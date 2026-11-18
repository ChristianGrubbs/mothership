<?php

namespace Mothership\Core\Traits;

use enshrined\svgSanitize\Sanitizer as MainSanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\File;

trait Sanitizer
{
    /**
     * List of mime types which needs to check.
     */
    public $mimeTypes = [
        'image/svg',
        'image/svg+xml',
    ];

    /**
     * Sanitize SVG file.
     */
    public function sanitizeSVG(string $path, ?string $mimeType, ?string $disk = null): void
    {
        if ($this->isFileSVG($mimeType)) {
            $sanitizer = new MainSanitizer;

            try {
                $dirtySVG = Storage::disk($disk)->get($path);
                $sanitizedSVG = $sanitizer->sanitize($dirtySVG);
            } catch (\Throwable $exception) {
                $this->deleteUntrustedSVG($path, $disk, $exception);

                throw $exception;
            }

            if ($sanitizedSVG === false || $sanitizedSVG === '') {
                $this->deleteUntrustedSVG($path, $disk);

                throw ValidationException::withMessages([
                    'file' => 'The SVG upload is malformed and could not be sanitized.',
                ]);
            }

            Storage::disk($disk)->put($path, $sanitizedSVG);
        }
    }

    /**
     * Delete an untrusted SVG, preserving the original failure when cleanup succeeds.
     */
    protected function deleteUntrustedSVG(string $path, ?string $disk, ?\Throwable $previous = null): void
    {
        try {
            $deleted = Storage::disk($disk)->delete($path);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The untrusted SVG upload could not be removed from storage.', 0, $previous ?? $exception);
        }

        if (! $deleted) {
            throw new \RuntimeException('The untrusted SVG upload could not be removed from storage.', 0, $previous);
        }
    }

    /**
     * Check file mime type
     */
    public function isFileSVG(?string $mimeType): bool
    {
        return in_array($mimeType, $this->mimeTypes);
    }
}
