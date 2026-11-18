<?php

namespace Mothership\DAM\Support;

/**
 * Names are shown to people who read them as text. A right-to-left override or an embedding/isolate control inside a
 * name reorders the characters around it ("report<RLO>evil<PDF> gpj.jpg" reads "report live gpj.jpg"), which lets a
 * file dress as another type. Display names and ZIP entry names go through here; the stored name is untouched.
 * Marks (LRM/RLM, Arabic letter mark) stay: real right-to-left names use them.
 */
final class BidiControls
{
    /** U+202A..U+202E (embeddings, overrides, PDF) and U+2066..U+2069 (isolates). */
    private const PATTERN = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function strip(string $name): string
    {
        return preg_replace(self::PATTERN, '', $name) ?? $name;
    }
}
