<?php

namespace Mothership\DAM\Support;

/**
 * Names inside a downloaded ZIP are opened on other people's machines. Each path segment is made safe for Windows and
 * for file managers without changing what it says: bidi controls go (BidiControls), leading dots go (the entry would be
 * hidden), trailing spaces and dots go (Windows drops them silently), and a reserved device name (CON, PRN, AUX, NUL,
 * COM1-9, LPT1-9, with any extension, any case) gets an underscore after its base name: CON.jpg becomes CON_.jpg.
 */
final class ZipEntryNames
{
    private const RESERVED = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i';

    public static function segment(string $name): string
    {
        $name = ltrim(rtrim(BidiControls::strip($name), " \t."), " \t.");

        if ($name === '') {
            return 'file';
        }

        $dot = strpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);

        if (preg_match(self::RESERVED, rtrim($base)) === 1) {
            return rtrim($base).'_'.($dot === false ? '' : substr($name, $dot));
        }

        return $name;
    }

    /** A whole entry path: every folder and the file name cleaned, empty segments dropped. */
    public static function path(string $path): string
    {
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), fn (string $part) => $part !== ''));

        return implode('/', array_map(fn (string $part) => self::segment($part), $segments ?: ['file']));
    }

    /**
     * `$path` cleaned and made unique inside one ZIP. `$taken` remembers every file ("f:") and every folder ("d:") written so
     * far, compared without case. A file that collides with an earlier file or folder is numbered "name (2).ext". Folders
     * that clean to the same name merge into the first one seen (the same casing is reused). A folder whose cleaned name
     * is already a file is numbered "name (2)" and every later entry in it follows that mapping, so an ordinary name is
     * never both a file and a folder (".cache" next to "cache/photo.jpg" would otherwise be).
     */
    public static function unique(string $path, array &$taken): string
    {
        $segments = explode('/', self::path($path));
        $file = array_pop($segments);
        $parent = '';

        foreach ($segments as $segment) {
            $parent = self::directory($parent, $segment, $taken);
        }

        return self::allocateFile($parent, $file, $taken);
    }

    /**
     * A name that sits next to an entry `unique()` already returned (such as its ".MISSING.txt" marker). `$resolvedPath` is
     * an output path, so its folders are kept as they are and only the last segment is numbered when it is taken.
     */
    public static function uniqueBeside(string $resolvedPath, array &$taken): string
    {
        $slash = strrpos($resolvedPath, '/');

        return $slash === false
            ? self::allocateFile('', $resolvedPath, $taken)
            : self::allocateFile(substr($resolvedPath, 0, $slash), substr($resolvedPath, $slash + 1), $taken);
    }

    private static function allocateFile(string $parent, string $file, array &$taken): string
    {
        $dot = strpos($file, '.', 1);
        $stem = $dot === false ? $file : substr($file, 0, $dot);
        $extension = $dot === false ? '' : substr($file, $dot);
        $candidate = $file;

        for ($counter = 2; self::isTaken($parent, $candidate, $taken); $counter++) {
            $candidate = "{$stem} ({$counter}){$extension}";
        }

        $taken['f:'.mb_strtolower(self::join($parent, $candidate))] = true;

        return self::join($parent, $candidate);
    }

    /**
     * The output folder for source folder `$segment` under output folder `$parent`. `m:` remembers which output a source
     * folder was given (so every later entry in it follows), while `d:` and `f:` are the output paths already occupied.
     * A new source folder takes its own name when nothing occupies it, otherwise the first free "name (n)", checked
     * against files and folders alike.
     */
    private static function directory(string $parent, string $segment, array &$taken): string
    {
        $mapKey = 'm:'.mb_strtolower(self::join($parent, $segment));

        if (isset($taken[$mapKey])) {
            return $taken[$mapKey];
        }

        $name = $segment;

        for ($counter = 2; self::isTaken($parent, $name, $taken); $counter++) {
            $name = "{$segment} ({$counter})";
        }

        $full = self::join($parent, $name);
        $taken[$mapKey] = $full;
        $taken['d:'.mb_strtolower($full)] = true;

        return $full;
    }

    private static function isTaken(string $parent, string $name, array $taken): bool
    {
        $key = mb_strtolower(self::join($parent, $name));

        return isset($taken['f:'.$key]) || isset($taken['d:'.$key]);
    }

    private static function join(string $parent, string $name): string
    {
        return $parent === '' ? $name : $parent.'/'.$name;
    }
}
