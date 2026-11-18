<?php

/*
 * Fixtures built byte by byte for the game-asset previews and TarListing: gzip-compressed tars (a Unity package
 * is one), Blender .blend files in each header format, and small PNGs. No real Blender or Unity file is used.
 */

if (! function_exists('tarListingHeader')) {
    /** One 512-byte header block with a valid checksum. */
    function tarListingHeader(string $name, int $size, string $type = '0', string $magic = "ustar\0", string $version = '00', string $prefix = '', ?string $sizeField = null): string
    {
        $header = str_pad($name, 100, "\0")
            ."0000644\0"."0000000\0"."0000000\0"
            .($sizeField ?? str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0")
            .str_pad(decoct(1_790_000_000), 11, '0', STR_PAD_LEFT)."\0"
            .str_repeat(' ', 8)
            .$type
            .str_repeat("\0", 100)
            .$magic.$version
            .str_repeat("\0", 32 + 32 + 8 + 8)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        return substr_replace($header, str_pad(decoct(array_sum(unpack('C*', $header))), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }
}

if (! function_exists('tarListingData')) {
    /** An entry's data, padded to whole blocks. */
    function tarListingData(string $data): string
    {
        return $data.str_repeat("\0", (512 - strlen($data) % 512) % 512);
    }
}

if (! function_exists('tarListingPaxRecord')) {
    /** One PAX record, `<length> <key>=<value>\n`, whose length counts itself. */
    function tarListingPaxRecord(string $key, string $value): string
    {
        $body = ' '.$key.'='.$value."\n";
        $length = strlen($body) + 1;

        while (strlen((string) $length) + strlen($body) !== $length) {
            $length = strlen((string) $length) + strlen($body);
        }

        return $length.$body;
    }
}

if (! function_exists('tarListingWrite')) {
    /** A gzip-compressed archive of `$blocks`, ended by two zero blocks. */
    function tarListingWrite(string $directory, array $blocks): string
    {
        $path = $directory.'/'.bin2hex(random_bytes(4)).'.tgz';
        file_put_contents($path, gzencode(implode('', $blocks).str_repeat("\0", 1024)));

        return $path;
    }
}

if (! function_exists('gameAssetPng')) {
    /** A small solid-colour PNG, as a Unity editor preview would be. */
    function gameAssetPng(int $red, int $green, int $blue, int $size = 8): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}

if (! function_exists('unityPackageWrite')) {
    /**
     * A .unitypackage: one folder per GUID with `asset`, `asset.meta`, `pathname` and, when given, `preview.png`.
     *
     * @param  array<string, array{pathname: string, preview?: string|null}>  $assets  by GUID, in archive order
     */
    function unityPackageWrite(string $directory, array $assets): string
    {
        $blocks = [];

        foreach ($assets as $guid => $asset) {
            $blocks[] = tarListingHeader($guid.'/', 0, '5');
            $blocks[] = tarListingHeader($guid.'/asset', 4);
            $blocks[] = tarListingData('data');
            $blocks[] = tarListingHeader($guid.'/asset.meta', strlen($meta = "fileFormatVersion: 2\n"));
            $blocks[] = tarListingData($meta);
            $blocks[] = tarListingHeader($guid.'/pathname', strlen($asset['pathname']));
            $blocks[] = tarListingData($asset['pathname']);

            if (($asset['preview'] ?? null) !== null) {
                $blocks[] = tarListingHeader($guid.'/preview.png', strlen($asset['preview']));
                $blocks[] = tarListingData($asset['preview']);
            }
        }

        $path = tarListingWrite($directory, $blocks);
        rename($path, $unity = substr($path, 0, -4).'.unitypackage');

        return $unity;
    }
}

if (! function_exists('blendThumbnailRgba')) {
    /** RGBA rows bottom row first, as Blender stores them: the bottom half red, the top half blue, opaque. */
    function blendThumbnailRgba(int $width, int $height): string
    {
        $rgba = '';

        for ($row = 0; $row < $height; $row++) {
            $rgba .= str_repeat($row < intdiv($height, 2) ? "\xff\x00\x00\xff" : "\x00\x00\xff\xff", $width);
        }

        return $rgba;
    }
}

if (! function_exists('blendFixture')) {
    /**
     * A .blend file's bytes in one of Blender's header formats:
     * - `legacy8`: `BLENDER-v402`, little-endian, 8-byte pointers (SmallBHead8);
     * - `legacy4be`: `BLENDER_V279`, big-endian, 4-byte pointers (BHead4);
     * - `large`: `BLENDER17-01v0500`, Blender 5.x (LargeBHead8).
     *
     * The blocks are REND, an optional TEST thumbnail, GLOB, one block per ID code in `$ids`, DNA1 and ENDB.
     *
     * @param  array{0: int, 1: int}|null  $thumbnail  width and height, or null for no TEST block
     * @param  list<string>  $ids  two-letter ID codes (`SC` scene, `OB` object)
     */
    function blendFixture(string $format = 'legacy8', ?array $thumbnail = [16, 16], array $ids = ['SC', 'OB', 'OB'], bool $endb = true): string
    {
        $little = $format !== 'legacy4be';
        $int = fn (int $value): string => pack($little ? 'V' : 'N', $value);

        $bhead = match ($format) {
            'legacy8'   => fn (string $code, int $length): string => pack('a4VPVV', $code, $length, 0x1000, 0, 1),
            'legacy4be' => fn (string $code, int $length): string => pack('a4NNNN', $code, $length, 0x1000, 0, 1),
            'large'     => fn (string $code, int $length): string => pack('a4VPPP', $code, 0, 0x1000, $length, 1),
        };

        $blocks = [['REND', str_repeat("\0", 72)]];

        if ($thumbnail !== null) {
            [$width, $height] = $thumbnail;
            $blocks[] = ['TEST', $int($width).$int($height).blendThumbnailRgba($width, $height)];
        }

        $blocks[] = ['GLOB', str_repeat("\0", 128)];

        foreach ($ids as $code) {
            $blocks[] = [$code."\0\0", str_repeat("\x2a", 200)];
        }

        $blocks[] = ['DNA1', 'SDNANAME'.str_repeat("\0", 56)];

        $bytes = match ($format) {
            'legacy8'   => 'BLENDER-v402',
            'legacy4be' => 'BLENDER_V279',
            'large'     => 'BLENDER17-01v0500',
        };

        foreach ($blocks as [$code, $data]) {
            $bytes .= $bhead($code, strlen($data)).$data;
        }

        return $endb ? $bytes.$bhead('ENDB', 0) : $bytes;
    }
}
