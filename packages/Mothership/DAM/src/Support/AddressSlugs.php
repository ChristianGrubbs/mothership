<?php

namespace Mothership\DAM\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * P4 D23: the one set of rules for a human address, a collection's `/c/{slug}` and a share link's `/s/{slug}`.
 *
 * - Lowercase letters, digits and single hyphens between them, 3 to 64 characters. Input is lowercased first.
 * - Never a reserved word (RESERVED): route segments, auth words and generic names a visitor could mistake for the
 *   product's own pages.
 * - Unique across collections AND shares, current and previous slugs alike, so an old URL never starts opening
 *   something else. `dam_slug_redirects` registers every slug a holder has claimed (one row per slug, owned by a
 *   collection or a share), and its unique index settles two writers racing for one slug. A holder may take back
 *   its own old slug.
 *
 * A slug is never a secret: for a share it is an alias that leads to the share's own token URL, whose checks all
 * still apply; for an internal collection it still requires signing in as an audience member.
 */
final class AddressSlugs
{
    public const HOLDER_COLLECTION = 'collection';

    public const HOLDER_SHARE = 'share';

    public const MIN = 3;

    public const MAX = 64;

    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const RESERVED = [
        'about', 'account', 'admin', 'api', 'app', 'assets', 'auth', 'c', 'collection', 'collections', 'dam', 'dashboard',
        'docs', 'download', 'downloads', 'edit', 'health', 'help', 'home', 'index', 'library', 'login', 'logout', 'new',
        'null', 'oauth', 'page', 'pages', 'password', 'public', 'register', 'reset', 'root', 's', 'search', 'settings',
        'share', 'shared', 'shares', 'signin', 'signout', 'static', 'storage', 'support', 'system', 'undefined', 'up',
        'upload', 'uploads', 'user', 'users', 'www',
    ];

    private const COLUMN = [self::HOLDER_COLLECTION => 'collection_id', self::HOLDER_SHARE => 'share_id'];

    public static function normalize(?string $slug): ?string
    {
        $slug = $slug === null ? '' : mb_strtolower(trim($slug));

        return $slug === '' ? null : $slug;
    }

    /**
     * Null when $slug may be used by the holder ($holder one of the HOLDER_* types, $holderId null for a new one);
     * otherwise the problem: `format`, `reserved` or `taken`.
     */
    public static function problem(string $slug, string $holder, ?int $holderId): ?string
    {
        if (strlen($slug) < self::MIN || strlen($slug) > self::MAX || preg_match(self::PATTERN, $slug) !== 1) {
            return 'format';
        }

        if (in_array($slug, self::RESERVED, true)) {
            return 'reserved';
        }

        $own = fn (string $type) => $holder === $type && $holderId !== null;

        $collection = DB::table('dam_collections')->where('slug', $slug)
            ->when($own(self::HOLDER_COLLECTION), fn ($q) => $q->where('id', '!=', $holderId))
            ->exists();
        $share = DB::table('dam_shares')->where('slug', $slug)
            ->when($own(self::HOLDER_SHARE), fn ($q) => $q->where('id', '!=', $holderId))
            ->exists();
        $registered = self::owner($slug);
        $registeredElsewhere = $registered !== null && ! ($holderId !== null && $registered === [$holder, $holderId]);

        return $collection || $share || $registeredElsewhere ? 'taken' : null;
    }

    /**
     * Registers $slug for the holder, after problem() and inside the caller's transaction, so the slug is the
     * holder's from then on, current or not. Null on success; otherwise the problem. A writer that loses a race for
     * the same slug gets `taken` from the unique index, never an error.
     */
    public static function claim(string $slug, string $holder, int $holderId): ?string
    {
        if (($problem = self::problem(slug: $slug, holder: $holder, holderId: $holderId)) !== null) {
            return $problem;
        }

        if (self::owner($slug) !== null) {
            return null;
        }

        try {
            DB::table('dam_slug_redirects')->insert(['slug' => $slug, self::COLUMN[$holder] => $holderId, 'created_at' => now()]);
        } catch (QueryException) {
            return 'taken';
        }

        return null;
    }

    /** Who registered $slug: [HOLDER_*, id], or null for a slug no one has claimed. */
    public static function owner(string $slug): ?array
    {
        $row = DB::table('dam_slug_redirects')->where('slug', $slug)->first(['collection_id', 'share_id']);

        return match (true) {
            $row === null                => null,
            $row->collection_id !== null => [self::HOLDER_COLLECTION, (int) $row->collection_id],
            $row->share_id !== null      => [self::HOLDER_SHARE, (int) $row->share_id],
            default                      => null,
        };
    }
}
