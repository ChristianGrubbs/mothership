<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing\Recipes;

use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Processing\Recipe;
use Mothership\DAM\Processing\RecipeFailed;
use Mothership\DAM\Processing\RecipeOutput;
use Mothership\DAM\Processing\RecipeRun;

/** Shared helpers: number and time formatting for arguments and labels, and the ffmpeg/vips call shapes. */
abstract class BaseRecipe implements Recipe
{
    /** Image formats vips writes for convert and resize. */
    protected const IMAGE_FORMATS = ['jpg', 'png', 'webp', 'avif'];

    protected const MIME = [
        'jpg'  => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif', 'gif' => 'image/gif',
        'mp4'  => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v', 'mkv' => 'video/x-matroska',
        'm4a'  => 'audio/mp4', 'mp3' => 'audio/mpeg',
    ];

    public function version(): int
    {
        return 1;
    }

    public function accepts(string $fileType, array $params): bool
    {
        return in_array($fileType, $this->fileTypes(), true);
    }

    public function check(array $params): array
    {
        return [];
    }

    public function outputRole(): string
    {
        return AssetFile::ROLE_OUTPUT;
    }

    /** A number as a tool argument: dot decimal whatever the locale, no exponent, trailing zeros trimmed. */
    protected static function num(float|int $value, int $decimals = 3): string
    {
        $text = number_format((float) $value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Seconds as a player shows them: 0:05, 1:02:03, 0:03.5 for a fractional second. */
    protected static function clock(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $whole = (int) floor($seconds);
        $fraction = round($seconds - $whole, 1);

        if ($fraction >= 1.0) {
            $whole++;
            $fraction = 0.0;
        }

        $text = $whole >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($whole, 3600), intdiv($whole % 3600, 60), $whole % 60)
            : sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);

        return $fraction > 0 ? $text.'.'.((int) round($fraction * 10)) : $text;
    }

    /** @param  list<string>  $arguments */
    protected function ffmpeg(RecipeRun $run, array $arguments): void
    {
        $run->tools->mustRun('ffmpeg', ['-hide_banner', '-nostdin', '-v', 'error', '-y', ...$arguments], $run->workdir);
    }

    /** @param  list<string>  $arguments */
    protected function vips(RecipeRun $run, array $arguments): void
    {
        $run->tools->mustRun('vips', $arguments, $run->workdir);
    }

    /** The finished file, or empty_output when the tool exited cleanly without writing one. */
    protected function finished(string $path, string $extension, array $notes = []): RecipeOutput
    {
        clearstatcache(true, $path);

        if (! is_file($path) || (int) filesize($path) === 0) {
            throw new RecipeFailed(RecipeFailed::EMPTY_OUTPUT);
        }

        return new RecipeOutput(path: $path, extension: $extension, mimeType: self::MIME[$extension] ?? 'application/octet-stream', notes: $notes);
    }

    /** The media must hold a video stream, and $at must fall inside it. */
    protected function requireVideoAt(RecipeRun $run, float $at): void
    {
        $info = $run->info();

        if (! $info->hasVideo) {
            throw new RecipeFailed(RecipeFailed::NO_VIDEO);
        }

        if ($info->duration !== null && $at >= $info->duration) {
            throw new RecipeFailed(RecipeFailed::OUT_OF_RANGE);
        }
    }

    /** Quality 1–100 as an x264 CRF: 85 → 22, 100 → 18, 1 → 40. */
    protected static function crf(int $quality): int
    {
        return max(18, min(40, (int) round(18 + (100 - $quality) * 0.25)));
    }

    /** H.264/AAC MP4 arguments for a re-encode, $filter applied to the first video stream. */
    protected static function h264(int $crf, ?string $filter = null): array
    {
        return [
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', $filter ?? 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', (string) $crf, '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart',
        ];
    }

    /** VP9/Opus WebM arguments for a re-encode. */
    protected static function vp9(int $crf, ?string $filter = null): array
    {
        return [
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', $filter ?? 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            '-c:v', 'libvpx-vp9', '-b:v', '0', '-crf', (string) min(63, $crf + 10), '-deadline', 'realtime', '-cpu-used', '8', '-row-mt', '1',
            '-pix_fmt', 'yuv420p', '-c:a', 'libopus', '-b:a', '128k',
        ];
    }
}
