<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

/**
 * File-type spelling helpers left from the in-app Creative Hub's Open with (removed 2026-11-17). The Craft MCP server's
 * kept services (CreativeHubSaver, SessionBundle, StackMedia) still compare and normalise extensions through them.
 */
final class OpenWithResolver
{
    /** Other spellings of one format; the canonical one is on the right. An asset may carry either. */
    private const ALIASES = ['jpeg' => 'jpg', 'tif' => 'tiff', 'aif' => 'aiff', 'htm' => 'html', 'midi' => 'mid'];

    public static function normalizeExtension(?string $extension): string
    {
        $extension = strtolower(ltrim(trim((string) $extension), '.'));

        return self::ALIASES[$extension] ?? $extension;
    }

    public static function sameType(?string $first, ?string $second): bool
    {
        $first = self::normalizeExtension($first);

        return $first !== '' && $first === self::normalizeExtension($second);
    }
}
