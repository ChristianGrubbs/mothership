<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Templates;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Models\Template;
use Mothership\DAM\Models\TemplateResource;
use Mothership\DAM\Models\TemplateVersion;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Support\TemplateLog;
use Mothership\User\Models\Admin;

/**
 * Template-based asset creation (P8′, D25), the one service behind the Templates pages and the REST API, so both
 * answer every question the same way.
 *
 * Authoring (dam.template.manage): a template starts from a Library image the author can open; its layout is
 * edited in the image editor (template mode) and saved with the editor's own render as the locked layer; slots are
 * drawn on that canvas; publishing freezes the version. Any change to a published template starts a new draft
 * version, so a published version, and every asset made from it, never changes.
 *
 * Filling (dam.template.use): a filler sends slot values for the published version they were shown; TemplateFill
 * re-validates each value, TemplateRenderer composes the PNG, and the result is saved as a NEW asset in a folder
 * the filler may upload to, with lineage naming the template version and every image-slot revision.
 */
class TemplateService
{
    public const PERMISSION_MANAGE = 'dam.template.manage';

    public const PERMISSION_USE = 'dam.template.use';

    public const RECIPE = 'template';

    /** The longest side of a preview PNG; a saved asset is always full size. */
    public const PREVIEW_SIDE = 1200;

    /** The only request fields a fill may carry. Anything else (a layout, a region, a slot list) is refused. */
    private const FILL_FIELDS = ['version', 'values', 'directory_id', 'name'];

    public function __construct(
        private readonly TemplateSchema $schema,
        private readonly ImageEditorSchema $editor,
        private readonly TemplateFill $fill,
        private readonly TemplateRenderer $renderer,
        private readonly ContentStore $store,
        private readonly Lineage $lineage,
        private readonly AssetAccessDecision $access,
    ) {}

    /** The role's answer, for the session's admin or an API key's: a full admin may do everything. */
    public static function may(Admin $admin, string $permission): bool
    {
        $role = $admin->role;

        return $role !== null && ($role->permission_type === 'all' || $admin->hasPermission($permission));
    }

    public static function canManage(Admin $admin): bool
    {
        return self::may(admin: $admin, permission: self::PERMISSION_MANAGE);
    }

    public static function canUse(Admin $admin): bool
    {
        return self::may(admin: $admin, permission: self::PERMISSION_USE);
    }

    /** Refuses an admin with neither permission (403): the Templates surface is not theirs at all. */
    public function authorizeAny(Admin $actor): void
    {
        abort_unless(self::canManage($actor) || self::canUse($actor), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    /**
     * The template when this admin may see it, else the 404 a missing id gets: a manager sees every template, a
     * filler only published ones that are not archived.
     */
    public function find(Admin $viewer, int $id): Template
    {
        $template = Template::query()->find($id);

        abort_unless($template !== null && $this->visibleTo(template: $template, viewer: $viewer), 404);

        return $template;
    }

    private function visibleTo(Template $template, Admin $viewer): bool
    {
        if (self::canManage($viewer)) {
            return true;
        }

        return self::canUse($viewer) && $template->status === Template::STATUS_PUBLISHED && $template->published_version_id !== null;
    }

    /** @return list<array<string, mixed>> */
    public function list(Admin $viewer, ?string $search = null, ?string $status = null): array
    {
        $query = Template::query()->with(['publishedVersion', 'outputDirectory'])->orderBy('name')->orderBy('id');

        if (! self::canManage($viewer)) {
            $query->where('status', Template::STATUS_PUBLISHED)->whereNotNull('published_version_id');
        } elseif ($status !== null && in_array($status, Template::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(description) LIKE ?', [$term]));
        }

        $templates = $query->get();
        $uses = $this->uses(viewer: $viewer, templateIds: $templates->pluck('id')->all());
        $drafts = TemplateVersion::query()->whereIn('template_id', $templates->pluck('id'))->whereNull('published_at')->pluck('version', 'template_id');

        return $templates->map(fn (Template $template) => $this->present(template: $template, viewer: $viewer, counts: ['uses' => (int) ($uses[$template->id] ?? 0), 'draft' => isset($drafts[$template->id]) ? (int) $drafts[$template->id] : null]))->values()->all();
    }

    /**
     * How many assets each template has made that this viewer can list: counts go through the access seam like
     * every other count, so a hidden output is never counted.
     *
     * @param  list<int>  $templateIds
     * @return array<int, int>
     */
    private function uses(Admin $viewer, array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }

        $query = Asset::query()
            ->join('dam_renditions as r', 'r.output_asset_id', '=', 'dam_assets.id')
            ->join('dam_lineage_inputs as i', 'i.rendition_id', '=', 'r.id')
            ->join('dam_template_versions as v', 'v.id', '=', 'i.template_version_id')
            ->where('r.recipe', self::RECIPE)
            ->where('i.role', LineageInput::ROLE_TEMPLATE)
            ->whereIn('v.template_id', $templateIds);
        $this->access->scopeListable($query, AccessContext::forAdmin(admin: $viewer, purpose: AccessContext::PURPOSE_LIST));

        return $query->groupBy('v.template_id')->selectRaw('v.template_id as template_id, COUNT(DISTINCT dam_assets.id) as uses')
            ->pluck('uses', 'template_id')->map(fn ($count) => (int) $count)->all();
    }

    /** @param  array{uses: int, draft: ?int}|null  $counts  what a listing already loaded for every row at once */
    public function present(Template $template, Admin $viewer, ?array $counts = null): array
    {
        $manager = self::canManage($viewer);
        $published = $template->publishedVersion;
        $uses = $counts['uses'] ?? ($this->uses(viewer: $viewer, templateIds: [$template->id])[$template->id] ?? 0);
        $draft = $counts === null
            ? TemplateVersion::query()->where('template_id', $template->id)->whereNull('published_at')->value('version')
            : $counts['draft'];

        $directory = $template->outputDirectory;

        return [
            'id'                => $template->id,
            'name'              => $template->name,
            'description'       => $template->description,
            'status'            => $template->status,
            'published_version' => $published?->version,
            'published_at'      => $published?->published_at?->toIso8601String(),
            'draft_version'     => $manager ? $draft : null,
            'uses'              => $uses,
            'output_directory'  => $directory !== null && (new DirectoryPermissionService)->forAdmin($viewer)->canView($directory->id)
                ? ['id' => $directory->id, 'name' => $directory->name] : null,
            'updated_at'        => $template->updated_at?->toIso8601String(),
            'layer_url'         => $published?->layer_resource_id ? route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $published->layer_resource_id]) : null,
            'use_url'           => $published !== null && $template->status === Template::STATUS_PUBLISHED ? route('admin.dam.templates.use', $template->id) : null,
            'edit_url'          => $manager ? route('admin.dam.templates.edit', $template->id) : null,
        ];
    }

    /**
     * A version as a form or the authoring canvas reads it: the canvas size, the layer, and every slot with its
     * label, region and constraints. A choice option's author image is given as a URL, never as a Library id.
     */
    public function presentVersion(Template $template, TemplateVersion $version): array
    {
        [$width, $height] = $this->schema->canvas($version->document);
        $resource = fn (?string $id) => $id === null ? null : route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $id]);

        return [
            'version'      => $version->version,
            'published'    => $version->isPublished(),
            'published_at' => $version->published_at?->toIso8601String(),
            'revision'     => $version->revision,
            'canvas'       => ['width' => $width, 'height' => $height],
            'layer_url'    => $resource($version->layer_resource_id),
            'slots'        => collect($version->slots())->map(function (array $slot) use ($resource) {
                if ($slot['type'] === 'choice') {
                    $slot['constraints']['options'] = collect($slot['constraints']['options'])
                        ->map(fn (array $option) => [...$option, 'image_url' => $resource($option['resource'] ?? null)])->all();
                }

                return $slot;
            })->values()->all(),
        ];
    }

    /** A template with its versions as the viewer may see them: a manager also gets the draft. */
    public function show(Admin $viewer, Template $template): array
    {
        $published = $template->publishedVersion;
        $draft = self::canManage($viewer) ? TemplateVersion::query()->where('template_id', $template->id)->whereNull('published_at')->first() : null;

        return [
            ...$this->present(template: $template, viewer: $viewer),
            'published' => $published ? $this->presentVersion(template: $template, version: $published) : null,
            'draft'     => $draft ? $this->presentVersion(template: $template, version: $draft) : null,
        ];
    }

    /**
     * Creates a draft template on a Library image the author can open. The image's current revision becomes the
     * template's own base (the same blob, retained by the resource row), so the template keeps it after the asset
     * is changed or deleted.
     */
    public function create(Admin $actor, array $data): Template
    {
        $data = validator($data, [
            'name'                => 'required|string|max:191',
            'description'         => 'nullable|string|max:2000',
            'base_asset_id'       => 'required|integer',
            'output_directory_id' => 'nullable|integer',
        ])->validate();
        $directoryId = $this->outputDirectory(actor: $actor, id: $data['output_directory_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $directoryId): Template {
            $template = Template::query()->create([
                'name'                => $data['name'],
                'description'         => $data['description'] ?? null,
                'status'              => Template::STATUS_DRAFT,
                'owner_id'            => $actor->id,
                'output_directory_id' => $directoryId,
            ]);
            $base = $this->importImage(actor: $actor, template: $template, assetId: (int) $data['base_asset_id'], kind: TemplateResource::KIND_BASE, field: 'base_asset_id');

            TemplateVersion::query()->create([
                'template_id'       => $template->id,
                'version'           => 1,
                'document'          => $this->schema->initial($base->id, $base->width, $base->height),
                // The untouched layout renders as the base image itself, so the base is the first layer.
                'layer_resource_id' => $base->id,
                'revision'          => (string) Str::uuid(),
                'created_by'        => $actor->id,
            ]);

            return $template->refresh();
        });
    }

    /**
     * Makes a Library image the author can open into one of the template's own images (its base, or a choice
     * option's picture). A hidden id fails exactly as a missing one, under $field.
     */
    public function importImage(Admin $actor, Template $template, int $assetId, string $kind, string $field = 'asset_id'): TemplateResource
    {
        $decision = $this->access->for(AccessContext::forAdmin(admin: $actor, purpose: AccessContext::PURPOSE_PREVIEW), $assetId);
        $revision = $decision->visible && $decision->asset?->file_type === 'image' ? $this->lineage->currentRevision(assetId: $assetId)?->loadMissing('fileObject') : null;
        $image = $revision?->fileObject ? $this->renderer->decode(object: $revision->fileObject) : null;

        if (! $revision instanceof AssetFile || $image === null) {
            throw ValidationException::withMessages([$field => trans('dam::app.admin.templates.validation.image-unavailable')]);
        }

        [$width, $height] = [imagesx($image), imagesy($image)];

        return DB::transaction(function () use ($actor, $template, $revision, $kind, $width, $height): TemplateResource {
            // Lock order as the image editor's: the asset row, then the blob, which retain() re-finds or recreates.
            Asset::lockedForGraph()->findOrFail($revision->dam_asset_id);
            $object = $this->store->retain($revision->fileObject);

            return TemplateResource::query()->create([
                'id'                   => (string) Str::uuid(),
                'template_id'          => $template->id,
                'kind'                 => $kind,
                'file_object_id'       => $object->id,
                'width'                => $width,
                'height'               => $height,
                'source_asset_id'      => $revision->dam_asset_id,
                'source_asset_file_id' => $revision->id,
                'created_by'           => $actor->id,
            ]);
        });
    }

    /** A folder id the admin can see, else the same validation error a missing one gets. */
    private function outputDirectory(Admin $actor, mixed $id, string $field = 'output_directory_id'): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        $directory = filter_var($id, FILTER_VALIDATE_INT) === false ? null : Directory::query()->find((int) $id);

        if ($directory === null || ! (new DirectoryPermissionService)->forAdmin($actor)->canView($directory->id)) {
            throw ValidationException::withMessages([$field => trans('validation.exists', ['attribute' => str_replace('_', ' ', $field)])]);
        }

        return $directory->id;
    }

    /** The version an edit lands in: the open draft, or a new one copied from the published version. */
    public function draftFor(Admin $actor, Template $template): TemplateVersion
    {
        $latest = $template->latestVersion();

        if ($latest !== null && ! $latest->isPublished()) {
            return $latest;
        }

        return TemplateVersion::query()->create([
            'template_id'       => $template->id,
            'version'           => ($latest?->version ?? 0) + 1,
            'document'          => $latest?->document,
            'layer_resource_id' => $latest?->layer_resource_id,
            'revision'          => (string) Str::uuid(),
            'created_by'        => $actor->id,
        ]);
    }

    /** Name, description, default folder and, in the draft, the slots. */
    public function update(Admin $actor, Template $template, array $data): Template
    {
        $data = validator($data, [
            'name'                => 'sometimes|required|string|max:191',
            'description'         => 'sometimes|nullable|string|max:2000',
            'output_directory_id' => 'sometimes|nullable|integer',
            'slots'               => 'sometimes|array|max:'.TemplateSchema::MAX_SLOTS,
        ])->validate();

        return DB::transaction(function () use ($actor, $template, $data): Template {
            $template = Template::query()->lockForUpdate()->findOrFail($template->id);
            $attributes = array_intersect_key($data, array_flip(['name', 'description']));

            if (array_key_exists('output_directory_id', $data)) {
                $attributes['output_directory_id'] = $this->outputDirectory(actor: $actor, id: $data['output_directory_id']);
            }

            $template->fill($attributes)->save();

            if (array_key_exists('slots', $data)) {
                $current = $template->latestVersion();
                $document = [...($current?->document ?? []), 'slots' => array_values($data['slots'])];
                $this->schema->validate($document);
                $this->assertOptionImages(template: $template, document: $document);

                // Saving the same slots again is no change, so it opens no new draft.
                if ($current === null || $current->document !== $document) {
                    $draft = $this->draftFor(actor: $actor, template: $template);
                    $draft->fill(['document' => $document])->save();
                }
            }

            return $template->refresh();
        });
    }

    /** A choice option's picture must be one of this template's own images, never a Library or another template's id. */
    private function assertOptionImages(Template $template, array $document): void
    {
        $ids = collect($document['slots'])->where('type', 'choice')
            ->flatMap(fn (array $slot) => collect($slot['constraints']['options'])->pluck('resource'))->filter()->unique();

        if ($ids->isNotEmpty() && TemplateResource::query()->where('template_id', $template->id)->whereIn('id', $ids->all())->count() !== $ids->count()) {
            $this->schema->invalid(trans('dam::app.admin.templates.schema.option-image'));
        }
    }

    /** The image editor's load answer for the draft's locked layout (the editor's own JSON contract). */
    public function layout(Template $template): array
    {
        // The latest version as it stands; saving it is what opens a draft, so loading the editor writes nothing.
        $version = $template->latestVersion();
        abort_if($version === null, 404);
        $locked = $version->document['locked'];
        $ids = array_unique(array_filter([$locked['base'], $locked['background']['image'] ?? null]));

        return [
            'document'      => $locked,
            'revision'      => $version->revision,
            'original_base' => $locked['base'],
            'resources'     => collect($ids)->mapWithKeys(fn (string $id) => [$id => route('admin.dam.templates.resource', ['id' => $template->id, 'resource' => $id])])->all(),
        ];
    }

    /**
     * Saves the editor's locked layout and its render, the template's new locked layer, into the draft. The render
     * must be a PNG of exactly the layout's size, as for an editor save; the base stays the template's base, and
     * slots outside a canvas that shrank are pulled back inside it.
     */
    public function saveLayout(Admin $actor, Template $template, array $locked, string $revision, string $renderPath): array
    {
        $this->editor->validate($locked);
        $bytes = (string) file_get_contents($renderPath);
        $info = @getimagesizefromstring($bytes);
        [$width, $height] = $this->editor->dimensions($locked);
        abort_unless($info && $info[2] === IMAGETYPE_PNG && $info[0] === $width && $info[1] === $height, 422, trans('dam::app.admin.templates.validation.render-size'));

        $object = $this->store->putLocal(disk: Directory::getAssetDisk(), localPath: $renderPath, sha256: hash('sha256', $bytes), mimeType: 'image/png', extension: 'png');

        return DB::transaction(function () use ($actor, $template, $locked, $revision, $object, $width, $height): array {
            $template = Template::query()->lockForUpdate()->findOrFail($template->id);
            // The revision the editor loaded must still be the latest version's, draft or published.
            abort_unless(hash_equals((string) $template->latestVersion()?->revision, $revision), 409, trans('dam::app.admin.templates.validation.stale-layout'));
            $draft = $this->draftFor(actor: $actor, template: $template);

            $document = $draft->document;

            if ($locked['base'] !== $document['locked']['base']) {
                $this->editor->invalid(trans('dam::app.admin.templates.validation.base-changed'));
            }

            $background = $locked['background']['image'] ?? null;

            if ($background !== null && ! TemplateResource::query()->where('template_id', $template->id)->whereKey($background)->exists()) {
                $this->editor->invalid(trans('dam::app.admin.templates.validation.background'));
            }

            $layer = TemplateResource::query()->create([
                'id'             => (string) Str::uuid(),
                'template_id'    => $template->id,
                'kind'           => TemplateResource::KIND_LAYER,
                'file_object_id' => $this->store->retain($object)->id,
                'width'          => $width,
                'height'         => $height,
                'created_by'     => $actor->id,
            ]);
            $document['locked'] = $locked;
            $document['slots'] = array_map(fn (array $slot) => [...$slot, 'region' => self::clamp(region: $slot['region'], width: $width, height: $height)], $document['slots']);
            $this->schema->validate($document);
            $draft->fill(['document' => $document, 'layer_resource_id' => $layer->id, 'revision' => (string) Str::uuid()])->save();
            $template->touch();

            return ['template_id' => $template->id, 'version' => $draft->version, 'revision' => $draft->revision];
        });
    }

    /** @return array{x: float|int, y: float|int, w: float|int, h: float|int} */
    private static function clamp(array $region, int $width, int $height): array
    {
        $x = min(max(0, $region['x']), $width - 1);
        $y = min(max(0, $region['y']), $height - 1);

        return ['x' => $x, 'y' => $y, 'w' => max(1, min($region['w'], $width - $x)), 'h' => max(1, min($region['h'], $height - $y))];
    }

    /**
     * Publishes the open draft as the version fillers get; or, for an archived template whose latest version is
     * already published, offers it again. A version needs at least one slot and a rendered layer.
     */
    public function publish(Admin $actor, Template $template): Template
    {
        return DB::transaction(function () use ($actor, $template): Template {
            $template = Template::query()->lockForUpdate()->findOrFail($template->id);
            $latest = $template->latestVersion();

            if ($latest !== null && $latest->isPublished()) {
                abort_unless($template->status === Template::STATUS_ARCHIVED, 422, trans('dam::app.admin.templates.validation.nothing-to-publish'));
                $template->fill(['status' => Template::STATUS_PUBLISHED])->save();

                return $template;
            }

            abort_if($latest === null || $latest->layer_resource_id === null, 422, trans('dam::app.admin.templates.validation.nothing-to-publish'));
            $this->schema->validate($latest->document);
            $this->assertOptionImages(template: $template, document: $latest->document);

            if ($latest->slots() === []) {
                $this->schema->invalid(trans('dam::app.admin.templates.validation.needs-slot'));
            }

            $latest->fill(['published_at' => now(), 'published_by' => $actor->id])->save();
            $template->fill(['status' => Template::STATUS_PUBLISHED, 'published_version_id' => $latest->id])->save();

            TemplateLog::write(event: 'template.published', ctx: [
                'template_id' => $template->id, 'version' => $latest->version, 'version_id' => $latest->id,
                'slots'       => count($latest->slots()), 'admin_id' => $actor->id,
            ]);

            return $template;
        });
    }

    /** No longer offered to fillers; the assets it made keep their lineage, and publishing offers it again. */
    public function archive(Admin $actor, Template $template): Template
    {
        $template->fill(['status' => Template::STATUS_ARCHIVED])->save();
        TemplateLog::write(event: 'template.archived', ctx: ['template_id' => $template->id, 'admin_id' => $actor->id]);

        return $template;
    }

    /** Only a template that never made an asset can be deleted; one that did keeps its versions for their lineage. */
    public function delete(Admin $actor, Template $template): void
    {
        $used = LineageInput::query()->where('role', LineageInput::ROLE_TEMPLATE)
            ->whereIn('template_version_id', TemplateVersion::query()->where('template_id', $template->id)->select('id'))->exists();

        abort_if($used, 422, trans('dam::app.admin.templates.validation.in-use'));

        // The database cascade removes the versions and resource rows; a published version refuses an Eloquent delete.
        Template::query()->where('id', $template->id)->delete();
        TemplateLog::write(event: 'template.deleted', ctx: ['template_id' => $template->id, 'admin_id' => $actor->id]);
    }

    /**
     * A PNG preview. A filler previews the published version they name; a manager may preview the draft
     * ($draft). Values go through the same checks as a save, so a preview never shows an image the viewer cannot
     * open, and a refusal shows here first; empty slots show their sample or a placeholder.
     */
    public function preview(Admin $actor, string $via, Template $template, array $payload, bool $draft = false): string
    {
        if ($draft && self::canManage($actor)) {
            $version = $template->latestVersion();
        } else {
            abort_unless(self::canUse($actor) && $template->status === Template::STATUS_PUBLISHED, 404);
            $version = $this->pinned(template: $template, payload: $payload);
        }

        abort_if($version?->layer === null, 404);
        $values = $payload['values'] ?? [];

        if (! is_array($values)) {
            throw TemplateRefused::because(reason: TemplateRefused::INVALID_VALUE, ctx: ['template_id' => $template->id]);
        }

        $resolved = $this->fill->resolve(version: $version, values: $values, actor: $actor, via: $via, preview: true);
        $layer = $version->layer->fileObject;
        abort_if($layer === null, 404);

        return $this->renderer->render(layer: $layer, slots: $resolved['slots'], maxSide: self::PREVIEW_SIDE);
    }

    /** The published version the request names; another (the template was republished meanwhile) is a 409. */
    private function pinned(Template $template, array $payload): TemplateVersion
    {
        $published = $template->publishedVersion;
        abort_if($published === null, 404);
        $named = filter_var($payload['version'] ?? null, FILTER_VALIDATE_INT);

        if ($named === false || $named !== $published->version) {
            throw TemplateRefused::because(reason: TemplateRefused::VERSION_CHANGED, ctx: ['template_id' => $template->id, 'version' => $published->version], status: 409);
        }

        return $published;
    }

    /**
     * Fills the published version and saves the render as a NEW asset (never changing another), in the folder the
     * request names or the template's default. The filler must be allowed to upload there. The new asset records,
     * on its own lineage rendition, the template version (input `template`, with the layer's checksum), each
     * image-slot revision and author option image, and the slot values, so it can be re-opened as a new fill.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $files  the request's uploads: any at all is refused
     */
    public function createAsset(Admin $actor, string $via, Template $template, array $payload, array $files = []): array
    {
        abort_unless(self::canUse($actor), 403, trans('dam::app.admin.permissions.unauthorized'));
        abort_unless($template->status === Template::STATUS_PUBLISHED, 404);
        $ctx = ['template_id' => $template->id];

        if ($files !== [] || collect($payload)->flatten()->contains(fn ($value) => $value instanceof UploadedFile)) {
            throw TemplateRefused::because(reason: TemplateRefused::UPLOAD_NOT_ACCEPTED, ctx: $ctx);
        }

        // Values only: a request that also carries a layout, a region or a slot list is refused, not ignored.
        if (array_diff(array_keys($payload), [...self::FILL_FIELDS, '_token']) !== []) {
            throw TemplateRefused::because(reason: TemplateRefused::UNKNOWN_FIELD, ctx: $ctx);
        }
        $version = $this->pinned(template: $template, payload: $payload);
        $ctx['version'] = $version->version;

        $data = validator($payload, ['values' => 'present|array', 'name' => 'nullable|string|max:150', 'directory_id' => 'nullable|integer'])->validate();
        $directory = $this->destination(actor: $actor, template: $template, id: $data['directory_id'] ?? null);
        $resolved = $this->fill->resolve(version: $version, values: $data['values'], actor: $actor, via: $via);
        $layer = $version->layer?->fileObject;

        if (! $layer instanceof FileObject) {
            throw TemplateRefused::because(reason: TemplateRefused::RENDER_FAILED, ctx: $ctx);
        }

        $bytes = $this->renderer->render(layer: $layer, slots: $resolved['slots']);
        $object = $this->ingest(bytes: $bytes);
        [$width, $height] = $this->schema->canvas($version->document);
        $name = trim((string) ($data['name'] ?? '')) !== '' ? trim((string) $data['name']) : $template->name;

        $asset = $this->publishAsset(actor: $actor, template: $template, version: $version, directoryId: $directory->id, object: $object, name: $name, size: ['width' => $width, 'height' => $height], resolved: $resolved);

        TemplateLog::write(event: 'template.asset_created', ctx: [
            ...$ctx, 'version_id' => $version->id, 'asset_id' => $asset->id, 'directory_id' => $directory->id,
            'admin_id'            => $actor->id, 'via' => $via, 'image_inputs' => count($resolved['inputs']), 'slots_filled' => count($resolved['values']),
        ]);

        return [
            'asset_id' => $asset->id,
            'name'     => $asset->file_name,
            'url'      => route('admin.dam.assets.edit', $asset->id),
            'template' => ['id' => $template->id, 'version' => $version->version],
        ];
    }

    /** The folder an output goes to: named, else the template's default; the filler must be able to upload there. */
    private function destination(Admin $actor, Template $template, mixed $id): Directory
    {
        $id ??= $template->output_directory_id;

        if ($id === null) {
            throw ValidationException::withMessages(['directory_id' => trans('dam::app.admin.templates.validation.folder-required')]);
        }

        $permissions = (new DirectoryPermissionService)->forAdmin($actor);
        $directory = Directory::query()->find((int) $id);

        // A folder the filler cannot see fails exactly as a missing one (VisibleDirectory's rule).
        if ($directory === null || ! $permissions->canView($directory->id)) {
            throw ValidationException::withMessages(['directory_id' => trans('validation.exists', ['attribute' => 'directory id'])]);
        }

        abort_unless(self::may(admin: $actor, permission: 'dam.asset.upload') && ($permissions->bypass() || $permissions->canAccess($directory->id)), 403, trans('dam::app.admin.templates.validation.folder-forbidden'));

        return $directory;
    }

    /** The render as a blob, committed in its own short transaction (ADR 0009 slice 3) like an editor save's. */
    private function ingest(string $bytes): FileObject
    {
        $temp = tempnam(sys_get_temp_dir(), 'dam_template_');
        file_put_contents($temp, $bytes);

        try {
            return $this->store->putLocal(disk: Directory::getAssetDisk(), localPath: $temp, sha256: hash('sha256', $bytes), mimeType: 'image/png', extension: 'png');
        } finally {
            @unlink($temp);
        }
    }

    /** @param  array{width: int, height: int}  $size */
    private function publishAsset(Admin $actor, Template $template, TemplateVersion $version, int $directoryId, FileObject $object, string $name, array $size, array $resolved): Asset
    {
        $writer = app(AccessListWriter::class);

        // ADR 0010's lock order, as the editor's save-as-new and a batch's new asset: the access model, the tree
        // lock, the destination folder FOR SHARE, then the new asset's rows and the graph. It takes that folder's list.
        return $writer->fencedAndTreeLocked(function () use ($writer, $actor, $template, $version, $directoryId, $object, $name, $size, $resolved): Asset {
            $writer->holdPlacementFolders(assetIds: [], directoryIds: [$directoryId]);
            $directory = Directory::query()->findOrFail($directoryId);
            $folderPath = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath();
            $stem = trim(str_replace(['/', '\\', ':'], ['-', '-', '.'], $name)) ?: 'template';
            $fileName = LibraryPaths::uniqueFileName($folderPath, mb_substr($stem, 0, 150).'.png');

            $asset = new Asset;
            $asset->fill([
                'path'       => $folderPath.'/'.$fileName,
                'file_name'  => $fileName,
                'file_type'  => 'image',
                'extension'  => 'png',
                'mime_type'  => 'image/png',
                'file_size'  => (int) $object->size,
                'title'      => $name,
                'created_by' => $actor->id,
                // Provenance on the asset itself: which template and version made it.
                'meta_data'  => [...$size, 'source' => 'template:'.$template->id.'@'.$version->version, 'template' => ['id' => $template->id, 'version' => $version->version]],
            ]);
            $asset->withGraphContent($object);
            $asset->save();
            $asset->directories()->attach($directoryId);
            $writer->inheritAssets([$asset->id]);

            // The output is its own entry, so its lineage rendition sits on it: deleting a source image or retiring
            // the template never erases what this asset was made from.
            $this->lineage->record(
                assetId: $asset->id,
                recipe: self::RECIPE,
                source: null,
                worker: 'template',
                outputAssetId: $asset->id,
                parameters: ['template_id' => $template->id, 'version' => $version->version, 'version_id' => $version->id, 'values' => $resolved['values']],
                inputs: [
                    ['role' => LineageInput::ROLE_TEMPLATE, 'asset_file_id' => null, 'sha256' => $version->layer?->fileObject?->sha256, 'template_version_id' => $version->id],
                    ...$resolved['inputs'],
                ],
            );

            return $asset;
        });
    }

    /**
     * The values an asset was filled with, to start a new fill from them ("Re-open from template"), when the
     * viewer can open that asset and it was made from this template. Null otherwise, never telling which.
     *
     * @return array{asset_id: int, version: int, values: array<string, mixed>}|null
     */
    public function prefill(Admin $viewer, Template $template, int $assetId): ?array
    {
        if (! $this->access->for(AccessContext::forAdmin(admin: $viewer), $assetId)->visible) {
            return null;
        }

        $rendition = Rendition::query()->where('dam_asset_id', $assetId)->where('output_asset_id', $assetId)->where('recipe', self::RECIPE)->first();
        $parameters = $rendition?->parameters ?? [];

        if ((int) ($parameters['template_id'] ?? 0) !== $template->id) {
            return null;
        }

        return ['asset_id' => $assetId, 'version' => (int) $parameters['version'], 'values' => (array) ($parameters['values'] ?? [])];
    }

    /** A template image's blob, for the viewer: a manager any of the template's, a filler only what the published version shows. */
    public function resource(Admin $viewer, Template $template, string $resourceId): TemplateResource
    {
        $resource = TemplateResource::query()->where('template_id', $template->id)->with('fileObject')->find($resourceId);
        abort_if($resource?->fileObject === null, 404);

        if (! self::canManage($viewer)) {
            $published = $template->publishedVersion;
            $shown = collect($published?->slots() ?? [])->where('type', 'choice')
                ->flatMap(fn (array $slot) => collect($slot['constraints']['options'])->pluck('resource'))->filter()
                ->push($published?->layer_resource_id);

            abort_unless($shown->contains($resource->id), 404);
        }

        return $resource;
    }
}
