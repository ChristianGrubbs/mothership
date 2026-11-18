<?php

namespace Mothership\DAM\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;

/**
 * `exists:dam_directories,id` that also refuses a folder the viewer cannot even see, with the exact message
 * `exists` gives a missing id, so a request body cannot be used to probe whether a hidden folder exists.
 *
 * "Visible" is canView, not canAccess: the path-only ancestors of a granted folder already appear in the
 * viewer's tree, so a later 403 for a visible folder they cannot open tells them nothing new and stays.
 */
class VisibleDirectory implements ValidationRule
{
    /** The folder when it exists and the viewer can see it, else null — a hidden folder reads as missing. */
    public static function find(mixed $value): ?Directory
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false) {
            return null;
        }

        $directory = Directory::query()->find($id);

        return $directory && app(DirectoryPermissionService::class)->canView($directory->id) ? $directory : null;
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! self::find($value)) {
            $fail('validation.exists')->translate();
        }
    }
}
