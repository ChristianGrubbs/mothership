<?php

namespace Mothership\DAM\Services;

use InvalidArgumentException;

/** Changes only scene roots. Binary geometry, images, codecs and animation indices survive verbatim. */
class GlbPrimaryView
{
    /** The browser sends an adjustment relative to the currently displayed corrected model. */
    public function compose(array $delta, ?array $previous): array
    {
        $q = array_map('floatval', array_values($delta['rotation']));
        $norm = sqrt(array_sum(array_map(fn ($n) => $n * $n, $q)));
        $q = array_map(fn ($n) => $n / $norm, $q);
        $translation = array_map('floatval', array_values($delta['translation']));
        if (! $previous) {
            return ['rotation'=>$q, 'translation'=>$translation];
        }
        $rotation = $this->multiply(left: $q, right: $previous['rotation']);
        $point = [...$previous['translation'], 0];
        $rotated = $this->multiply(left: $this->multiply(left: $q, right: $point), right: [-$q[0], -$q[1], -$q[2], $q[3]]);

        return ['rotation'=>$rotation, 'translation'=>[$rotated[0] + $translation[0], $rotated[1] + $translation[1], $rotated[2] + $translation[2]]];
    }

    private function multiply(array $left, array $right): array
    {
        [$x, $y, $z, $w] = $left;
        [$a, $b, $c, $d] = $right;

        return [$w * $a + $x * $d + $y * $c - $z * $b, $w * $b - $x * $c + $y * $d + $z * $a,
            $w * $c + $x * $b - $y * $a + $z * $d, $w * $d - $x * $a - $y * $b - $z * $c];
    }

    public function transform($input, $output, array $rotation, array $translation): void
    {
        $header = $this->read($input, 20);
        $h = unpack('Vversion/Vlength/VjsonLength/VjsonType', substr($header, 4));
        if (substr($header, 0, 4) !== 'glTF' || $h['version'] !== 2 || $h['jsonType'] !== 0x4E4F534A
            || $h['jsonLength'] > 16 * 1024 * 1024
            || $h['jsonLength'] % 4 !== 0 || $h['length'] < 20 + $h['jsonLength']) {
            throw new InvalidArgumentException('Invalid or oversized GLB header.');
        }
        try {
            // Objects stay objects, including empty extension dictionaries.
            $doc = json_decode($this->read($input, $h['jsonLength']), false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid GLB JSON.', previous: $e);
        }
        if (! is_object($doc) || ($doc->asset->version ?? null) !== '2.0' || ! is_array($doc->scenes ?? null)
            || ! is_array($doc->nodes ?? null) || ! count($doc->scenes)) {
            throw new InvalidArgumentException('GLB has no usable scene.');
        }
        $nodeCount = count($doc->nodes);
        foreach ($doc->scenes as $scene) {
            if (! is_object($scene) || ! is_array($scene->nodes ?? null)) {
                throw new InvalidArgumentException('Invalid GLB scene roots.');
            }
            foreach ($scene->nodes as $node) {
                if (! is_int($node) || $node < 0 || $node >= $nodeCount) {
                    throw new InvalidArgumentException('Invalid GLB root node.');
                }
            }
            $wrapper = (object) ['name'=>'Mothership primary orientation', 'rotation'=>$rotation, 'translation'=>$translation, 'children'=>$scene->nodes];
            $scene->nodes = [count($doc->nodes)];
            $doc->nodes[] = $wrapper;
        }
        $json = json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $json = str_pad($json, (int) ceil(strlen($json) / 4) * 4, ' ');
        $length = $h['length'] - $h['jsonLength'] + strlen($json);
        if ($length > 0xFFFFFFFF) {
            throw new InvalidArgumentException('Corrected GLB exceeds the format length limit.');
        }
        $this->write($output, 'glTF'.pack('VVVV', 2, $length, strlen($json), 0x4E4F534A).$json);
        $remaining = $h['length'] - 20 - $h['jsonLength'];
        while ($remaining > 0) {
            if ($remaining < 8) {
                throw new InvalidArgumentException('Truncated GLB chunk.');
            }
            $chunk = $this->read($input, 8);
            $length = unpack('V', $chunk)[1];
            if ($length % 4 !== 0 || $length > $remaining - 8) {
                throw new InvalidArgumentException('Invalid GLB chunk length.');
            }
            $this->write($output, $chunk);
            if (stream_copy_to_stream($input, $output, $length) !== $length) {
                throw new InvalidArgumentException('Truncated GLB binary data.');
            }
            $remaining -= 8 + $length;
        }
        if (fread($input, 1) !== '') {
            throw new InvalidArgumentException('Unexpected trailing GLB data.');
        }
        rewind($output);
    }

    private function read($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($stream, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new InvalidArgumentException('Truncated GLB.');
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    private function write($stream, string $bytes): void
    {
        if (fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Could not write corrected GLB.');
        }
    }
}
