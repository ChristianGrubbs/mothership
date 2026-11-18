<?php

/**
 * Hub resource fixtures (ADR 0015). Every font is made here from DejaVu Sans, which ships with dompdf (vendor) under a
 * free license: as is (TTF), marked variable, rewrapped as WOFF and as a one- or two-face TTC (refused in v1). WOFF2 and ABR files are headers made
 * byte by byte. Each test file that uses these require_once's this file itself.
 */

use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

if (! function_exists('hubResourceDejaVu')) {
    function hubResourceDejaVu(string $face = 'DejaVuSans-BoldOblique.ttf'): string
    {
        $path = base_path('vendor/dompdf/dompdf/lib/fonts/'.$face);
        expect(is_file($path))->toBeTrue("the dompdf DejaVu font {$face} is missing from vendor");

        return (string) file_get_contents($path);
    }

    /** $bytes in a new temp file, removed when the test process ends. */
    function hubResourceFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hub-resource-');
        file_put_contents($path, $bytes);
        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }

    /**
     * The sfnt table directory of $ttf: tag => [offset, length, checksum].
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    function hubResourceTables(string $ttf): array
    {
        $count = unpack('n', substr($ttf, 4, 2))[1];
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('a4tag/Nchecksum/Noffset/Nlength', substr($ttf, 12 + 16 * $i, 16));
            $tables[$entry['tag']] = [$entry['offset'], $entry['length'], $entry['checksum']];
        }

        return $tables;
    }

    /** $ttf with every table offset moved by $shift bytes (a collection header goes in front of it). */
    function hubResourceShift(string $ttf, int $shift): string
    {
        $count = unpack('n', substr($ttf, 4, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $at = 12 + 16 * $i + 8;
            $ttf = substr_replace($ttf, pack('N', unpack('N', substr($ttf, $at, 4))[1] + $shift), $at, 4);
        }

        return $ttf;
    }

    /** A TrueType collection naming $faces faces, all of them the same DejaVu font. */
    function hubResourceTtc(int $faces): string
    {
        $header = 12 + 4 * $faces;

        return 'ttcf'.pack('N', 0x00010000).pack('N', $faces).str_repeat(pack('N', $header), $faces).hubResourceShift(hubResourceDejaVu(), $header);
    }

    /**
     * DejaVu marked as a variable font: its `FFTM` table directory entry renamed `fvar`. php-font-lib only needs the
     * tag to be present, and has no `fvar` reader, so the table body is never read.
     */
    function hubResourceVariable(): string
    {
        $ttf = hubResourceDejaVu();
        $count = unpack('n', substr($ttf, 4, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            if (substr($ttf, 12 + 16 * $i, 4) === 'FFTM') {
                return substr_replace($ttf, 'fvar', 12 + 16 * $i, 4);
            }
        }

        throw new RuntimeException('DejaVu has no FFTM table to rename.');
    }

    /** DejaVu as a WOFF 1.0 file: each table zlib-compressed where that is smaller (php-font-lib inflates them). */
    function hubResourceWoff(): string
    {
        $ttf = hubResourceDejaVu();
        $tables = hubResourceTables($ttf);
        $directory = '';
        $data = '';
        $offset = 44 + 20 * count($tables);

        foreach ($tables as $tag => [$at, $length, $checksum]) {
            $plain = substr($ttf, $at, $length);
            $packed = gzcompress($plain);
            $stored = strlen($packed) < $length ? $packed : $plain;
            $directory .= str_pad($tag, 4).pack('NNNN', $offset, strlen($stored), $length, $checksum);
            $padded = str_pad($stored, (strlen($stored) + 3) & ~3, "\0");
            $data .= $padded;
            $offset += strlen($padded);
        }

        $header = 'wOFF'.substr($ttf, 0, 4).pack('N', 44 + strlen($directory) + strlen($data)).pack('nn', count($tables), 0)
            .pack('N', strlen($ttf)).pack('nn', 1, 0).pack('NNNNN', 0, 0, 0, 0, 0);

        return $header.$directory.$data;
    }

    /** A WOFF2 header whose own length is the file's; the body is filler, as php-font-lib never reads a WOFF2. */
    function hubResourceWoff2(string $flavor = "\x00\x01\x00\x00", ?int $claimedLength = null): string
    {
        $size = 256;

        return str_pad('wOF2'.$flavor.pack('N', $claimedLength ?? $size).pack('nn', 12, 0).pack('NN', 4096, 200), $size, "\x5a");
    }

    /** An ABR of $version; from version 6 the `8BIM` sample block follows unless $signed is false. */
    function hubResourceAbr(int $version, bool $signed = true): string
    {
        $head = pack('n', $version).pack('n', $version >= 6 ? 2 : 1);

        return $head.($version >= 6 && $signed ? '8BIMsamp'.pack('N', 16) : 'XXXXsamp'.pack('N', 16)).str_repeat("\x11", 64);
    }

    function hubResourceAdmin(string $permissionType = 'custom', int $status = 1): Admin
    {
        $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => ['dam', 'dam.hub']]);

        return Admin::factory()->create(['role_id' => $role->id, 'status' => $status]);
    }
}
