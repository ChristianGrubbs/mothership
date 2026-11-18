<?php

namespace Mothership\DAM\Support;

/**
 * The file-type placeholder pictures the Library grid shows, as inline data URIs read from the package itself.
 * The signed-out share pages used `storage/dam/grid/*.svg`, which exists only after `vendor:publish`; production
 * deploys never run it, so those pages showed broken images (VIS2-14).
 */
final class GridPlaceholders
{
    /** @var array<string, string> */
    private static array $cache = [];

    private const FILES = [
        'image'    => 'image',
        'video'    => 'video',
        'audio'    => 'audio',
        'document' => 'file',
    ];

    public static function dataUri(?string $fileType): string
    {
        $name = self::FILES[$fileType ?? ''] ?? 'unspecified';

        return self::$cache[$name] ??= 'data:image/svg+xml;base64,'.base64_encode((string) @file_get_contents(dirname(__DIR__).'/Resources/assets/images/grid/'.$name.'.svg'));
    }
}
