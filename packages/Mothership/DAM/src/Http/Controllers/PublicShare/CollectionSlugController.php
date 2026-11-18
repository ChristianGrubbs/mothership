<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\PublicShare;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Support\CollectionLog;
use Mothership\User\Models\Admin;

/**
 * P4 D23: `/c/{slug}`, a collection's human URL.
 *
 * - **Public audience:** cosmetic. The slug leads to the collection's active share link, whose own rules (password,
 *   download-off, creator access) then apply through ShareGate. A renamed slug answers 301 to the current one first.
 *   With no active link (none yet, revoked or expired) or a closed collection, the slug answers like an unknown one.
 * - **Any other audience:** never cosmetic. The visitor must be signed in and open the collection (an audience member,
 *   its owner or a full admin); a slug never replaces that. Only then does a renamed slug answer 301, or the slug lead
 *   to the collection's page.
 * - **No existence oracle:** an unknown slug and a slug the visitor cannot open give one answer: the sign-in page for a
 *   visitor who is not signed in, and the same 404 for one who is.
 */
class CollectionSlugController extends Controller
{
    public function __construct(
        protected CollectionService $collections,
    ) {}

    public function open(string $slug)
    {
        $slug = mb_strtolower($slug);
        $collection = AssetCollection::query()->where('slug', $slug)->first();
        $renamed = false;

        if ($collection === null) {
            $collectionId = DB::table('dam_slug_redirects')->where('slug', $slug)->value('collection_id');
            $collection = $collectionId === null ? null : AssetCollection::query()->where('id', $collectionId)->first();
            $renamed = $collection !== null;
        }

        $viewer = $this->viewer();

        if ($collection !== null && $collection->isPublic() && $collection->isLive() && ($link = $this->collections->link($collection)) !== null && $link->isActive()) {
            if ($renamed && $collection->slug !== null) {
                return redirect()->route('dam.collections.slug', ['slug' => $collection->slug], 301);
            }

            $token = $link->urlToken();

            if ($token !== null) {
                CollectionLog::write('collection.slug.opened', ['collection_id' => (int) $collection->id, 'audience' => 'public']);

                return redirect()->route('dam.share.show', ['token' => $token]);
            }
        }

        if ($collection !== null && $viewer !== null && $this->collections->opens(collection: $collection, actor: $viewer)) {
            if ($renamed && $collection->slug !== null) {
                return redirect()->route('dam.collections.slug', ['slug' => $collection->slug], 301);
            }

            CollectionLog::write('collection.slug.opened', ['collection_id' => (int) $collection->id, 'audience' => (string) $collection->audience, 'viewer_id' => (int) $viewer->id]);

            return redirect()->route('admin.dam.collections.show', $collection->id);
        }

        return $this->unknown(viewer: $viewer);
    }

    /** The one answer for a slug that does not exist and one this visitor cannot open. */
    protected function unknown(?Admin $viewer): RedirectResponse
    {
        abort_if($viewer !== null, 404);

        return redirect()->guest(route('admin.session.create'));
    }

    protected function viewer(): ?Admin
    {
        $viewer = auth()->guard('admin')->user();

        return $viewer instanceof Admin && (bool) $viewer->status ? $viewer : null;
    }
}
