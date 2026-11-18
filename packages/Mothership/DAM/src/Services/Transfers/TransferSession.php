<?php

namespace Mothership\DAM\Services\Transfers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mothership\DAM\Support\TransferLog;
use Mothership\User\Models\Admin;

final class TransferSession
{
    public const LIFETIME_SECONDS = 90;

    public function origin(): ?string
    {
        $origin = rtrim((string) config('dam.transfers.origin'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            return null;
        }

        $local = app()->environment(['local', 'testing']) && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        if (($parts['scheme'] ?? '') !== 'https' && ! ($local && ($parts['scheme'] ?? '') === 'http')) {
            return null;
        }

        foreach ([config('app.url')] as $other) {
            $parsed = parse_url((string) $other);
            if (is_array($parsed) && strtolower($parts['host']) === strtolower($parsed['host'] ?? '')
                && $parts['scheme'] === ($parsed['scheme'] ?? '')
                && ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) === ($parsed['port'] ?? (($parsed['scheme'] ?? '') === 'https' ? 443 : 80))) {
                return null;
            }
        }

        return $origin;
    }

    public function available(): bool
    {
        return (bool) config('dam.transfers.enabled') && $this->origin() !== null
            && strlen((string) config('dam.transfers.shared_key')) >= 32;
    }

    public function clientConfig(Request $request): ?array
    {
        $admin = auth('admin')->user();
        if (! $this->available() || ! $admin instanceof Admin || ! $admin->status || $admin->isApiUser()
            || ! bouncer()->hasPermission('dam.transfers')) {
            return null;
        }

        $person = $request->query('person');

        return [
            'origin'         => $this->origin(),
            'frameUrl'       => $this->origin().'/',
            'grantUrl'       => route('admin.dam.transfers.grant'),
            'transfersUrl'   => route('admin.dam.transfers.index'),
            'selectedUserId' => is_string($person) && ctype_digit($person) ? $person : null,
            'labels'         => trans('dam::transfers'),
        ];
    }

    public function issue(Request $request): array
    {
        abort_unless($this->available(), 404);
        // Re-read on every renewal so disabling an account stops its connected devices within one grant lifetime.
        $admin = Admin::query()->find(auth('admin')->id());
        abort_unless($admin instanceof Admin && $admin->status && ! $admin->isApiUser() && bouncer()->hasPermission('dam.transfers'), 403);
        $key = (string) config('dam.transfers.shared_key');
        $issuedAt = now()->timestamp;
        $claims = [
            'v'    => 1,
            'aud'  => 'mothership-transfers',
            'sub'  => (string) $admin->id,
            // 100 Unicode code points fit the signaling service's 200 UTF-16-unit limit.
            'name' => mb_substr(trim($admin->name) ?: ($admin->email ?: 'Staff '.$admin->id), 0, 100),
            'sid'  => hash_hmac('sha256', 'transfers:'.$request->session()->getId(), $key),
            'jti'  => (string) Str::uuid(),
            'iat'  => $issuedAt,
            'exp'  => $issuedAt + self::LIFETIME_SECONDS,
        ];
        $payload = $this->encode(value: json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = $this->encode(value: hash_hmac('sha256', $payload, $key, true));
        TransferLog::issued(adminId: (int) $admin->id);

        return ['grant' => $payload.'.'.$signature, 'expiresAt' => $claims['exp']];
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
