<?php

namespace Mothership\DAM\Support;

/**
 * A directory name starting with `.` is refused everywhere a folder is created or renamed: a root
 * folder literally named `.objects` would collide with ContentStore::ROOT ('assets/.objects'), and
 * deleting it would run deleteDirectory('assets/.objects') and wipe every blob in the content store.
 */
final class ReservedDirectoryNames
{
    public static function isReserved(string $name): bool
    {
        return str_starts_with($name, '.');
    }
}
