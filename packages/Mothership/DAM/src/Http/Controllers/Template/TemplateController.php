<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Template;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Template;
use Mothership\DAM\Models\TemplateResource;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\DAM\Services\Templates\TemplateService;
use Mothership\User\Models\Admin;

/**
 * The Templates pages (P8′, D25): the list, the authoring page and the image editor in template mode
 * (dam.template.manage), and "New from template" (dam.template.use). Every answer comes from TemplateService, the
 * same as the REST API's; the route ACL gates each route and the service checks the role again.
 */
class TemplateController extends Controller
{
    public function __construct(
        protected TemplateService $templates,
        protected ImageEditorSchema $editor,
    ) {}

    public function index(): View
    {
        $this->templates->authorizeAny($this->actor());

        return view('dam::templates.index', [
            'canManage' => TemplateService::canManage($this->actor()),
            'canUse'    => TemplateService::canUse($this->actor()),
            'folderId'  => request()->integer('directory_id') ?: null,
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $this->templates->authorizeAny($this->actor());

        return response()->json(['templates' => $this->templates->list(viewer: $this->actor(), search: $request->string('search')->toString(), status: $request->input('status'))]);
    }

    public function show(int $id): JsonResponse
    {
        $this->templates->authorizeAny($this->actor());

        return response()->json(['template' => $this->templates->show(viewer: $this->actor(), template: $this->templates->find(viewer: $this->actor(), id: $id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->create(actor: $this->actor(), data: $request->all());

        return response()->json(['template' => $this->templates->show(viewer: $this->actor(), template: $template)], 201);
    }

    public function edit(int $id): View
    {
        $this->authorizeManage();
        $template = $this->templates->find(viewer: $this->actor(), id: $id);

        return view('dam::templates.edit', ['template' => $this->templates->show(viewer: $this->actor(), template: $template)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->update(actor: $this->actor(), template: $this->templates->find(viewer: $this->actor(), id: $id), data: $request->all());

        return response()->json(['template' => $this->templates->show(viewer: $this->actor(), template: $template)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeManage();
        $this->templates->delete(actor: $this->actor(), template: $this->templates->find(viewer: $this->actor(), id: $id));

        return response()->json(['message' => trans('dam::app.admin.templates.deleted')]);
    }

    public function publish(int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->publish(actor: $this->actor(), template: $this->templates->find(viewer: $this->actor(), id: $id));

        return response()->json(['template' => $this->templates->show(viewer: $this->actor(), template: $template), 'message' => trans('dam::app.admin.templates.published')]);
    }

    public function archive(int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->archive(actor: $this->actor(), template: $this->templates->find(viewer: $this->actor(), id: $id));

        return response()->json(['template' => $this->templates->show(viewer: $this->actor(), template: $template), 'message' => trans('dam::app.admin.templates.archived')]);
    }

    /** A Library image the author picked for a choice option, made into one of the template's own images. */
    public function importImage(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->find(viewer: $this->actor(), id: $id);
        $request->validate(['asset_id' => 'required|integer']);
        $resource = $this->templates->importImage(actor: $this->actor(), template: $template, assetId: $request->integer('asset_id'), kind: TemplateResource::KIND_OPTION);

        return response()->json(['id' => $resource->id, 'width' => $resource->width, 'height' => $resource->height, 'url' => route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $resource->id])], 201);
    }

    /** The image editor's load request in template mode (its own JSON contract). */
    public function layout(int $id): JsonResponse
    {
        $this->authorizeManage();

        return response()->json($this->templates->layout(template: $this->templates->find(viewer: $this->actor(), id: $id)));
    }

    /** The image editor's save in template mode: the locked layout and its render, into the draft version. */
    public function layoutSave(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage();
        $template = $this->templates->find(viewer: $this->actor(), id: $id);
        $request->validate(['revision' => 'required|uuid', 'mode' => 'required|in:version,new', 'document' => 'required|string|max:1000000', 'render' => 'required|file|max:49152']);

        try {
            $locked = json_decode($request->input('document'), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->editor->invalid('Invalid document JSON.');
        }

        if (! is_array($locked)) {
            $this->editor->invalid('Invalid document.');
        }

        return response()->json($this->templates->saveLayout(
            actor: $this->actor(),
            template: $template,
            locked: $locked,
            revision: $request->input('revision'),
            renderPath: $request->file('render')->getRealPath(),
        ));
    }

    /** A template image (its layer, base, background or an option picture), same origin so the editor canvas stays clean. */
    public function resource(int $id, string $resource): Response|\Symfony\Component\HttpFoundation\Response
    {
        $this->templates->authorizeAny($this->actor());
        $template = $this->templates->find(viewer: $this->actor(), id: $id);
        $row = $this->templates->resource(viewer: $this->actor(), template: $template, resourceId: $resource);

        return app(ContentStore::class)->deliver(object: $row->fileObject, filename: $row->id, headers: ['Cache-Control' => 'private, no-store'], sameOrigin: true);
    }

    /** The author's preview of the draft, with each slot's sample (or the values sent). */
    public function preview(Request $request, int $id): Response
    {
        $this->authorizeManage();
        $png = $this->templates->preview(actor: $this->actor(), via: AccessContext::VIA_LIBRARY, template: $this->templates->find(viewer: $this->actor(), id: $id), payload: $request->all(), draft: true);

        return $this->png($png);
    }

    /** "New from template": the fill form beside its live preview. */
    public function use(Request $request, int $id): View
    {
        $template = $this->templates->find(viewer: $this->actor(), id: $id);
        abort_unless(TemplateService::canUse($this->actor()) && $template->status === Template::STATUS_PUBLISHED, 404);
        $from = $request->integer('from');
        // "New from template" on a folder: that folder, when the filler can see it; else the template's own.
        $folderId = $request->integer('directory_id');
        $folder = $folderId > 0 && (new DirectoryPermissionService)->forAdmin($this->actor())->canView($folderId)
            ? Directory::query()->whereKey($folderId)->first(['id', 'name']) : null;

        return view('dam::templates.use', [
            'template'    => $this->templates->show(viewer: $this->actor(), template: $template),
            'prefill'     => $from > 0 ? $this->templates->prefill(viewer: $this->actor(), template: $template, assetId: $from) : null,
            'folder'      => $folder ? ['id' => $folder->id, 'name' => $folder->name] : null,
            'canUpload'   => TemplateService::may(admin: $this->actor(), permission: 'dam.asset.upload'),
            'canBrowse'   => TemplateService::may(admin: $this->actor(), permission: 'dam.directory.index'),
            'canThumbs'   => TemplateService::may(admin: $this->actor(), permission: 'dam.asset_assign'),
        ]);
    }

    /** The filler's live preview of the published version, with the values so far. */
    public function usePreview(Request $request, int $id): Response
    {
        $png = $this->templates->preview(actor: $this->actor(), via: AccessContext::VIA_LIBRARY, template: $this->templates->find(viewer: $this->actor(), id: $id), payload: $request->all());

        return $this->png($png);
    }

    public function createAsset(Request $request, int $id): JsonResponse
    {
        $template = $this->templates->find(viewer: $this->actor(), id: $id);
        $asset = $this->templates->createAsset(actor: $this->actor(), via: AccessContext::VIA_LIBRARY, template: $template, payload: $request->all(), files: $request->allFiles());

        return response()->json(['asset' => $asset, 'message' => trans('dam::app.admin.templates.use.created')], 201);
    }

    private function png(string $bytes): Response
    {
        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizeManage(): void
    {
        abort_unless(TemplateService::canManage($this->actor()), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    private function actor(): Admin
    {
        /** @var Admin */
        return auth()->guard('admin')->user();
    }
}
