<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Vectorization\AgentVectorizations;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * REST twin of the Library's Convert menu (filed) and the Home Image Vectorization tool (one-off): the same jobs,
 * allowance, replay and 20 MiB / 33,554,432-pixel limits. The api.scope middleware checks each route's
 * api.dam.vectorizations.* scope; a conversion belongs to the key's admin and is readable only by that admin.
 * Contract: docs/ops/image-vectorization.md.
 */
class VectorizationController extends Controller
{
    public function __construct(private AgentVectorizations $vectorizations) {}

    public function storeForAsset(Request $request, int $asset): JsonResponse
    {
        $data = $request->validate([
            'format'     => ['required', 'in:svg,pdf'],
            'request_id' => ['sometimes', 'uuid'],
        ]);
        $row = $this->vectorizations->fromAsset(actor: $this->admin(), via: AccessContext::VIA_API, assetId: $asset, format: $data['format'], requestId: $data['request_id'] ?? null);

        return $this->respond(row: $row, status: 202);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'       => ['required', 'file', 'mimes:png,jpg,jpeg,webp,bmp,gif,tif,tiff', 'max:20480'],
            // A one-off always yields both formats; format is accepted so callers may say which one they want.
            'format'     => ['sometimes', 'in:svg,pdf'],
            'request_id' => ['sometimes', 'uuid'],
        ]);
        $row = $this->vectorizations->fromFile(actor: $this->admin(), path: (string) $request->file('file')->getRealPath(), requestId: $data['request_id'] ?? null);

        return $this->respond(row: $row, status: 202);
    }

    public function show(string $vectorization): JsonResponse
    {
        return $this->respond(row: $this->vectorizations->find(actor: $this->admin(), via: AccessContext::VIA_API, id: $vectorization), status: 200);
    }

    public function file(string $vectorization, string $format): BinaryFileResponse
    {
        $row = $this->vectorizations->find(actor: $this->admin(), via: AccessContext::VIA_API, id: $vectorization);
        $path = $this->vectorizations->resultPath(row: $row, format: $format);

        return response()->download($path, 'vectorized-'.$row->id.'.'.$format, [
            'Content-Type'            => $format === 'svg' ? 'image/svg+xml' : 'application/pdf',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
        ])->setPrivate();
    }

    private function respond(object $row, int $status): JsonResponse
    {
        $result = $this->vectorizations->present(row: $row);
        $result['downloads'] = $result['formats'] === [] ? null : [
            'svg' => route('admin.api.dam.vectorizations.file', ['vectorization' => $result['id'], 'format' => 'svg']),
            'pdf' => route('admin.api.dam.vectorizations.file', ['vectorization' => $result['id'], 'format' => 'pdf']),
        ];

        return response()->json(['success' => true, 'data' => ['vectorization' => $result]], $status)
            ->header('Cache-Control', 'private, no-store');
    }

    private function admin(): Admin
    {
        /** @var Admin */
        return auth()->guard('api')->user();
    }
}
