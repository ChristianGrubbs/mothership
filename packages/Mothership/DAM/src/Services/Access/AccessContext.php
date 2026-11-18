<?php

namespace Mothership\DAM\Services\Access;

use Mothership\User\Models\Admin;

/**
 * Who is asking about an asset, why, and through which surface (P2 S2). The viewer is the signed-in admin unless
 * one is named, as a grant-authorised page request does. Share links (`share:<id>`) join when the public viewer
 * delegates to the seam (P2 S5/S6).
 *
 * P4 D2 (additive): `collection:<id>` names a collection's own routes. Only collection() and collectionLink() make
 * one, and only the collection controllers call them, so a collection-audience grant can never reach a Library,
 * search, REST or ordinary file context: those never carry a collection id (roadmap §3.3, grants bound to their
 * container).
 */
final class AccessContext
{
    public const PURPOSE_LIST = 'list';

    public const PURPOSE_PREVIEW = 'preview';

    public const PURPOSE_DOWNLOAD = 'download';

    /** Changing the asset. Under `grants` this also asks the asset's first folder, as mutations always have. */
    public const PURPOSE_EDIT = 'edit';

    public const PURPOSE_SHARE = 'share';

    public const VIA_LIBRARY = 'library';

    public const VIA_API = 'api';

    /** P4 D2: the prefix of `collection:<id>`, a collection's own routes. */
    public const VIA_COLLECTION = 'collection';

    /**
     * @param  bool  $includeUnavailable  P2 S3: whether a listing shows the owner, editors and full admins the assets
     *                                    that are not out for use (with a badge). Off, a listing shows only what
     *                                    everyone it is shared with sees: the Library's "Include unavailable" filter.
     * @param  ?int  $collectionId  P4 D2: set only for `collection:<id>`; the seam then answers through CollectionGrant.
     * @param  bool  $throughLink  P4 D2: a public collection's share link, already opened by ShareGate, asks instead
     *                             of a signed-in viewer.
     */
    private function __construct(
        public readonly string $purpose,
        public readonly string $via,
        public readonly ?Admin $viewer,
        public readonly bool $includeUnavailable = true,
        public readonly ?int $collectionId = null,
        public readonly bool $throughLink = false,
    ) {}

    /** The same context with unavailable assets left out of listings for every viewer. */
    public function withoutUnavailable(): self
    {
        return new self(purpose: $this->purpose, via: $this->via, viewer: $this->viewer, includeUnavailable: false, collectionId: $this->collectionId, throughLink: $this->throughLink);
    }

    /**
     * P4 D2: a collection's own routes for a signed-in viewer (the session's admin unless one is named). The only
     * context in which a collection-audience grant counts, and then only for that collection's members.
     */
    public static function collection(int $collectionId, string $purpose = self::PURPOSE_PREVIEW, ?Admin $viewer = null): self
    {
        return new self(purpose: $purpose, via: self::VIA_COLLECTION.':'.$collectionId, viewer: $viewer, collectionId: $collectionId);
    }

    /** P4 D2: a public collection's routes through its share link, which ShareGate has already opened. */
    public static function collectionLink(int $collectionId, string $purpose = self::PURPOSE_PREVIEW): self
    {
        return new self(purpose: $purpose, via: self::VIA_COLLECTION.':'.$collectionId, viewer: null, collectionId: $collectionId, throughLink: true);
    }

    /** P4 D2: whether this is a `collection:<id>` context. */
    public function isCollection(): bool
    {
        return $this->collectionId !== null;
    }

    public static function library(string $purpose = self::PURPOSE_PREVIEW): self
    {
        return new self(purpose: $purpose, via: self::VIA_LIBRARY, viewer: null);
    }

    public static function api(string $purpose = self::PURPOSE_PREVIEW): self
    {
        return new self(purpose: $purpose, via: self::VIA_API, viewer: null);
    }

    /** Asks for a named admin instead of the session, e.g. the admin a page grant was issued to. */
    public static function forAdmin(Admin $admin, string $purpose = self::PURPOSE_PREVIEW, string $via = self::VIA_LIBRARY): self
    {
        return new self(purpose: $purpose, via: $via, viewer: $admin);
    }
}
