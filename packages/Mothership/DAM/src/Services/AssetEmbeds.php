<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Embed;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Support\VideoLog;

/**
 * Tokenized embeds of a video or audio asset (P6 D26): making, listing and revoking them for staff, and resolving a
 * public `/embed/{token}` request to the bytes it may play. Every public refusal (an unknown, revoked or expired
 * token, an asset that is not out for use, one with nothing a browser can play) resolves to null, so the routes
 * answer them all alike.
 */
class AssetEmbeds
{
    public const TOKEN_LENGTH = 48;

    public const MAX_ORIGINS = 10;

    /** A web origin as a frame ancestor: scheme, host (an optional leading `*.`), optional port. Nothing else. */
    public const ORIGIN_PATTERN = '/\Ahttps?:\/\/(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?\z/';

    /** The file types an embed plays. */
    public const EMBEDDABLE_TYPES = ['video', 'audio'];

    private const PLAYABLE_AUDIO_MIMES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/ogg', 'audio/webm', 'audio/flac', 'audio/x-flac', 'audio/opus'];

    private const PLAYABLE_AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'flac'];

    public static function embeddable(Asset $asset): bool
    {
        return in_array($asset->file_type, self::EMBEDDABLE_TYPES, true);
    }

    /**
     * Makes an embed and returns it with its URL token, which is never stored and cannot be shown again.
     *
     * @param  list<string>  $allowedOrigins  already normalized by normalizeOrigins()
     * @return array{embed: Embed, token: string}
     */
    public function create(Asset $asset, ?int $createdBy, ?Carbon $expiresAt, array $allowedOrigins): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $embed = Embed::create([
            'asset_id'        => $asset->id,
            'token_hash'      => Embed::hashToken($token),
            'created_by'      => $createdBy,
            'expires_at'      => $expiresAt,
            'allowed_origins' => $allowedOrigins === [] ? null : array_values($allowedOrigins),
        ]);

        VideoLog::write(event: 'embed.created', ctx: [
            'embed_id'  => (int) $embed->id,
            'asset_id'  => (int) $asset->id,
            'by'        => (int) $createdBy,
            'origins'   => count($allowedOrigins),
            'expires'   => $expiresAt !== null,
        ]);

        return ['embed' => $embed, 'token' => $token];
    }

    /** Revokes an active embed in one conditional write; false when it was already revoked. */
    public function revoke(Embed $embed, ?int $revokedBy): bool
    {
        $revoked = Embed::query()->where('id', $embed->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;

        VideoLog::write(event: $revoked ? 'embed.revoked' : 'embed.revoke_noop', ctx: [
            'embed_id' => (int) $embed->id,
            'asset_id' => (int) $embed->asset_id,
            'by'       => (int) $revokedBy,
        ]);

        return $revoked;
    }

    /** @return Collection<int, Embed> newest first */
    public function forAsset(Asset $asset): Collection
    {
        return Embed::query()->where('asset_id', $asset->id)->with('creator:id,name')->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** What staff see of an embed: never its token or hash. */
    public function present(Embed $embed, bool $canRevoke): array
    {
        return [
            'id'              => (int) $embed->id,
            'asset_id'        => (int) $embed->asset_id,
            'status'          => $embed->status(),
            'allowed_origins' => $embed->allowed_origins ?? [],
            'expires_at'      => $embed->expires_at?->toIso8601String(),
            'revoked_at'      => $embed->revoked_at?->toIso8601String(),
            'created_at'      => $embed->created_at?->toIso8601String(),
            'created_by'      => $embed->created_by === null ? null : (int) $embed->created_by,
            'creator_name'    => $embed->creator?->name,
            'can_revoke'      => $canRevoke && $embed->status() === Embed::STATUS_ACTIVE,
        ];
    }

    /** The embed URL and its iframe snippet, for the one response that makes the embed. */
    public function urls(string $token, Asset $asset): array
    {
        $url = route('dam.embed.show', ['token' => $token]);
        $height = $asset->file_type === 'audio' ? 120 : 360;
        $title = e((string) $asset->display_name);

        return [
            'url'     => $url,
            'snippet' => '<iframe src="'.e($url).'" width="640" height="'.$height.'" title="'.$title.'" style="border:0" allow="fullscreen; picture-in-picture" referrerpolicy="no-referrer"></iframe>',
        ];
    }

    /**
     * Allowed origins as sent (a list, or one string with one origin per line or comma), lower-cased with no trailing
     * slash, duplicates dropped. Validation (ORIGIN_PATTERN, MAX_ORIGINS) runs on the result, so nothing but an origin
     * ever reaches the frame-ancestors header.
     *
     * @return list<string>
     */
    public static function normalizeOrigins(mixed $input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[\s,]+/', $input) ?: [];
        }

        if (! is_array($input)) {
            return [];
        }

        $origins = [];

        foreach ($input as $origin) {
            if (! is_string($origin)) {
                $origins[] = $origin;

                continue;
            }

            $origin = rtrim(strtolower(trim($origin)), '/');

            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins, SORT_REGULAR));
    }

    /** The CSP frame-ancestors source list: the embed's allowed origins, or any web page when it names none. */
    public static function frameAncestors(?array $allowedOrigins): string
    {
        $origins = array_values(array_filter((array) $allowedOrigins, fn ($origin) => is_string($origin) && preg_match(self::ORIGIN_PATTERN, $origin) === 1));

        return $origins === [] ? '*' : implode(' ', $origins);
    }

    /**
     * The embed a URL token names, its asset and the file it plays, or null for any reason it may not play: the token
     * is unknown, the embed is revoked or expired, the asset is missing, trashed or not out for use for everyone
     * (asked through the access seam), or nothing of it is something a browser can play.
     *
     * @return array{embed: Embed, asset: Asset, object: FileObject, mime: string, filename: string}|null
     */
    public function resolve(string $token): ?array
    {
        $hash = Embed::hashToken($token);
        $embed = Embed::query()->where('token_hash', $hash)->first();

        // The lookup is by an index over a digest the caller cannot steer; the comparison after it is constant-time,
        // so neither the database's collation nor a string compare can answer for a near miss.
        if ($embed === null || ! hash_equals((string) $embed->token_hash, $hash)) {
            return $this->refused(reason: 'unknown');
        }

        if ($embed->status() !== Embed::STATUS_ACTIVE) {
            return $this->refused(reason: $embed->status(), embed: $embed);
        }

        $asset = app(AssetAccessDecision::class)->openToPublic((int) $embed->asset_id);

        if ($asset === null || ! self::embeddable($asset)) {
            return $this->refused(reason: 'asset_closed', embed: $embed);
        }

        $source = $this->playableSource($asset);

        if ($source === null) {
            return $this->refused(reason: 'nothing_playable', embed: $embed);
        }

        return ['embed' => $embed, 'asset' => $asset] + $source;
    }

    /**
     * The preview proxy when one is published; otherwise the original, only when a browser can play it.
     *
     * @return array{object: FileObject, mime: string, filename: string}|null
     */
    public function playableSource(Asset $asset): ?array
    {
        $baseName = pathinfo((string) $asset->file_name, PATHINFO_FILENAME) ?: 'media';

        if ($asset->file_type === 'video') {
            $proxy = $asset->publishedWebProxy();

            if ($proxy instanceof AssetFile && $proxy->fileObject !== null) {
                return ['object' => $proxy->fileObject, 'mime' => 'video/mp4', 'filename' => $baseName.'.mp4'];
            }

            if ($asset->originalBrowserPlaybackType() !== 'video') {
                return null;
            }
        } elseif (! $this->playableAudio($asset)) {
            return null;
        }

        try {
            $object = app(ContentStore::class)->current($asset);
        } catch (\Throwable) {
            return null;
        }

        if (! app(ContentStore::class)->holdsBlob($object)) {
            return null;
        }

        return [
            'object'   => $object,
            'mime'     => (string) ($asset->mime_type ?: $object->mime_type ?: 'application/octet-stream'),
            'filename' => (string) $asset->file_name,
        ];
    }

    private function playableAudio(Asset $asset): bool
    {
        $mime = strtolower(trim(explode(';', (string) $asset->mime_type)[0]));

        return in_array($mime, self::PLAYABLE_AUDIO_MIMES, true)
            || in_array(strtolower((string) $asset->extension), self::PLAYABLE_AUDIO_EXTENSIONS, true);
    }

    private function refused(string $reason, ?Embed $embed = null): null
    {
        VideoLog::write(event: 'embed.refused', ctx: [
            'reason'   => $reason,
            'embed_id' => $embed === null ? 0 : (int) $embed->id,
        ]);

        return null;
    }
}
