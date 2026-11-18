<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * Why an upload was refused, in a form that can be stored and shown later: a generic `code` the client can branch
 * on, a translation `key` and its `params`. It is never exception text, so a stored reason cannot leak a path, a
 * class name or a stack trace to the browser.
 *
 * The status endpoint and the direct upload response expose it as `{code, message}`; any refusal (a web-page
 * bundle, a file type) can use the same shape.
 */
final class UploadRefusal
{
    /** Only reasons worded in these translation namespaces are rendered; anything else falls back to the generic text. */
    private const KEY_PREFIXES = ['dam::app.', 'admin::app.'];

    private const MAX_PARAM_LENGTH = 200;

    /** What is stored and shown when a reason names a key that is unknown or outside KEY_PREFIXES. */
    public const GENERIC_KEY = 'dam::app.admin.dam.web-pages.refused.generic';

    /**
     * @param  array<string, int|string>  $params
     * @return array{code: string, key: string, params: array<string, int|string>}
     */
    public static function make(string $code, string $key, array $params = []): array
    {
        // Normalised before it is stored: a reason that could not be shown later is replaced by the generic one now.
        if (! self::showable($key)) {
            $key = self::GENERIC_KEY;
            $params = [];
        }

        return [
            'code'   => self::cleanCode($code),
            'key'    => $key,
            'params' => array_map(self::cleanParam(...), $params),
        ];
    }

    /**
     * The reason in the current locale.
     *
     * @param  array<string, mixed>  $reason
     */
    public static function message(array $reason): string
    {
        $key = is_string($reason['key'] ?? null) ? $reason['key'] : '';
        $params = is_array($reason['params'] ?? null) ? array_map(self::cleanParam(...), $reason['params']) : [];

        if (! self::showable($key)) {
            return (string) trans(self::GENERIC_KEY);
        }

        return (string) trans($key, $params);
    }

    /**
     * The `{code, message}` a client shows, or null when there is no stored reason.
     *
     * @param  array<string, mixed>|null  $reason
     * @return array{code: string, message: string}|null
     */
    public static function payload(?array $reason): ?array
    {
        if (! $reason) {
            return null;
        }

        return [
            'code'    => self::cleanCode($reason['code'] ?? null),
            'message' => self::message($reason),
        ];
    }

    private static function cleanCode(mixed $code): string
    {
        return is_string($code) && preg_match('/\A[a-z0-9_]{1,64}\z/', $code) === 1 ? $code : 'refused';
    }

    private static function showable(string $key): bool
    {
        $inNamespace = array_filter(self::KEY_PREFIXES, fn (string $prefix): bool => str_starts_with($key, $prefix)) !== [];

        // A key naming a group of strings (an array) is not a message.
        return $inNamespace && trans()->has($key) && is_string(trans($key));
    }

    /**
     * A parameter as plain, single-line text: control characters removed, whitespace collapsed, capped. It is
     * text, not markup; the tray renders it with textContent semantics.
     */
    private static function cleanParam(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        $text = (string) (is_scalar($value) ? $value : '');
        $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > self::MAX_PARAM_LENGTH ? mb_substr($text, 0, self::MAX_PARAM_LENGTH - 1).'…' : $text;
    }
}
