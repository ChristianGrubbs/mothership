<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Carbon\CarbonImmutable;

/**
 * The outside knowledge Rename with AI asks for: what was on at the time a file was captured, and where the capture
 * was. Both are hints only — AiNameSuggester works without either, and a name never waits on one.
 *
 * Nothing ships that answers these. NullNamingContext is bound by default and knows nothing, so a name is proposed
 * from the file's own description, tags and capture date. To plug a calendar or a site gazetteer back in, write an
 * implementation and bind it over the default in a service provider:
 *
 *     $this->app->bind(NamingContext::class, YourCalendar::class);
 *
 * An implementation must never throw: a failed read is no events and no place.
 */
interface NamingContext
{
    /**
     * What was on around this capture time, the closest first. Times are wall-clock strings with no zone
     * ("2026-11-12T17:00"), as camera EXIF times are, so they compare as written.
     *
     * @return list<array{title: string, location: string, start: string, end: string}>
     */
    public function eventsAround(CarbonImmutable $capturedAt): array;

    /**
     * Where the capture was: a site label and the nearest building name, '' for either when unknown.
     *
     * @param  array<string, mixed>  $metadata  the file's embedded metadata, as stored
     * @param  int  $assetId  0 for a stack member, which has no properties of its own
     * @return array{0: string, 1: string}
     */
    public function placeOf(array $metadata, int $assetId): array;
}
