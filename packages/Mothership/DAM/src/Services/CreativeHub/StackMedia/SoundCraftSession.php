<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub\StackMedia;

/**
 * SoundCraft's .scraft, which names its audio by relative path (`sources[].path`, "Audio Files/x.wav") and opens as the
 * .scraftz bundle packed from its stack (SessionBundle).
 */
final class SoundCraftSession implements StackMediaReader
{
    public function extensions(): array
    {
        return ['scraft'];
    }

    public function media(): string
    {
        return 'a SoundCraft session (its audio)';
    }

    /**
     * The paths a bundle can hold under exactly that name, so the session finds them: relative, `/`-separated, with no
     * empty, `.` or `..` step and none of the characters the zip writer replaces (`\ : * ? " < > |`, ZipStream's Windows
     * filter, which would leave the medium offline under a changed name). Any other path is left out, as one the stack
     * does not hold.
     */
    public function paths(string $contents): array
    {
        $data = json_decode($contents, true);
        $sources = is_array($data) && is_array($data['sources'] ?? null) ? $data['sources'] : [];
        $paths = [];

        foreach ($sources as $source) {
            $path = is_array($source) && is_string($source['path'] ?? null) ? $source['path'] : '';
            $steps = explode('/', $path);

            if ($path === '' || strpbrk($path, "\\:*?\"<>|\0") !== false
                || array_intersect($steps, ['', '.', '..']) !== []) {
                continue;
            }

            $paths[$path] = true;
        }

        return array_map('strval', array_keys($paths));
    }
}
