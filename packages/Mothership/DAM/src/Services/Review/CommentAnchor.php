<?php

namespace Mothership\DAM\Services\Review;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where on a revision a review comment points (P7): a point or a region on an image, normalized to 0–1 of its width
 * and height so it survives any display size, or a time range in seconds on video and audio. Anything else, an extra
 * key included, is refused, so what is stored is always one of the three shapes normalize() returns.
 */
final class CommentAnchor implements ValidationRule
{
    public const POINT = 'point';

    public const REGION = 'region';

    public const TIME = 'time';

    /** The keys each shape carries; `end` is optional on a time anchor. */
    private const KEYS = [
        self::POINT  => ['type', 'x', 'y'],
        self::REGION => ['type', 'x', 'y', 'w', 'h'],
        self::TIME   => ['type', 'start', 'end'],
    ];

    /** Slack for a region whose edge was rounded in the browser to exactly 1. */
    private const EPSILON = 0.000001;

    /** @param  bool  $timed  whether the asset is video or audio, the only kinds a time anchor fits */
    public function __construct(private readonly bool $timed) {}

    public static function forFileType(?string $fileType): self
    {
        return new self(timed: in_array($fileType, ['video', 'audio'], true));
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $type = is_array($value) ? ($value['type'] ?? null) : null;

        if (! is_string($type) || ! isset(self::KEYS[$type]) || array_diff(array_keys($value), self::KEYS[$type]) !== []) {
            $fail('dam::app.admin.dam.asset.comments.review.anchor-invalid')->translate();

            return;
        }

        if ($type === self::TIME && ! $this->timed) {
            $fail('dam::app.admin.dam.asset.comments.review.anchor-time-media')->translate();

            return;
        }

        if (! $this->validShape(type: $type, anchor: $value)) {
            $fail('dam::app.admin.dam.asset.comments.review.anchor-invalid')->translate();
        }
    }

    /**
     * The stored form of a validated anchor: numbers as floats rounded to six places, and a time anchor without an
     * end when none was given. Null stays null.
     *
     * @param  array<string, mixed>|null  $anchor
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $anchor): ?array
    {
        if ($anchor === null) {
            return null;
        }

        $type = $anchor['type'];
        $normalized = ['type' => $type];

        foreach (self::KEYS[$type] as $key) {
            if ($key !== 'type' && self::present($anchor[$key] ?? null)) {
                $normalized[$key] = round((float) $anchor[$key], 6);
            }
        }

        return $normalized;
    }

    /** @param  array<string, mixed>  $anchor */
    private function validShape(string $type, array $anchor): bool
    {
        if ($type === self::TIME) {
            $start = $anchor['start'] ?? null;
            $end = $anchor['end'] ?? null;

            if (! self::number($start) || (float) $start < 0) {
                return false;
            }

            return ! self::present($end) || (self::number($end) && (float) $end >= (float) $start);
        }

        foreach (array_slice(self::KEYS[$type], 1) as $key) {
            if (! self::number($anchor[$key] ?? null)) {
                return false;
            }
        }

        [$x, $y] = [(float) $anchor['x'], (float) $anchor['y']];

        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            return false;
        }

        if ($type === self::POINT) {
            return true;
        }

        [$w, $h] = [(float) $anchor['w'], (float) $anchor['h']];

        return $w > 0 && $h > 0 && $x + $w <= 1 + self::EPSILON && $y + $h <= 1 + self::EPSILON;
    }

    /** A finite JSON number, or a numeric string as a form post sends one; never a boolean. */
    private static function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float) $value);
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
