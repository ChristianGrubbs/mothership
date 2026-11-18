<?php

namespace Mothership\DAM\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Symfony\Component\HttpFoundation\Response;

trait ServesAcceleratedFiles
{
    /**
     * Hand an authorized local file to nginx with X-Accel-Redirect so it is served with
     * sendfile and native byte ranges. PHP streaming a multi-hundred-megabyte video makes
     * nginx buffer the whole FastCGI response to a temp file first, so playback never starts
     * (observed 2026-09-02: a browser fetched one video three times and gave up).
     *
     * Returns null when no accel prefix is configured or the file is not readable locally,
     * so the caller falls back to the ordinary file response.
     */
    protected function acceleratedFileResponse(string $path, string $absolutePath, array $headers, ?string $mimeType = null): ?Response
    {
        $prefix = config('mothership.media_accel_prefix');

        if (blank($prefix) || ! is_file($absolutePath)) {
            return null;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        $headers['X-Accel-Redirect'] = rtrim($prefix, '/').'/'.$encoded;
        $headers['Content-Type'] = $mimeType
            ?: (Storage::disk(Directory::getAssetDisk())->mimeType($path) ?: 'application/octet-stream');

        unset($headers['X-Accel-Buffering']);

        return response('', 200, $headers);
    }
}
