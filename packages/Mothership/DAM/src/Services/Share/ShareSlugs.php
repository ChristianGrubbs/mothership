<?php

namespace Mothership\DAM\Services\Share;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Support\AddressSlugs;
use Mothership\DAM\Support\ShareLog;

/**
 * P4 D23: a share link's optional custom address, `/s/{slug}`, as the web dialog and REST both set it.
 *
 * - The slug is an alias of the link, never a second secret: `/s/{slug}` leads to the link's own token URL, whose
 *   checks (expiry, password, preview-only, availability, creator access) all still apply. Anyone with the slug can
 *   reach the link, so only those who may see the token (Share::tokenVisibleTo: the creator, the target's owner, a
 *   full admin) may see or set it.
 * - The rules (format, reserved words, unique across shares AND collections) are AddressSlugs'. Every slug a link
 *   has held stays registered to it: a renamed one answers 301 to the current one, and nothing else can take it.
 * - Reissue retires the slug with the token (ShareRepository::reissue()): a reissue cuts off everyone who had the
 *   link, by its slug too. The slug stays registered to the link, which can take it back on purpose.
 * - A collection's link has the collection's own `/c/` address and takes no slug.
 */
final class ShareSlugs
{
    /** @return array<string, string> */
    public static function rules(): array
    {
        return ['slug' => 'sometimes|nullable|string|max:255'];
    }

    /**
     * Sets, changes or clears ($slug null or blank) the link's slug, inside the caller's transaction.
     *
     * @throws ValidationException `slug` format, reserved, taken, or a collection's link
     */
    public static function write(Share $share, ?string $slug): void
    {
        $slug = AddressSlugs::normalize($slug);
        $old = $share->slug;

        if ($slug === $old) {
            return;
        }

        if ($slug !== null && $share->share_type === Share::TYPE_COLLECTION) {
            throw ValidationException::withMessages(['slug' => trans('dam::app.admin.dam.share.slug-collection')]);
        }

        if ($slug !== null && ($problem = AddressSlugs::claim(slug: $slug, holder: AddressSlugs::HOLDER_SHARE, holderId: (int) $share->id)) !== null) {
            throw ValidationException::withMessages(['slug' => trans('dam::app.admin.dam.share.slug-'.$problem)]);
        }

        try {
            Share::query()->where('id', $share->id)->update(['slug' => $slug]);
        } catch (QueryException) {
            // Another link took the same slug between the check and the write: the unique index decides.
            throw ValidationException::withMessages(['slug' => trans('dam::app.admin.dam.share.slug-taken')]);
        }

        $share->slug = $slug;
        $share->syncOriginalAttribute('slug');

        ShareLog::write('share.slug.changed', ['share_id' => (int) $share->id, 'had_slug' => $old !== null, 'has_slug' => $slug !== null]);
    }

    /**
     * What a share's presenter adds: the slug and its URL only alongside the token ($token, null for anyone else), and the
     * URL to copy, which is the slug's when one is set.
     *
     * @return array{slug: ?string, slug_url: ?string, share_url: ?string}
     */
    public static function present(Share $share, ?string $token): array
    {
        $slug = $token !== null ? $share->slug : null;
        $slugUrl = $slug !== null ? route('dam.share.slug', ['slug' => $slug]) : null;

        return [
            'slug'      => $slug,
            'slug_url'  => $slugUrl,
            'share_url' => $slugUrl ?? ($token !== null ? route('dam.share.show', ['token' => $token]) : null),
        ];
    }

    /**
     * The availability check behind the dialog's live hint and REST: the normalized slug and whether $shareId (null
     * for a new link) may use it, with the reason when not.
     *
     * @return array{slug: ?string, available: bool, problem: ?string, message: ?string}
     */
    public static function check(?string $slug, ?int $shareId): array
    {
        $slug = AddressSlugs::normalize($slug);
        $problem = $slug === null ? 'format' : AddressSlugs::problem(slug: $slug, holder: AddressSlugs::HOLDER_SHARE, holderId: $shareId);

        return [
            'slug'      => $slug,
            'available' => $problem === null,
            'problem'   => $problem,
            'message'   => $problem === null ? null : trans('dam::app.admin.dam.share.slug-'.$problem),
        ];
    }
}
