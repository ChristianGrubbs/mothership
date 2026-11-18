<?php

namespace Mothership\DAM\Filesystem;

use enshrined\svgSanitize\Sanitizer as SvgSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mothership\Core\Filesystem\FileStorer as BaseFileStorer;

class FileStorer extends BaseFileStorer
{
    public function store(string $path, mixed $file, $fileName = null, array $options = [])
    {
        $name = $fileName ?? $this->getFileName($file);

        return $this->storeAs($path, $name, $file, $options);
    }

    public function storeAs(string $path, string $name, mixed $file, array $options = [])
    {
        $result = parent::storeAs($path, $name, $file, $options);

        if ($result === false) {
            throw new \RuntimeException(trans('dam::app.admin.dam.asset.datagrid.storage-upload-failed', ['name' => $name]));
        }

        return $result;
    }

    /**
     * Put an upload at `$stagingPath` on `$disk` for ContentStore::adoptStored(), never at a Library path
     * (ADR 0009 slice 3). An SVG is sanitized on its local temp file first, so the blob, its sha256 and the
     * asset's file_size all describe the sanitized bytes.
     */
    public function stage(UploadedFile $file, string $disk, string $stagingPath): string
    {
        $this->sanitizeLocalSvg(localPath: (string) $file->getRealPath(), mimeType: $file->getMimeType());

        $result = Storage::disk($disk)->putFileAs(dirname($stagingPath), $file, basename($stagingPath));

        if ($result === false) {
            throw new \RuntimeException(trans('dam::app.admin.dam.asset.datagrid.storage-upload-failed', ['name' => $file->getClientOriginalName()]));
        }

        return $result;
    }

    /**
     * Sanitize an SVG in place on the local filesystem, before anything hashes it. The chunked path only has
     * the client's declared MIME type, so the bytes are sniffed too. Returns the sanitized bytes, or null for
     * any other file; an SVG that does not parse is rejected before writing or staging any bytes.
     */
    public function sanitizeLocalSvg(string $localPath, ?string $mimeType): ?string
    {
        $sniffed = @mime_content_type($localPath) ?: null;

        if (! $this->isFileSVG($mimeType) && ! $this->isFileSVG($sniffed)) {
            return null;
        }

        $dirty = @file_get_contents($localPath);

        if ($dirty === false) {
            throw new \RuntimeException('The SVG upload could not be read for sanitizing.');
        }

        $clean = (new SvgSanitizer)->sanitize($dirty);

        if ($clean === false || $clean === '') {
            throw ValidationException::withMessages([
                'file' => 'The SVG upload is malformed and could not be sanitized.',
            ]);
        }

        if (@file_put_contents($localPath, $clean) !== strlen($clean)) {
            throw new \RuntimeException('The sanitized SVG upload could not be written.');
        }

        clearstatcache(true, $localPath);

        return $clean;
    }
}
