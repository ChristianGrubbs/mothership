<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

/**
 * A session file that names its media by relative path (SoundCraftSession reads them), opened in the hub as the bundle
 * its app reads (.scraftz: the session at the zip's root and each medium at the path it names, stored uncompressed, since
 * SoundCraft refuses deflated entries). The media come from the session's own stack (StackMedia::match); one the stack
 * does not hold is left out and the app shows it offline.
 */
final class SessionBundle
{
    /** Session type => the bundle type its app opens. */
    public const TYPES = ['scraft' => 'scraftz'];

    public static function bundleType(?string $extension): ?string
    {
        return self::TYPES[OpenWithResolver::normalizeExtension($extension)] ?? null;
    }
}
