<?php

namespace Mothership\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Mothership\Core\PublicOrigins;

/**
 * The base URL for links in outgoing mail (Email settings, `mail_link_base_url`).
 *
 * Accepted: a bare https origin (no path, query, fragment, credentials or port) that is exactly one of the
 * origins The Mothership is configured to serve on: APP_URL or an entry of APP_PUBLIC_ORIGINS (PublicOrigins::list).
 * TRUSTED_HOSTS is not consulted: its entries are regex patterns, not origins. Anything else is refused at save
 * time, and again when a link is built, so a stale or hand-edited row cannot point mail elsewhere.
 */
class MailLinkBaseUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::origin(value: $value) === null) {
            $fail('admin::app.invites.link-base-invalid')->translate();
        }
    }

    /**
     * The normalised origin (`https://host`), or null when $value is not acceptable.
     */
    public static function origin(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $parts = parse_url($value);

        if ($value === '' || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])) {
            return null;
        }

        if (! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $origin = 'https://'.strtolower($parts['host']);

        $allowed = PublicOrigins::list(canonical: rtrim((string) config('app.url'), '/'), extra: config('app.public_origins'));

        return in_array($origin, array_map('strtolower', $allowed), true) ? $origin : null;
    }
}
