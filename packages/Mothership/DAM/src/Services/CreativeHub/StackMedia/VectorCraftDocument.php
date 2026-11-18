<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

/**
 * A VectorCraft document whose images link files outside it (`file.place` links rasters by default). The document keeps
 * only a small preview of each (`images.<key>.proxy`), and VectorCraft web reads no linked files, so the hub opens a copy
 * that holds each linked file's bytes instead of its preview: what VectorCraft's own Include Linked Files save writes,
 * which shows the image in full wherever the linked file can't be found. The files come from the document's stack,
 * matched by file name (StackMedia::match); an image the stack does not hold keeps its preview.
 *
 * Format v4: UTF-8 JSON, optionally gzipped, then MARKER and the blob bytes; `images.<key>.blob = [offset, length]` with
 * offsets counted from just after the marker, so appending keeps every existing blob where it is.
 */
final class VectorCraftDocument implements StackMediaPacker
{
    public const MARKER = "\n\0VCBLOBS\0";

    /** The most a gzipped document inflates to; the document and its blobs are held in memory while it is read. */
    public const MAX_DOCUMENT_BYTES = 4 * StackMedia::MAX_BYTES;

    public function extensions(): array
    {
        return ['vectorcraft'];
    }

    public function media(): string
    {
        return 'a VectorCraft document (its linked images)';
    }

    /** The file name of each linked image still holding only its preview, each once; an image holding its bytes is left out. */
    public function paths(string $contents): array
    {
        $parsed = self::parse($contents);

        return $parsed === null ? [] : array_values(array_unique(self::previewed($parsed['doc'])));
    }

    /**
     * Each previewed image whose file `$sizes` names takes that file's bytes, appended after the blobs the document holds
     * (one copy for images linking the same file); the rest keep their previews.
     */
    public function pack(string $contents, array $sizes): ?array
    {
        $parsed = self::parse($contents);

        if ($parsed === null) {
            return null;
        }

        $doc = $parsed['doc'];
        $end = strlen($parsed['tail']);
        $offsets = [];

        foreach (self::previewed($doc) as $key => $name) {
            if (! isset($sizes[$name])) {
                continue;
            }

            if (! isset($offsets[$name])) {
                $offsets[$name] = $end;
                $end += $sizes[$name];
            }

            $entry = $doc->images->{$key};
            unset($entry->data, $entry->proxy);
            $entry->mime = self::mime($name) ?? ($entry->mime ?? 'application/octet-stream');
            $entry->blob = [$offsets[$name], $sizes[$name]];
        }

        if ($offsets === []) {
            return null;
        }

        $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? null : ['head' => $json.self::MARKER.$parsed['tail'], 'append' => array_map('strval', array_keys($offsets))];
    }

    /**
     * The document's JSON, decoded to objects so empty objects stay objects when written back, and its blob bytes.
     *
     * @return array{doc: object, tail: string}|null
     */
    private static function parse(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $bytes = @gzdecode($bytes, self::MAX_DOCUMENT_BYTES);

            if ($bytes === false) {
                return null;
            }
        }

        $at = strpos($bytes, self::MARKER);
        [$head, $tail] = $at === false ? [$bytes, ''] : [substr($bytes, 0, $at), substr($bytes, $at + strlen(self::MARKER))];
        $doc = json_decode($head, false, 512, JSON_BIGINT_AS_STRING);

        return is_object($doc) && isset($doc->document) ? ['doc' => $doc, 'tail' => $tail] : null;
    }

    /**
     * The linked file name of each image entry still holding only a preview, by image key.
     *
     * @return array<string, string>
     */
    private static function previewed(object $doc): array
    {
        $images = is_object($doc->images ?? null) ? $doc->images : new \stdClass;
        $links = self::links($doc->document);
        $names = [];

        foreach (get_object_vars($images) as $key => $entry) {
            if (is_object($entry) && ($entry->proxy ?? false) === true && isset($links[(string) $key])) {
                $names[(string) $key] = $links[(string) $key];
            }
        }

        return $names;
    }

    /**
     * The linked file name of each image node, by image key: the link's relative path, else its path, reduced to the name.
     *
     * @return array<string, string>
     */
    private static function links(mixed $node): array
    {
        $links = [];
        $stack = [$node];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (! is_object($current) && ! is_array($current)) {
                continue;
            }

            if (is_object($current) && ($current->type ?? null) === 'image' && is_object($current->link ?? null)
                && is_string($current->key ?? null) && $current->key !== '') {
                $path = $current->link->relative ?? $current->link->path ?? '';
                $name = is_string($path) ? basename(str_replace('\\', '/', $path)) : '';

                if ($name !== '') {
                    $links[$current->key] = $name;
                }
            }

            foreach (is_object($current) ? get_object_vars($current) : $current as $child) {
                $stack[] = $child;
            }
        }

        return $links;
    }

    private static function mime(string $name): ?string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'bmp'         => 'image/bmp',
            default       => null,
        };
    }
}
