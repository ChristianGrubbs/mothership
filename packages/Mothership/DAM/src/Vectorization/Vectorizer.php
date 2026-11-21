<?php

namespace Mothership\DAM\Vectorization;

use DOMDocument;
use DOMElement;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\Services\HomeTelemetry;
use RuntimeException;
use Throwable;

class Vectorizer
{
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    private const NUMBER_PATTERN = '[+-]?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+))(?:[eE][+-]?\d+)?';

    private const LENGTH_PATTERN = self::NUMBER_PATTERN.'(?:em|ex|px|in|cm|mm|pt|pc|%)?';

    private const PDF_SPACE_PATTERN = '(?:[\x00\t\n\f\r ]|%[^\r\n]*)';

    private const PDF_TOKEN_PATTERN = '[^\x00\t\n\f\r ()<>\[\]{}/%]+';

    private const MAX_XREF_BYTES = 32 * 1024 * 1024;

    /**
     * Determine whether vectorization is configured and ready for use.
     */
    public function ready(): bool
    {
        return (bool) config('vectorization.enabled')
            && Allowance::unlimitedIds() !== []
            && filled(config('vectorization.api_id'))
            && filled(config('vectorization.api_secret'));
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(config('vectorization.api_id'), config('vectorization.api_secret'))
            ->connectTimeout(15)
            ->timeout(210)
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * Convert an input image into a portable SVG and PDF pair.
     *
     * @return array{svg: string, pdf: string}
     */
    public function convert(string $path): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Vectorization provider unavailable.');
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Input image unavailable.');
        }

        try {
            $base = config('vectorization.base_url');
            HomeTelemetry::record(event: 'vectorization.provider_start', ctx: ['phase' => 'svg']);
            $first = $this->client()->attach('image', $stream, 'input')->post($base.'/vectorize', [
                'mode'                                   => 'production',
                'policy.retention_days'                  => '1',
                'input.max_pixels'                       => '3145828',
                'output.file_format'                     => 'svg',
                'output.svg.version'                     => 'svg_1_1',
                'output.svg.fixed_size'                  => 'false',
                'output.svg.adobe_compatibility_mode'    => 'true',
                'output.draw_style'                      => 'fill_shapes',
                'output.group_by'                        => 'none',
            ]);

            $token = $first->header('X-Image-Token');

            if (! $first->successful() || ! filled($token)) {
                throw new RuntimeException;
            }

            $svg = $this->cleanSvg(svg: $first->body());
            HomeTelemetry::record(event: 'vectorization.provider_start', ctx: ['phase' => 'pdf']);
            $second = $this->client()->asForm()->post($base.'/download', [
                'image.token'        => $token,
                'output.file_format' => 'pdf',
            ]);

            if (! $second->successful() || ! $this->isValidPdf($second->body())) {
                throw new RuntimeException;
            }

            HomeTelemetry::record(event: 'vectorization.provider_complete', ctx: ['status' => 'completed']);

            return ['svg' => $svg, 'pdf' => $second->body()];
        } catch (Throwable $error) {
            HomeTelemetry::record(
                event: 'vectorization.provider_failed',
                ctx: ['exception' => $error::class],
                level: 'error',
            );
            // Do not retain a previous exception: an HTTP exception may contain credentials/body.
            throw new RuntimeException('Vectorization provider unavailable.');
        } finally {
            fclose($stream);
        }
    }

    private function cleanSvg(string $svg): string
    {
        if ($svg === '' || strlen($svg) > 32 * 1024 * 1024) {
            throw new RuntimeException;
        }

        // The provider's SVG 1.1 prolog needs no external DTD after sanitization.
        $svg = preg_replace(
            '~\A((?:<\?xml\s[^?]*\?>)?\s*)<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1\.1//EN" "http://www\.w3\.org/Graphics/SVG/1\.1/DTD/svg11\.dtd">~',
            '$1',
            $svg,
            1,
        );

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            throw new RuntimeException;
        }

        $clean = (new Sanitizer)->sanitize($svg);

        if (! is_string($clean) || $clean === '') {
            throw new RuntimeException;
        }

        $xml = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $xml->loadXML($clean, LIBXML_NONET)
                || $xml->documentElement?->localName !== 'svg'
                || $xml->documentElement?->namespaceURI !== self::SVG_NAMESPACE) {
                throw new RuntimeException;
            }

            $root = $xml->documentElement;

            $this->assertSafeAttributes($root);

            if (! $this->isValidViewBox($root->getAttribute('viewBox'))) {
                throw new RuntimeException;
            }

            $shapes = 0;

            foreach ($root->getElementsByTagName('*') as $node) {
                $this->assertSafeAttributes($node);

                if (in_array($node->localName, ['image', 'script', 'foreignObject', 'use'], true)) {
                    throw new RuntimeException;
                }

                if (in_array($node->localName, ['path', 'rect', 'circle', 'ellipse', 'polygon', 'polyline', 'line'], true)) {
                    $this->assertValidShape($node);
                    $shapes++;
                }
            }

            if ($shapes === 0) {
                throw new RuntimeException;
            }

            return $clean;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function assertSafeAttributes(DOMElement $node): void
    {
        foreach ($node->attributes as $attribute) {
            if (in_array($attribute->localName, ['xmlns', 'xmlns:xlink'], true)) {
                continue;
            }

            if (preg_match('/(?:https?:|javascript:|data:)/i', $attribute->value)) {
                throw new RuntimeException;
            }

            if ($attribute->localName === 'href' && ! str_starts_with($attribute->value, '#')) {
                throw new RuntimeException;
            }

            if (preg_match_all('/url\s*\(([^)]*)\)/i', $attribute->value, $matches)) {
                foreach ($matches[1] as $reference) {
                    if (! str_starts_with(trim($reference, " \t\n\r\0\x0B\"'"), '#')) {
                        throw new RuntimeException;
                    }
                }
            }
        }
    }

    private function isValidViewBox(string $value): bool
    {
        $numbers = $this->parseNumberList($value);

        return count($numbers) === 4 && $numbers[2] > 0 && $numbers[3] > 0;
    }

    private function assertValidShape(DOMElement $node): void
    {
        switch ($node->localName) {
            case 'path':
                $this->assertValidPathData($node->getAttribute('d'));
                break;
            case 'rect':
                $this->assertPositiveAttribute($node, 'width');
                $this->assertPositiveAttribute($node, 'height');
                break;
            case 'circle':
                $this->assertPositiveAttribute($node, 'r');
                break;
            case 'ellipse':
                $this->assertPositiveAttribute($node, 'rx');
                $this->assertPositiveAttribute($node, 'ry');
                break;
            case 'line':
                foreach (['x1', 'y1', 'x2', 'y2'] as $attribute) {
                    $this->assertNumberAttribute($node, $attribute);
                }
                break;
            case 'polygon':
            case 'polyline':
                $points = $this->parseNumberList($node->getAttribute('points'), allowUnits: true);
                $minimum = $node->localName === 'polygon' ? 6 : 4;

                if (count($points) < $minimum || count($points) % 2 !== 0) {
                    throw new RuntimeException;
                }
                break;
        }
    }

    private function assertPositiveAttribute(DOMElement $node, string $attribute): void
    {
        if (! $this->isFiniteLength($node->getAttribute($attribute), positive: true)) {
            throw new RuntimeException;
        }
    }

    private function assertNumberAttribute(DOMElement $node, string $attribute): void
    {
        if (! $node->hasAttribute($attribute)) {
            return;
        }

        if (! $this->isFiniteLength($node->getAttribute($attribute))) {
            throw new RuntimeException;
        }
    }

    private function isFiniteLength(string $value, bool $positive = false): bool
    {
        $value = trim($value);

        if (preg_match('/\A'.self::LENGTH_PATTERN.'\z/', $value) !== 1
            || preg_match('/\A'.self::NUMBER_PATTERN.'/', $value, $matches) !== 1) {
            return false;
        }

        $number = (float) $matches[0];

        return is_finite($number) && (! $positive || $number > 0);
    }

    /**
     * @return list<float>
     */
    private function parseNumberList(string $value, bool $allowUnits = false): array
    {
        $numbers = [];
        $offset = 0;
        $length = strlen($value);
        $pattern = $allowUnits ? self::LENGTH_PATTERN : self::NUMBER_PATTERN;

        while ($offset < $length && ctype_space($value[$offset])) {
            $offset++;
        }

        while ($offset < $length) {
            if (preg_match('/\G'.$pattern.'/', $value, $matches, 0, $offset) !== 1) {
                throw new RuntimeException;
            }

            if (preg_match('/\A'.self::NUMBER_PATTERN.'/', $matches[0], $numberMatch) !== 1) {
                throw new RuntimeException;
            }

            $number = (float) $numberMatch[0];

            if (! is_finite($number)) {
                throw new RuntimeException;
            }

            $numbers[] = $number;
            $offset += strlen($matches[0]);

            $separatorStart = $offset;

            while ($offset < $length && ctype_space($value[$offset])) {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            if ($value[$offset] === ',') {
                $offset++;

                while ($offset < $length && ctype_space($value[$offset])) {
                    $offset++;
                }

                if ($offset >= $length || $value[$offset] === ',') {
                    throw new RuntimeException;
                }

                continue;
            }

            if ($offset === $separatorStart) {
                throw new RuntimeException;
            }
        }

        return $numbers;
    }

    private function assertValidPathData(string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        $hasMove = false;
        $hasDrawing = false;

        while ($offset < $length && ctype_space($data[$offset])) {
            $offset++;
        }

        if ($offset >= $length || preg_match('/\G[Mm]/', $data, $unused, 0, $offset) !== 1) {
            throw new RuntimeException;
        }

        while (true) {
            $this->skipPathSeparators($data, $offset);

            if ($offset >= $length) {
                break;
            }

            if (preg_match('/\G[AaCcHhLlMmQqSsTtVvZz]/', $data, $matches, 0, $offset) !== 1) {
                throw new RuntimeException;
            }

            $command = $matches[0];
            $offset += 1;

            if (strtolower($command) === 'z') {
                if (! $hasMove) {
                    throw new RuntimeException;
                }

                continue;
            }

            $arity = match (strtolower($command)) {
                'm', 'l', 't' => 2,
                'h', 'v'      => 1,
                'c'           => 6,
                's', 'q'      => 4,
                'a'           => 7,
                default       => throw new RuntimeException,
            };
            $groups = 0;

            while (true) {
                $this->skipPathSeparators($data, $offset);

                if ($offset >= $length || preg_match('/\G[AaCcHhLlMmQqSsTtVvZz]/', $data, $unused, 0, $offset) === 1) {
                    break;
                }

                $values = [];

                for ($index = 0; $index < $arity; $index++) {
                    $this->skipPathSeparators($data, $offset);

                    if (preg_match('/\G'.self::NUMBER_PATTERN.'/', $data, $number, 0, $offset) !== 1) {
                        throw new RuntimeException;
                    }

                    $values[] = (float) $number[0];
                    $offset += strlen($number[0]);
                }

                foreach ($values as $value) {
                    if (! is_finite($value)) {
                        throw new RuntimeException;
                    }
                }

                if (strtolower($command) === 'a' && (! in_array($values[3], [0.0, 1.0], true)
                    || ! in_array($values[4], [0.0, 1.0], true))) {
                    throw new RuntimeException;
                }

                $groups++;

                if (strtolower($command) === 'm') {
                    $hasMove = true;

                    if ($groups > 1) {
                        $hasDrawing = true;
                    }
                } else {
                    $hasDrawing = true;
                }
            }

            if ($groups === 0) {
                throw new RuntimeException;
            }
        }

        if (! $hasDrawing) {
            throw new RuntimeException;
        }
    }

    private function skipPathSeparators(string $data, int &$offset): void
    {
        $length = strlen($data);

        while ($offset < $length && ($data[$offset] === ',' || ctype_space($data[$offset]))) {
            $offset++;
        }
    }

    private function isValidPdf(string $pdf): bool
    {
        if (preg_match('/\A%PDF-\d\.\d(?:\r\n|\r|\n)/', $pdf) !== 1) {
            return false;
        }

        if (preg_match('/\b\d+\s+\d+\s+obj\b[\s\S]+?\bendobj\b/', $pdf) !== 1
            || preg_match('/\bstartxref[ \t]*(?:\r\n|\r|\n)(\d+)[ \t]*(?:\r\n|\r|\n)%%EOF[ \t]*(?:\r\n|\r|\n)?\z/', $pdf, $matches) !== 1) {
            return false;
        }

        $xrefOffset = (int) $matches[1];

        if ($xrefOffset >= strlen($pdf)) {
            return false;
        }

        if (substr($pdf, $xrefOffset, 4) === 'xref') {
            return $this->isValidClassicXref($pdf, $xrefOffset);
        }

        return $this->isValidXrefStream($pdf, $xrefOffset);
    }

    private function isValidClassicXref(string $pdf, int $offset): bool
    {
        $length = strlen($pdf);
        $cursor = $offset + 4;

        if (! preg_match('/\G(?:\r\n|\r|\n)/', $pdf, $matches, 0, $cursor)) {
            return false;
        }

        $cursor += strlen($matches[0]);
        $subsections = 0;

        while (preg_match('/\G(\d+)\s+(\d+)(?:\r\n|\r|\n)/', $pdf, $matches, 0, $cursor) === 1) {
            $firstObject = (int) $matches[1];
            $entryCount = (int) $matches[2];

            if ($entryCount < 1 || $entryCount > 1000000) {
                return false;
            }

            $cursor += strlen($matches[0]);

            for ($index = 0; $index < $entryCount; $index++) {
                if (preg_match('/\G(\d{10}) (\d{5}) ([nf])(?:\r\n| (?:\r(?!\n)|\n))/', $pdf, $entry, 0, $cursor) !== 1) {
                    return false;
                }

                $cursor += strlen($entry[0]);

                if ($entry[3] === 'n') {
                    $objectNumber = $firstObject + $index;
                    $objectOffset = (int) $entry[1];
                    $generation = (int) $entry[2];

                    if ($objectOffset >= $length
                        || preg_match('/\A'.$objectNumber.'\s+'.$generation.'\s+obj\b/', substr($pdf, $objectOffset)) !== 1) {
                        return false;
                    }
                }
            }

            $subsections++;
        }

        $this->skipPdfWhitespace(pdf: $pdf, cursor: $cursor);

        if ($subsections === 0 || preg_match('/\Gtrailer\s*<<[\s\S]*?>>/', $pdf, $trailer, 0, $cursor) !== 1) {
            return false;
        }

        return preg_match('/\/Size\s+\d+\b/', $trailer[0]) === 1;
    }

    private function isValidXrefStream(string $pdf, int $offset): bool
    {
        if (preg_match('/\G\d+\s+\d+\s+obj\b/', $pdf, $matches, 0, $offset) !== 1) {
            return false;
        }

        $cursor = $offset + strlen($matches[0]);
        $object = $this->readPdfValue(pdf: $pdf, cursor: $cursor);

        if ($object['type'] !== 'dictionary') {
            return false;
        }

        $dictionary = $object['value'];

        if (($dictionary['Type']['type'] ?? null) !== 'name'
            || $dictionary['Type']['value'] !== 'XRef'
            || ($dictionary['Size']['type'] ?? null) !== 'integer'
            || ($dictionary['Length']['type'] ?? null) !== 'integer') {
            return false;
        }

        $size = $dictionary['Size']['value'];
        $encodedLength = $dictionary['Length']['value'];
        $widths = $this->pdfIntegerList(object: $dictionary['W'] ?? null);
        $ranges = isset($dictionary['Index'])
            ? $this->pdfIntegerList(object: $dictionary['Index'])
            : [0, $size];

        if ($size < 1 || $size > 1000000 || $encodedLength < 1 || $encodedLength > self::MAX_XREF_BYTES
            || $widths === null || count($widths) !== 3 || array_sum($widths) < 1
            || $ranges === null || count($ranges) === 0 || count($ranges) % 2 !== 0) {
            return false;
        }

        $entryCount = 0;
        $lastEnd = 0;

        for ($index = 0; $index < count($ranges); $index += 2) {
            [$first, $count] = [$ranges[$index], $ranges[$index + 1]];

            if ($first < $lastEnd || $count < 1 || $first >= $size || $count > $size - $first) {
                return false;
            }

            $lastEnd = $first + $count;
            $entryCount += $count;
        }

        $decodedLength = array_sum($widths) * $entryCount;

        if ($decodedLength > self::MAX_XREF_BYTES) {
            return false;
        }

        $this->skipPdfWhitespace(pdf: $pdf, cursor: $cursor);

        if (preg_match('/\Gstream(?:\r\n|\n)/', $pdf, $matches, 0, $cursor) !== 1) {
            return false;
        }

        $cursor += strlen($matches[0]);

        if ($encodedLength > strlen($pdf) - $cursor) {
            return false;
        }

        $encoded = substr($pdf, $cursor, $encodedLength);
        $cursor += $encodedLength;

        if (preg_match('~\G(?:\r\n|\r|\n)?endstream(?=[\x00\t\n\f\r %])~', $pdf, $matches, 0, $cursor) !== 1) {
            return false;
        }

        $cursor += strlen($matches[0]);
        $this->skipPdfWhitespace(pdf: $pdf, cursor: $cursor);

        if (preg_match('~\Gendobj(?=[\x00\t\n\f\r %])~', $pdf, $matches, 0, $cursor) !== 1
            || ($dictionary['F']['type'] ?? 'null') !== 'null') {
            return false;
        }

        $filter = $dictionary['Filter'] ?? ['type' => 'null'];
        $parameters = $dictionary['DecodeParms'] ?? ['type' => 'null'];

        if ($filter['type'] === 'null') {
            return $parameters['type'] === 'null' && $encodedLength === $decodedLength;
        }

        if ($filter['type'] === 'array') {
            if (count($filter['value']) !== 1) {
                return false;
            }

            $filter = $filter['value'][0];

            if ($parameters['type'] === 'array' && count($parameters['value']) === 1) {
                $parameters = $parameters['value'][0];
            }
        }

        if ($filter['type'] !== 'name' || $filter['value'] !== 'FlateDecode') {
            return false;
        }

        if ($parameters['type'] !== 'null') {
            if ($parameters['type'] !== 'dictionary') {
                return false;
            }

            $predictor = $parameters['value']['Predictor'] ?? ['type' => 'integer', 'value' => 1];

            // Predictor transforms and other filters are not supported by this narrow validator.
            if ($predictor['type'] !== 'integer' || $predictor['value'] !== 1) {
                return false;
            }
        }

        return $this->isValidFlateXref(encoded: $encoded, expectedLength: $decodedLength);
    }

    private function isValidFlateXref(string $encoded, int $expectedLength): bool
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);

        if ($context === false) {
            return false;
        }

        $length = strlen($encoded);
        $decodedLength = 0;

        // Small input chunks bound each allocation even for highly compressed provider data.
        for ($cursor = 0; $cursor < $length; $cursor += 1024) {
            $decoded = @inflate_add($context, substr($encoded, $cursor, 1024), ZLIB_SYNC_FLUSH);

            if ($decoded === false) {
                return false;
            }

            $decodedLength += strlen($decoded);

            if ($decodedLength > $expectedLength) {
                return false;
            }

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                return $decodedLength === $expectedLength && inflate_get_read_len($context) === $length;
            }
        }

        return false;
    }

    /**
     * @return list<int>|null
     */
    private function pdfIntegerList(?array $object): ?array
    {
        if (($object['type'] ?? null) !== 'array') {
            return null;
        }

        $values = [];

        foreach ($object['value'] as $item) {
            if ($item['type'] !== 'integer' || $item['value'] < 0 || $item['value'] > 1000000) {
                return null;
            }

            $values[] = $item['value'];
        }

        return $values;
    }

    private function skipPdfWhitespace(string $pdf, int &$cursor): void
    {
        if (preg_match('~\G'.self::PDF_SPACE_PATTERN.'+~', $pdf, $matches, 0, $cursor) === 1) {
            $cursor += strlen($matches[0]);
        }
    }

    /**
     * Read one PDF object without interpreting text in strings, comments or nested dictionaries as keys.
     *
     * @return array{type: string, value: mixed}
     */
    private function readPdfValue(string $pdf, int &$cursor, int $depth = 0): array
    {
        if ($depth > 16) {
            throw new RuntimeException;
        }

        $this->skipPdfWhitespace(pdf: $pdf, cursor: $cursor);
        $start = $pdf[$cursor] ?? '';

        if (substr($pdf, $cursor, 2) === '<<' || $start === '[') {
            $dictionary = $start === '<';
            $cursor += $dictionary ? 2 : 1;
            $closing = $dictionary ? '>>' : ']';
            $values = [];

            while (true) {
                $this->skipPdfWhitespace(pdf: $pdf, cursor: $cursor);

                if (substr($pdf, $cursor, strlen($closing)) === $closing) {
                    $cursor += strlen($closing);

                    return ['type' => $dictionary ? 'dictionary' : 'array', 'value' => $values];
                }

                if ($dictionary) {
                    if (($pdf[$cursor] ?? '') !== '/') {
                        throw new RuntimeException;
                    }

                    $key = $this->readPdfValue(pdf: $pdf, cursor: $cursor, depth: $depth + 1)['value'];

                    if (array_key_exists($key, $values)) {
                        throw new RuntimeException;
                    }

                    $values[$key] = $this->readPdfValue(pdf: $pdf, cursor: $cursor, depth: $depth + 1);
                } else {
                    $values[] = $this->readPdfValue(pdf: $pdf, cursor: $cursor, depth: $depth + 1);
                }
            }
        }

        if ($start === '(') {
            $nesting = 1;
            $cursor++;

            while ($cursor < strlen($pdf) && $nesting > 0) {
                $character = $pdf[$cursor++];

                if ($character === '\\') {
                    $cursor++;
                } elseif ($character === '(') {
                    $nesting++;
                } elseif ($character === ')') {
                    $nesting--;
                }
            }

            if ($nesting !== 0) {
                throw new RuntimeException;
            }

            return ['type' => 'string', 'value' => null];
        }

        if ($start === '<') {
            if (preg_match('~\G<[0-9a-fA-F\x00\t\n\f\r ]*>~', $pdf, $matches, 0, $cursor) !== 1) {
                throw new RuntimeException;
            }

            $cursor += strlen($matches[0]);

            return ['type' => 'string', 'value' => null];
        }

        if ($start === '/') {
            $cursor++;
            $name = '';

            if (preg_match('~\G'.self::PDF_TOKEN_PATTERN.'~', $pdf, $matches, 0, $cursor) === 1) {
                $name = $matches[0];
                $cursor += strlen($name);
            }

            if (preg_match('/#(?![0-9a-fA-F]{2})/', $name) === 1) {
                throw new RuntimeException;
            }

            $name = preg_replace_callback('/#([0-9a-fA-F]{2})/', fn (array $match) => chr(hexdec($match[1])), $name);

            if (str_contains($name, "\0")) {
                throw new RuntimeException;
            }

            return ['type' => 'name', 'value' => $name];
        }

        if (preg_match('~\G'.self::PDF_TOKEN_PATTERN.'~', $pdf, $matches, 0, $cursor) !== 1) {
            throw new RuntimeException;
        }

        $value = $matches[0];
        $cursor += strlen($value);

        if (preg_match('/\A[+-]?\d+\z/', $value) === 1) {
            if ((int) $value >= 0 && preg_match('~\G'.self::PDF_SPACE_PATTERN.'+\d+'.self::PDF_SPACE_PATTERN.'+R(?=[\x00\t\n\f\r ()<>\[\]{}/%]|\z)~', $pdf, $matches, 0, $cursor) === 1) {
                $cursor += strlen($matches[0]);

                return ['type' => 'reference', 'value' => null];
            }

            return ['type' => 'integer', 'value' => (int) $value];
        }

        if (preg_match('/\A[+-]?(?:\d+\.\d*|\.\d+)\z/', $value) === 1) {
            return ['type' => 'real', 'value' => $value];
        }

        if (in_array($value, ['true', 'false', 'null'], true)) {
            return ['type' => $value === 'null' ? 'null' : 'boolean', 'value' => $value];
        }

        throw new RuntimeException;
    }
}
