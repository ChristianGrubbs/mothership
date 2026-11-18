<?php

namespace Mothership\DAM\Support;

/**
 * P4 D23: a collection's human slug, the `{slug}` in `/c/{slug}`. The rules (format, reserved words, uniqueness
 * across collections and share links) are AddressSlugs'; this names the collection side of them.
 *
 * A slug is cosmetic only for a public audience. For an internal one `/c/{slug}` still requires signing in as an
 * audience member (CollectionSlugController); a slug never replaces a secret.
 */
final class CollectionSlugs
{
    public const MIN = AddressSlugs::MIN;

    public const MAX = AddressSlugs::MAX;

    public const PATTERN = AddressSlugs::PATTERN;

    public const RESERVED = AddressSlugs::RESERVED;

    public static function normalize(?string $slug): ?string
    {
        return AddressSlugs::normalize($slug);
    }

    /** Null when $slug may be used by collection $collectionId (null for a new one); otherwise the error's key. */
    public static function problem(string $slug, ?int $collectionId): ?string
    {
        return AddressSlugs::problem(slug: $slug, holder: AddressSlugs::HOLDER_COLLECTION, holderId: $collectionId);
    }

    /** Registers $slug for the collection inside the caller's transaction; null on success, else the error's key. */
    public static function claim(string $slug, int $collectionId): ?string
    {
        return AddressSlugs::claim(slug: $slug, holder: AddressSlugs::HOLDER_COLLECTION, holderId: $collectionId);
    }
}
