<?php

namespace Mothership\DocumentationExample;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

final class ExampleController
{
    public function index(): View
    {
        abort_unless(bouncer()->hasPermission('documentation-example'), 403);

        return view('documentation-example::index');
    }

    public function data(): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('documentation-example'), 403);

        return response()->json(['message' => 'Extension registered'])->header('Cache-Control', 'private, no-store');
    }
}
