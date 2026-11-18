<?php

namespace Mothership\DAM\Services;

use RuntimeException;

class StylePressPackageFiles
{
    public function read(string $manifestPath): array
    {
        if (! is_file($manifestPath) || is_link($manifestPath) || filesize($manifestPath) > 32000000) {
            throw new RuntimeException('The package manifest is missing or too large.');
        }
        $data = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported StylePress package schema.');
        }

        return $data;
    }

    public function resolve(string $root, string $relative, ?string $sha256 = null): string
    {
        $parts = explode('/', $relative);
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, ':') || str_contains($relative, "\0") || array_intersect($parts, ['', '.', '..'])) {
            throw new RuntimeException('Package paths must be relative ordinary files.');
        }
        $base = realpath($root);
        $current = $base;
        foreach ($parts as $part) {
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                throw new RuntimeException('Package symlinks are not supported.');
            }
        }
        $path = realpath($current);
        if (! $base || ! $path || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('A package attachment is missing.');
        }
        if ($sha256 !== null && (! preg_match('/\A[a-f0-9]{64}\z/i', $sha256) || ! hash_equals(strtolower($sha256), hash_file('sha256', $path)))) {
            throw new RuntimeException('A package attachment checksum does not match.');
        }

        return $path;
    }
}
