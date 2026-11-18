<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\PublicShare;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Share\ShareGate;
use Mothership\DAM\Support\AddressSlugs;
use Mothership\DAM\Support\ShareLog;

/**
 * P4 D23: `/s/{slug}`, a share link's custom address.
 *
 * - **An alias, never a second secret.** The slug resolves to its link and then leads to the link's own token URL,
 *   so every check the token path makes (password, preview-only, availability, trash, merge, creator access,
 *   view counting) runs there, unchanged and exactly once. Nothing is served here.
 * - **Open only while the link opens.** The link is read through ShareGate, as the token path reads it (creator
 *   access applied); a revoked or expired link, a link whose slug was retired by Reissue, a collection's link and an
 *   unknown slug all get the token path's own not-found page, so the answer never says which.
 * - **Renamed:** a slug the link held before answers 301 to its current one, only while the link opens.
 */
class ShareSlugController extends Controller
{
    public function open(ShareGate $gate, string $slug): RedirectResponse|Response
    {
        $slug = mb_strtolower($slug);
        $share = Share::query()->where('slug', $slug)->first();
        $renamed = false;

        if ($share === null && ($owner = AddressSlugs::owner($slug)) !== null && $owner[0] === AddressSlugs::HOLDER_SHARE) {
            $share = Share::query()->where('id', $owner[1])->first();
            $renamed = $share !== null;
        }

        $token = $share?->urlToken();
        $open = $token === null || $share->share_type === Share::TYPE_COLLECTION || $share->slug === null ? null : $gate->share($token);

        if ($open === null || ! $open->isActive()) {
            ShareLog::write('share.slug.refused', ['share_id' => $share === null ? 0 : (int) $share->id, 'renamed' => $renamed]);

            return response()->view('dam::share.public.not-found', [], 404);
        }

        if ($renamed) {
            return redirect()->route('dam.share.slug', ['slug' => $open->slug], 301);
        }

        ShareLog::write('share.slug.opened', ['share_id' => (int) $open->id]);

        return redirect()->route('dam.share.show', ['token' => $token]);
    }
}
