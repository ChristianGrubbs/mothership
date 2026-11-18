<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;

/**
 * A short-lived, signed capability that lets the session-free page route serve one bundle
 * version to the admin who opened it (ADR 0007 amendment). Siblings resolve relative to the
 * grant segment, so every request a sandboxed (opaque-origin) page makes carries it.
 */
class PageGrant
{
    public const TTL = 3600;

    private const MEMBER_DOMAIN = 'dam-page-member|';

    public static function issue(int $assetId, int $version, int $adminId, int $ttlSeconds = self::TTL): string
    {
        $exp = time() + $ttlSeconds;
        $payload = "{$assetId}.{$version}.{$adminId}.{$exp}";

        return self::encode($payload.'.'.self::sign($payload));
    }

    /** @return array{version:int, admin:int, exp:int}|null */
    public static function verify(string $grant, int $assetId): ?array
    {
        $decoded = self::decode($grant);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 5) {
            return null;
        }

        [$id, $version, $admin, $exp, $sig] = $parts;

        if (! ctype_digit($id) || ! ctype_digit($version) || ! ctype_digit($admin) || ! ctype_digit($exp)) {
            return null;
        }

        $payload = "{$id}.{$version}.{$admin}.{$exp}";

        if (! hash_equals(self::sign($payload), $sig) || (int) $id !== $assetId || (int) $exp < time()) {
            return null;
        }

        return ['version' => (int) $version, 'admin' => (int) $admin, 'exp' => (int) $exp];
    }

    /**
     * A member grant: the same bearer capability, bound to one current file of the owning asset instead of a bundle
     * version. The payload leads with `m` and is signed under its own domain, so a legacy grant can never verify as a
     * member grant nor the reverse (verify() also rejects any grant that is not exactly five parts, and this one has
     * eight). The pin is the owning asset, the attachment slot, the AssetFile revision and its FULL 64-hex checksum.
     */
    public static function issueMember(Asset $asset, AssetFile $file, string $checksum, int $adminId, int $ttlSeconds = self::TTL): string
    {
        $exp = time() + $ttlSeconds;
        $payload = implode('.', ['m', $asset->id, (int) $file->attachment_id, $file->id, strtolower($checksum), $adminId, $exp]);

        return self::encode($payload.'.'.self::sign($payload, self::MEMBER_DOMAIN));
    }

    /** @return array{asset:int, attachment:int, file:int, checksum:string, admin:int, exp:int}|null */
    public static function verifyMember(string $grant, int $assetId): ?array
    {
        $decoded = self::decode($grant);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 8 || $parts[0] !== 'm') {
            return null;
        }

        [, $id, $attachment, $file, $checksum, $admin, $exp, $sig] = $parts;

        foreach ([$id, $attachment, $file, $admin, $exp] as $number) {
            if (! ctype_digit($number) || strlen($number) > 18) {
                return null;
            }
        }

        if (preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1) {
            return null;
        }

        $payload = implode('.', ['m', $id, $attachment, $file, $checksum, $admin, $exp]);

        if (! hash_equals(self::sign($payload, self::MEMBER_DOMAIN), $sig) || (int) $id !== $assetId || (int) $exp < time()) {
            return null;
        }

        return ['asset' => (int) $id, 'attachment' => (int) $attachment, 'file' => (int) $file, 'checksum' => $checksum, 'admin' => (int) $admin, 'exp' => (int) $exp];
    }

    /** Trailing slash is deliberate: relative URLs inside the page must resolve under the grant. */
    public static function memberBaseUrl(Asset $asset, AssetFile $file, string $checksum, int $adminId): string
    {
        $grant = self::issueMember(asset: $asset, file: $file, checksum: $checksum, adminId: $adminId);

        return rtrim(route('admin.dam.pages.file', ['id' => $asset->id, 'grant' => $grant, 'path' => '']), '/').'/';
    }

    /** Trailing slash is deliberate: relative URLs inside the page must resolve under the grant. */
    public static function baseUrl(Asset $asset, int $adminId): string
    {
        $grant = self::issue($asset->id, WebPageBundler::versionNumber($asset), $adminId);

        return rtrim(route('admin.dam.pages.file', ['id' => $asset->id, 'grant' => $grant, 'path' => '']), '/').'/';
    }

    protected static function sign(string $payload, string $domain = ''): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', $domain.$payload, $key);
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function decode(string $value): ?string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_-]{20,512}$/', $value)) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
