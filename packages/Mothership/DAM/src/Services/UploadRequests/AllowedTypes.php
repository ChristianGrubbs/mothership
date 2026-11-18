<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Illuminate\Validation\ValidationException;

/**
 * P5a: an upload request's type limit. Entries are file extensions (`pdf`, `.jpg`) or MIME patterns (`image/png`,
 * `video/*`), lower-cased and de-duplicated. An empty list accepts any type the Library itself accepts; a file passes
 * a non-empty list when its extension is listed or its MIME type matches a pattern. Chunk init asks with the type the
 * browser declared; finalize asks again with the type read from the assembled bytes.
 */
final class AllowedTypes
{
    public const MAX_ENTRIES = 50;

    private const EXTENSION = '/\A[a-z0-9]{1,16}\z/';

    private const MIME = '/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\/([a-z0-9][a-z0-9!#$&^_.+-]{0,126}|\*)\z/';

    /**
     * The stored list from what a form or API sent: a comma- or space-separated string, a list, or nothing.
     *
     * @return list<string>
     *
     * @throws ValidationException on an entry that is neither an extension nor a MIME pattern
     */
    public static function normalize(mixed $input, string $field = 'allowed_types'): array
    {
        $entries = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input);
        $clean = [];

        foreach ($entries ?: [] as $entry) {
            $entry = strtolower(ltrim(trim((string) $entry), '.'));

            if ($entry === '') {
                continue;
            }

            if (preg_match(self::EXTENSION, $entry) !== 1 && preg_match(self::MIME, $entry) !== 1) {
                throw ValidationException::withMessages([$field => trans('dam::app.admin.upload-requests.validation.allowed-types')]);
            }

            $clean[$entry] = true;
        }

        if (count($clean) > self::MAX_ENTRIES) {
            throw ValidationException::withMessages([$field => trans('dam::app.admin.upload-requests.validation.allowed-types')]);
        }

        return array_keys($clean);
    }

    /** @param  list<string>|null  $allowed */
    public static function permits(?array $allowed, ?string $extension, ?string $mimeType): bool
    {
        if ($allowed === null || $allowed === []) {
            return true;
        }

        $extension = strtolower(trim((string) $extension));
        $mimeType = strtolower(trim((string) $mimeType));

        foreach ($allowed as $entry) {
            if (! str_contains($entry, '/')) {
                if ($extension !== '' && $entry === $extension) {
                    return true;
                }

                continue;
            }

            if ($mimeType === '') {
                continue;
            }

            if ($entry === $mimeType || (str_ends_with($entry, '/*') && str_starts_with($mimeType, substr($entry, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** What the `accept` attribute of the portal's file input says: `.pdf,image/*`. */
    public static function acceptAttribute(?array $allowed): string
    {
        return implode(',', array_map(fn (string $entry) => str_contains($entry, '/') ? $entry : '.'.$entry, $allowed ?? []));
    }
}
