<?php

namespace Mothership\DAM\Http\Controllers\Embed;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Services\AssetEmbeds;
use Mothership\DAM\Services\ContentStore;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The public embed (P6 D26): `/embed/{token}` is a bare player page, with no admin chrome, cookie or download
 * control, and `/embed/{token}/media` streams what it plays with byte ranges. Each request asks AssetEmbeds again, so
 * revoking the embed or the asset leaving use stops the next range request. Every refusal (unknown, revoked or
 * expired token; missing, trashed or unavailable asset; nothing playable) is the same 404, body and headers alike.
 */
class EmbedController extends Controller
{
    /** The refusal's policy, the same for every refusal: nothing loads, and any page may frame the notice. */
    public const REFUSAL_CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors *";

    public function __construct(protected AssetEmbeds $embeds) {}

    public function show(string $token): SymfonyResponse
    {
        $resolved = $this->embeds->resolve($token);

        if ($resolved === null) {
            return $this->refused();
        }

        $nonce = Str::random(24);
        $asset = $resolved['asset'];

        return response()->view('dam::embed.player', [
            'title'    => (string) $asset->display_name,
            'kind'     => $asset->file_type === 'audio' ? 'audio' : 'video',
            'mediaUrl' => route('dam.embed.media', ['token' => $token]),
            'mime'     => $resolved['mime'],
            'nonce'    => $nonce,
        ])->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors ".AssetEmbeds::frameAncestors($resolved['embed']->allowed_origins),
        ]);
    }

    public function media(string $token): SymfonyResponse
    {
        $resolved = $this->embeds->resolve($token);

        if ($resolved === null) {
            return $this->refused();
        }

        try {
            // Streamed through the app on every disk (sameOrigin), never redirected to a blob URL that would outlive
            // a revoke. Only the embed page's own origin may load it (CORP), so a page that may not frame the embed
            // cannot hotlink its bytes either.
            return app(ContentStore::class)->deliver(
                object: $resolved['object'],
                filename: $resolved['filename'],
                disposition: HeaderUtils::DISPOSITION_INLINE,
                mimeType: $resolved['mime'],
                headers: [
                    'Content-Security-Policy'      => "default-src 'none'; frame-ancestors ".AssetEmbeds::frameAncestors($resolved['embed']->allowed_origins),
                    'Cross-Origin-Resource-Policy' => 'same-origin',
                ],
                sameOrigin: true,
            );
        } catch (ContentUnavailable) {
            return $this->refused();
        }
    }

    protected function refused(): Response
    {
        return response()->view('dam::embed.unavailable', [], 404)
            ->withHeaders(['Content-Security-Policy' => self::REFUSAL_CSP]);
    }
}
