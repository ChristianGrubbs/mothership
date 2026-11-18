<?php

namespace Mothership\Admin\Http\Controllers;

use App\Documentation\DocumentationTelemetry;
use App\Documentation\Manual;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DocumentationController extends Controller
{
    public function api(): View
    {
        // The API reference and raw route/OpenAPI files are administrator material, like the administrator guides.
        abort_unless(bouncer()->hasPermission('configuration'), 403);
        $document = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $coverage = json_decode(file_get_contents(base_path('docs/api/coverage.json')), true, flags: JSON_THROW_ON_ERROR);
        DocumentationTelemetry::record(event: 'api.read', ctx: ['paths' => count($document['paths'])]);

        return view('admin::help.api', compact('document', 'coverage'));
    }

    public function download(string $file): BinaryFileResponse
    {
        abort_unless(bouncer()->hasPermission('configuration'), 403);
        abort_unless(in_array($file, ['openapi.json', 'routes.json', 'coverage.json'], true), 404);
        DocumentationTelemetry::record(event: 'api.download', ctx: ['file' => $file]);

        return response()->download(base_path('docs/api/'.$file), $file, ['Content-Type' => 'application/json']);
    }

    public function show(Request $request, Manual $manual, string $slug): View
    {
        $entry = $manual->find($slug);
        $html = $manual->html($entry);
        DocumentationTelemetry::record(event: 'article.read', ctx: ['slug' => $entry['slug']]);

        return view('admin::help.documentation', ['entry' => $entry, 'html' => $html, 'entries' => $manual->entries()]);
    }
}
