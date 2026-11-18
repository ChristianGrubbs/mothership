<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\TemplateResource;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Templates\TemplateService;
use Mothership\User\Models\Admin;

/**
 * REST twin of the Templates pages (P8′, D25): the same TemplateService, so the same visibility, validation,
 * refusal codes and lineage. The api.scope middleware checks each route's own scope (api.dam.templates.*); the
 * key's admin must also hold dam.template.use to fill and dam.template.manage to author, as on the web.
 * Editing the locked layout needs the image editor's render, so it is web-only; slots, metadata, publishing and
 * filling are here.
 */
class TemplateController extends Controller
{
    public function __construct(protected TemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $this->templates->authorizeAny($this->admin());

        return $this->ok(['templates' => $this->templates->list(viewer: $this->admin(), search: $request->string('search')->toString(), status: $request->input('status'))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->templates->authorizeAny($this->admin());
        $template = $this->templates->find(viewer: $this->admin(), id: $id);
        $from = $request->integer('from');

        return $this->ok([
            'template' => $this->templates->show(viewer: $this->admin(), template: $template),
            'prefill'  => $from > 0 ? $this->templates->prefill(viewer: $this->admin(), template: $template, assetId: $from) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->create(actor: $this->admin(), data: $request->all());

        return $this->ok(['template' => $this->templates->show(viewer: $this->admin(), template: $template)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->update(actor: $this->admin(), template: $this->templates->find(viewer: $this->admin(), id: $id), data: $request->all());

        return $this->ok(['template' => $this->templates->show(viewer: $this->admin(), template: $template)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeManage();
        $this->templates->delete(actor: $this->admin(), template: $this->templates->find(viewer: $this->admin(), id: $id));

        return $this->ok([]);
    }

    public function publish(int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->publish(actor: $this->admin(), template: $this->templates->find(viewer: $this->admin(), id: $id));

        return $this->ok(['template' => $this->templates->show(viewer: $this->admin(), template: $template)]);
    }

    public function archive(int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->archive(actor: $this->admin(), template: $this->templates->find(viewer: $this->admin(), id: $id));

        return $this->ok(['template' => $this->templates->show(viewer: $this->admin(), template: $template)]);
    }

    public function importImage(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->find(viewer: $this->admin(), id: $id);
        $request->validate(['asset_id' => 'required|integer']);
        $resource = $this->templates->importImage(actor: $this->admin(), template: $template, assetId: $request->integer('asset_id'), kind: TemplateResource::KIND_OPTION);

        return $this->ok(['id' => $resource->id, 'width' => $resource->width, 'height' => $resource->height], 201);
    }

    public function createAsset(Request $request, int $id): JsonResponse
    {
        $template = $this->templates->find(viewer: $this->admin(), id: $id);
        $asset = $this->templates->createAsset(actor: $this->admin(), via: AccessContext::VIA_API, template: $template, payload: $request->all(), files: $request->allFiles());

        return $this->ok(['asset' => $asset], 201);
    }

    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function authorizeManage(): void
    {
        abort_unless(TemplateService::canManage($this->admin()), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    private function admin(): Admin
    {
        /** @var Admin */
        return auth()->guard('api')->user();
    }
}
