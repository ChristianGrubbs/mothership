<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

/**
 * EffectCraft's .ecproj, which opens as it is: hosted EffectCraft writes its footage as `/work/<path>`, the web app as
 * `/<name>`. The workspace sends the stack files holding them with the project, and EffectCraft's hub agent adds them
 * and relinks the footage to them (the browser build never resolves a stored path itself).
 */
final class EffectCraftProject implements StackMediaReader
{
    public function extensions(): array
    {
        return ['ecproj'];
    }

    public function media(): string
    {
        return 'an EffectCraft project (its footage, images and audio)';
    }

    /**
     * The footage files the project names (`items.*.kind.path` of each `Footage` item: video, stills and audio), with `\`
     * read as `/` so a Windows path keeps its file name. Solids, placeholders and compositions are not files a stack holds.
     */
    public function paths(string $contents): array
    {
        $data = json_decode($contents, true);
        $items = is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];
        $paths = [];

        foreach ($items as $item) {
            $kind = is_array($item) && is_array($item['kind'] ?? null) ? $item['kind'] : [];
            $path = ($kind['type'] ?? null) === 'Footage' && is_string($kind['path'] ?? null) ? str_replace('\\', '/', $kind['path']) : '';

            if (basename($path) !== '') {
                $paths[$path] = true;
            }
        }

        return array_map('strval', array_keys($paths));
    }
}
