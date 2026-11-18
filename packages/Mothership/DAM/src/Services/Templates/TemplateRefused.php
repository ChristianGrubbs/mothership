<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Templates;

use Illuminate\Http\JsonResponse;
use Mothership\DAM\Support\TemplateLog;
use RuntimeException;

/**
 * A fill the server will not render (P8′, D25), with a short reason code the UI and REST clients act on and a
 * translated message. Rendered as 422 (409 when the template was republished under the filler) with the same body
 * on the web and REST, and logged by code, template, version and slot id only, never the value that was refused.
 *
 * An image slot naming an asset the filler cannot see answers `image_unavailable`, exactly as a missing id does:
 * same status, same code, same message, so a fill cannot probe whether a hidden asset exists.
 */
final class TemplateRefused extends RuntimeException
{
    public const UNKNOWN_FIELD = 'unknown_field';

    public const UPLOAD_NOT_ACCEPTED = 'upload_not_accepted';

    public const VERSION_CHANGED = 'version_changed';

    public const UNKNOWN_SLOT = 'unknown_slot';

    public const MISSING_REQUIRED = 'missing_required';

    public const INVALID_VALUE = 'invalid_value';

    public const TEXT_TOO_LONG = 'text_too_long';

    public const TEXT_MULTILINE = 'text_multiline';

    public const TEXT_OVERFLOW = 'text_overflow';

    public const TEXT_UNSUPPORTED = 'text_unsupported';

    public const IMAGE_UNAVAILABLE = 'image_unavailable';

    public const IMAGE_UNSUPPORTED = 'image_unsupported';

    public const IMAGE_TOO_SMALL = 'image_too_small';

    public const CHOICE_INVALID = 'choice_invalid';

    public const RENDER_FAILED = 'render_failed';

    public function __construct(public readonly string $reason, public readonly ?string $slot = null, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }

    /** @param  array{template_id?: int, version?: int}  $ctx */
    public static function because(string $reason, ?string $slot = null, array $ctx = [], int $status = 422): self
    {
        TemplateLog::write(event: 'template.slot_refused', ctx: [...$ctx, 'reason' => $reason, 'slot' => $slot], level: 'notice');

        return new self(reason: $reason, slot: $slot, status: $status);
    }

    public function message(): string
    {
        return trans('dam::app.admin.templates.refusals.'.$this->reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->message(),
            'code'    => $this->reason,
            'slot'    => $this->slot,
        ], $this->status);
    }
}
