<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

/** What ffprobe read from a staged file: enough for recipes to check their parameters against the media. */
final class MediaInfo
{
    public function __construct(
        public readonly ?float $duration,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly bool $hasVideo,
        public readonly bool $hasAudio,
        public readonly ?float $fps,
        public readonly float $startTime = 0.0,
    ) {}

    /** @param  array<string, mixed>  $probe  decoded `ffprobe -show_format -show_streams -of json` */
    public static function fromProbe(array $probe): self
    {
        $streams = is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $video = collect($streams)->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'video');
        $audio = collect($streams)->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'audio');
        $duration = $probe['format']['duration'] ?? $video['duration'] ?? null;
        $fps = null;

        if (is_string($video['avg_frame_rate'] ?? null) && str_contains($video['avg_frame_rate'], '/')) {
            [$num, $den] = array_map('floatval', explode('/', $video['avg_frame_rate'], 2));
            $fps = $den > 0 && $num > 0 ? $num / $den : null;
        }

        return new self(
            duration: is_numeric($duration) ? (float) $duration : null,
            width: isset($video['width']) ? (int) $video['width'] : null,
            height: isset($video['height']) ? (int) $video['height'] : null,
            hasVideo: $video !== null,
            hasAudio: $audio !== null,
            fps: $fps,
            startTime: is_numeric($probe['format']['start_time'] ?? null) ? (float) $probe['format']['start_time'] : 0.0,
        );
    }
}
