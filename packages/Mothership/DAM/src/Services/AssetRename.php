<?php

namespace Mothership\DAM\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Support\LibraryPaths;

/**
 * Renames an asset: the one rule the Library's Rename dialog and the REST update share. The file keeps its own
 * extension (only the base name of what is sent counts), and the Library path follows the name in the same write,
 * so the record keeps matching its bytes. A title, when given, is set with the matching `title` property.
 */
class AssetRename
{
    /** The rule a submitted file name must meet: a base name, never starting with a dot. */
    public const FILE_NAME_RULE = 'required|string|max:255|regex:/^(?!\.)[\w .-]+$/';

    /**
     * @return array{file_changed: bool, title_changed: bool}
     *
     * @throws AssetRenameException name_taken when another asset already has the new Library path, name_conflict when
     *                              one took it concurrently
     */
    public function rename(Asset $asset, string $fileName, ?string $title = null): array
    {
        try {
            return DB::transaction(function () use ($asset, $fileName, $title): array {
                // The caller's model can be stale: a re-upload may have replaced the bytes (and the extension) since
                // it was loaded. Take the row lock the upload publisher takes, then derive everything from the row
                // as it is now, so the name never pairs one file's extension with another file's content.
                $fresh = Asset::query()->whereKey($asset->id)->lockedForGraph()->firstOrFail();

                $result = $this->renameLocked($fresh, $fileName, $title);

                $asset->setRawAttributes($fresh->getAttributes(), sync: true);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            throw new AssetRenameException(AssetRenameException::NAME_CONFLICT);
        }
    }

    /** @return array{file_changed: bool, title_changed: bool} */
    private function renameLocked(Asset $asset, string $fileName, ?string $title): array
    {
        // The current file's extension: a re-upload writes file_name and extension together under the same lock.
        $extension = pathinfo((string) $asset->file_name, PATHINFO_EXTENSION);

        $baseName = pathinfo(trim($fileName), PATHINFO_FILENAME);
        $name = $extension === '' ? $baseName : $baseName.'.'.$extension;
        $title = $title === null ? null : trim($title);
        $newPath = dirname((string) $asset->path).'/'.$name;
        $fileChanged = $name !== $asset->file_name;
        $titleChanged = $title !== null && $title !== trim((string) $asset->display_name);

        if (! $fileChanged && ! $titleChanged) {
            return ['file_changed' => false, 'title_changed' => false];
        }

        // No physical file to move (ADR 0009 slice 3): the Library path is a name change only. A name that already
        // names another asset is refused up front; dam_assets.path's UNIQUE index is the actual guard, caught above for
        // a concurrent rename.
        if ($fileChanged && LibraryPaths::taken($newPath, ignoreAssetId: $asset->id)) {
            throw new AssetRenameException(AssetRenameException::NAME_TAKEN, Asset::where('path', $newPath)->first()?->file_name);
        }

        $asset->update(array_filter([
            'file_name' => $fileChanged ? $name : null,
            'path'      => $fileChanged ? $newPath : null,
            'title'     => $title,
        ], fn ($value) => $value !== null));

        if ($title !== null) {
            $asset->properties()
                ->whereRaw('LOWER(name) = ?', ['title'])
                ->update(['value' => $title]);
        }

        return ['file_changed' => $fileChanged, 'title_changed' => $titleChanged];
    }
}
