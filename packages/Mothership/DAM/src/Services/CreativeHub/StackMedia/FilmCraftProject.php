<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

/**
 * FilmCraft's .fcproj, which opens as it is: hosted FilmCraft writes its clips as `/work/<name>`, the web app as
 * `/files/<name>`. The workspace sends the stack files holding them with the project, and FilmCraft's hub agent imports
 * them and relinks each clip to its file.
 */
final class FilmCraftProject implements StackMediaReader
{
    public function extensions(): array
    {
        return ['fcproj'];
    }

    public function media(): string
    {
        return 'a FilmCraft project (its clips)';
    }

    /**
     * The media files the project names (`project.items.*.kind.Media.media.File.path`), with `\` read as `/` so a Windows
     * path keeps its file name. Generated media and proxies are not files a stack holds.
     */
    public function paths(string $contents): array
    {
        $data = json_decode($contents, true);
        $items = is_array($data) && is_array($data['project']['items'] ?? null) ? $data['project']['items'] : [];
        $paths = [];

        foreach ($items as $item) {
            $path = is_array($item) ? ($item['kind']['Media']['media']['File']['path'] ?? null) : null;
            $path = is_string($path) ? str_replace('\\', '/', $path) : '';

            if (basename($path) !== '') {
                $paths[$path] = true;
            }
        }

        return array_map('strval', array_keys($paths));
    }
}
