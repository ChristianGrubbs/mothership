<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Directory;

class GenerationDirectory
{
    /**
     * @param  int|null  $ownerId  the admin who asked for the generation these folders hold, because a
     *                             queue worker has no session to fill `created_by` from (ADR 0010). Null
     *                             leaves a folder unowned, and full admins manage it.
     */
    public function ensure(array $segments, ?int $ownerId = null): Directory
    {
        // The tree lock before the root row, not after it (ADR 0004's order, and ADR 0010's fence, which
        // run() takes as this transaction's first statement). Locking the root first left a cycle with a
        // rebuild or a switch: the transition holds the state row and waits for the tree, while an import
        // holds the root and waits for the state row (Codex review of PR #74, finding 2).
        return Directory::withTreeLock(function () use ($segments, $ownerId): Directory {
            $directory = Directory::query()->whereNull('parent_id')->orderBy('id')->lockForUpdate()->first()
                ?? Directory::create(['name'=>'Assets', 'created_by'=>$ownerId]);
            foreach ($segments as $segment) {
                if (! is_string($segment) || trim($segment) === '' || mb_strlen($segment) > 255 || str_contains($segment, "\0")) {
                    throw new \RuntimeException('Invalid generation directory name.');
                }
                $directory = Directory::firstOrCreate(['parent_id'=>$directory->id, 'name'=>$segment], ['created_by'=>$ownerId]);
            }

            return $directory;
        });
    }

    public function forManifest(array $manifest, ?int $ownerId = null): Directory
    {
        $kind = ($manifest['kind'] ?? 'image') === 'video' ? 'Video' : 'Images';
        $style = $manifest['style'] ?? null;
        $concept = $manifest['concept'] ?? null;
        $segments = ['AI-Generations', $kind];
        if ($kind === 'Video' && ! empty($concept['title']) && ! empty($concept['category'])) {
            array_push($segments, 'Concepts', $concept['category'], $concept['title']);
        } elseif (! empty($style['title']) && ! empty($style['category'])) {
            array_push($segments, 'Pre-Built-Styles', $style['category'], $style['title']);
        } else {
            array_push($segments, 'Misc.', $manifest['provider'] ?? 'Unknown');
        }

        return $this->ensure(segments: $segments, ownerId: $ownerId);
    }
}
