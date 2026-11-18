<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Analytics\UploadEvents;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\HubResource;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CreativeHub\CreativeHubLog;
use Mothership\DAM\Services\CreativeHub\CreativeHubSaver;
use Mothership\DAM\Services\CreativeHub\HubResourceInspector;
use Mothership\DAM\Services\CreativeHub\HubResourceRefused;
use Mothership\DAM\Services\CreativeHub\HubResourceStore;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP layer of the Hub resource library (ADR 0015): fonts and brushes people upload for the Creative Hub apps.
 *
 * - Every route is behind `dam.hub.enabled` (404 while off) and the `dam.hub.resources` ACL key, which every non-API
 *   user holds (Admin::hasPermission). Sharing also needs `dam.hub.resources.share` and a license attestation; a new
 *   upload by someone holding it is shared with Everyone at once, the upload being the attestation (store()).
 * - A resource the viewer may not read answers like a missing one (404), on every route. Reading is
 *   HubResource::scopeVisibleTo(): owner, Everyone, a listed group, or a full admin.
 * - Changing or deleting a resource is its owner's alone (403 for a reader who is not the owner).
 * - Bytes leave only through bytes(): same origin, as an attachment, pinned to the sha256 the listing gave. A hub app
 *   gets them from the workspace by postMessage (ADR 0012), never by fetching this route itself.
 * - Every HubResourceRefused becomes a translated 422 (403 for `not_owner`), with its stable reason code.
 */
class HubResourceController extends Controller
{
    /** The license note recorded when an upload is shared with Everyone as it is stored. */
    public const UPLOAD_LICENSE_NOTE = 'Shared on upload';

    /** The viewer lacks dam.hub.resources.share: a 422, like the other sharing refusals. */
    private const SHARE_NOT_ALLOWED = 'share_not_allowed';

    private const REASON_MESSAGES = [
        HubResourceRefused::EMPTY            => 'empty',
        HubResourceRefused::TOO_LARGE        => 'too-large',
        HubResourceRefused::FORBIDDEN        => 'forbidden',
        HubResourceRefused::UNKNOWN_FORMAT   => 'unknown-format',
        HubResourceRefused::UNPARSED         => 'unparsed',
        HubResourceRefused::COLLECTION       => 'collection',
        HubResourceRefused::BAD_BRUSH        => 'bad-brush',
        HubResourceRefused::QUOTA_BYTES      => 'quota-bytes',
        HubResourceRefused::LICENSE_REQUIRED => 'license-required',
        HubResourceRefused::BAD_VISIBILITY   => 'bad-visibility',
        HubResourceRefused::NOT_OWNER        => 'not-owner',
        self::SHARE_NOT_ALLOWED              => 'share-not-allowed',
    ];

    /** Browsers that send Sec-Fetch-Site: only a request from this origin, or one the person typed, gets bytes. */
    private const BYTES_FETCH_SITES = ['same-origin', 'none'];

    public function __construct(
        private HubResourceStore $store,
    ) {}

    /**
     * The Fonts & brushes manager page (PR3). Its data comes from list(); the page itself carries only the groups the
     * viewer may share with (shareableGroups(), never the whole group directory) and the caps.
     * The route is not in acl.php, so the dam.hub.resources check is here.
     */
    public function index(): View
    {
        $this->enabled();
        abort_unless((bool) bouncer()->hasPermission('dam.hub.resources'), 403);

        return view('dam::creative-hub.resources', [
            'config' => [
                'urls'     => [
                    'list'   => route('admin.dam.hub.resources.list'),
                    'store'  => route('admin.dam.hub.resources.store'),
                ],
                'groups'   => $this->shareableGroups($this->admin()),
                'canShare' => (bool) bouncer()->hasPermission('dam.hub.resources.share'),
                'limits'   => [
                    'font'  => (int) config('dam.hub.resources.font_max_bytes'),
                    'brush' => (int) config('dam.hub.resources.brush_max_bytes'),
                    'quota' => (int) config('dam.hub.resources.per_user_max_bytes'),
                ],
            ],
        ]);
    }

    /** The resources the viewer may read, as metadata and a pinned bytes URL; never the bytes themselves. */
    public function list(Request $request): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'kind' => ['nullable', 'in:'.HubResourceInspector::KIND_FONT.','.HubResourceInspector::KIND_BRUSH],
        ]);

        $admin = $this->admin();
        $resources = HubResource::query()
            ->visibleTo($admin)
            ->when(isset($data['kind']), fn ($query) => $query->where('kind', $data['kind']))
            ->with(['fileObject:id,sha256', 'owner:id,name'])
            ->orderBy('kind')
            ->orderByRaw('LOWER(COALESCE(family, name))')
            ->orderBy('weight')
            ->orderBy('id')
            ->get();

        $groupIds = $this->groupIdsFor(resources: $resources->filter(fn (HubResource $resource): bool => $this->owns(resource: $resource, admin: $admin))->all());

        return response()->json([
            'resources' => $resources
                ->map(fn (HubResource $resource): array => $this->present(resource: $resource, admin: $admin, groupIds: $groupIds[(int) $resource->id] ?? []))
                ->values(),
            'usage'     => [
                'bytes' => (int) HubResource::query()->where('owner_id', $admin->getKey())->sum('size'),
                'limit' => (int) config('dam.hub.resources.per_user_max_bytes'),
            ],
        ]);
    }

    /**
     * Uploads one font or brush for the viewer, shared with Everyone when they may share (else private). 201 when
     * stored, 200 when they already held it.
     */
    public function store(Request $request): JsonResponse
    {
        $this->enabled();
        $request->validate(['file' => ['required', 'file']]);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        // The inspector holds the exact per-kind caps. A file over the largest of them is refused here, before it is
        // read, with the same too_large answer the inspector gives.
        $largest = max((int) config('dam.hub.resources.font_max_bytes'), (int) config('dam.hub.resources.brush_max_bytes'));
        $size = (int) $file->getSize();
        // P1b: one resource file is one upload with one attempt and one terminal outcome; no folder holds it.
        $uploadEvents = UploadEvents::start(path: 'hub', directoryId: null);

        if ($size > $largest) {
            $uploadEvents->failed(errorCode: HubResourceRefused::TOO_LARGE);

            return $this->refusal(refused: new HubResourceRefused(
                reason: HubResourceRefused::TOO_LARGE,
                message: 'The file is over the largest resource cap.',
                context: ['size' => $size, 'limit' => $largest],
            ));
        }

        $admin = $this->admin();

        try {
            ['resource' => $resource, 'created' => $created] = $this->store->store(
                path: (string) $file->getRealPath(),
                originalName: (string) $file->getClientOriginalName(),
                owner: $admin,
            );
        } catch (HubResourceRefused $refused) {
            $uploadEvents->failed(errorCode: $refused->reason);

            return $this->refusal(refused: $refused);
        } catch (\Throwable $error) {
            $uploadEvents->failed(errorCode: 'publish_failed');

            throw $error;
        }
        $uploadEvents->succeeded();

        // A new upload is shared with Everyone at once when its owner may share, and the upload is the license
        // attestation (ADR 0015 amendment 2026-10-20). A repeat of bytes the owner already holds keeps its sharing.
        if ($created && (bool) bouncer()->hasPermission('dam.hub.resources.share')) {
            try {
                $resource = $this->store->setVisibility(
                    resource: $resource,
                    actor: $admin,
                    visibility: HubResource::VISIBILITY_EVERYONE,
                    licenseNote: self::UPLOAD_LICENSE_NOTE,
                    licenseAttested: true,
                );
            } catch (HubResourceRefused) {
                // Stored but not shared: it stays private, and its owner can share it from the page.
            }
        }

        $resource->load(['fileObject:id,sha256', 'owner:id,name']);

        return response()->json(
            ['resource' => $this->present(resource: $resource, admin: $admin, groupIds: $resource->groupIds()), 'created' => $created],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * The owner renames a resource, sets who sees it, or edits its license note. Sharing (Everyone or groups) needs
     * `dam.hub.resources.share`, `license_attested` and a non-empty `license_note` in this request, else 422. Every
     * change in one request lands together or not at all, decided against the row as locked in that transaction.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->enabled();
        $admin = $this->admin();
        $resource = $this->readable(uuid: $uuid, admin: $admin);
        $data = $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:255'],
            'visibility'       => ['sometimes', 'required', 'string', 'in:'.implode(',', HubResource::VISIBILITIES)],
            'group_ids'        => ['sometimes', 'array', 'max:100'],
            'group_ids.*'      => ['integer', 'min:1'],
            'license_note'     => ['sometimes', 'nullable', 'string', 'max:2000'],
            'license_attested' => ['sometimes', 'boolean'],
        ]);

        abort_if(array_intersect_key($data, array_flip(['name', 'visibility', 'group_ids', 'license_note'])) === [], 422, trans('dam::app.admin.hub-resources.errors.nothing-to-change'));

        if (! $this->owns(resource: $resource, admin: $admin)) {
            return $this->refusal(refused: new HubResourceRefused(reason: HubResourceRefused::NOT_OWNER, message: 'Only the owner can change this resource.'));
        }

        try {
            DB::transaction(function () use ($resource, $admin, $data): void {
                // Every rule reads the row as it stands under the lock, never as this request first read it: a share
                // committed in between must make a note change here a re-share, so a shared resource never loses its note.
                $locked = HubResource::query()->whereKey($resource->getKey())->lockForUpdate()->first();
                abort_if($locked === null, 404);

                $visibility = $data['visibility'] ?? (string) $locked->visibility;
                // Any change to who sees a shared resource, or to its license note, is a new share and is attested again.
                $reshare = $visibility !== HubResource::VISIBILITY_PRIVATE
                    && (isset($data['visibility']) || array_key_exists('group_ids', $data) || array_key_exists('license_note', $data));

                if ($reshare && ! (bool) bouncer()->hasPermission('dam.hub.resources.share')) {
                    throw new HubResourceRefused(reason: self::SHARE_NOT_ALLOWED, message: 'Sharing needs dam.hub.resources.share.');
                }

                // A new group needs the owner's membership now (or a full admin); a group already on this resource may stay.
                if ($reshare && isset($data['group_ids'])) {
                    $allowed = [...$this->attachableGroupIds($admin), ...$locked->groupIds()];

                    if (array_diff(array_map('intval', $data['group_ids']), $allowed) !== []) {
                        throw new HubResourceRefused(reason: HubResourceRefused::BAD_VISIBILITY, message: 'A listed group is not one the owner may share with.');
                    }
                }

                if (isset($data['name'])) {
                    $locked->update(['name' => CreativeHubSaver::cleanName((string) $data['name'])]);
                }

                if ($reshare) {
                    $this->store->setVisibility(
                        resource: $locked,
                        actor: $admin,
                        visibility: $visibility,
                        groupIds: array_map('intval', $data['group_ids'] ?? ($visibility === HubResource::VISIBILITY_GROUPS ? $locked->groupIds() : [])),
                        licenseNote: isset($data['license_note']) ? (string) $data['license_note'] : null,
                        licenseAttested: (bool) ($data['license_attested'] ?? false),
                    );
                } elseif (isset($data['visibility'])) {
                    $this->store->setVisibility(resource: $locked, actor: $admin, visibility: HubResource::VISIBILITY_PRIVATE);
                }

                // Only a resource that is private under the lock (or made private by this request) takes a plain owner
                // note: nothing is shared, so nothing is attested.
                if (! $reshare && array_key_exists('license_note', $data)) {
                    $note = trim((string) $data['license_note']);
                    HubResource::query()->whereKey($locked->getKey())->where('visibility', HubResource::VISIBILITY_PRIVATE)
                        ->update(['license_note' => $note === '' ? null : $note]);
                }
            });
        } catch (HubResourceRefused $refused) {
            return $this->refusal(refused: $refused);
        }

        $fresh = HubResource::query()->with(['fileObject:id,sha256', 'owner:id,name'])->findOrFail($resource->getKey());

        return response()->json(['resource' => $this->present(resource: $fresh, admin: $admin, groupIds: $fresh->groupIds())]);
    }

    /** The owner deletes a resource; its blob is released when nothing else holds it. */
    public function destroy(string $uuid): JsonResponse
    {
        $this->enabled();
        $admin = $this->admin();
        $resource = $this->readable(uuid: $uuid, admin: $admin);

        if (! $this->owns(resource: $resource, admin: $admin)) {
            return $this->refusal(refused: new HubResourceRefused(reason: HubResourceRefused::NOT_OWNER, message: 'Only the owner can delete this resource.'));
        }

        try {
            $this->store->delete(resource: $resource, actor: $admin);
        } catch (HubResourceRefused $refused) {
            return $this->refusal(refused: $refused);
        }

        return response()->json(['message' => trans('dam::app.admin.hub-resources.deleted')]);
    }

    /**
     * A readable resource's bytes, from this origin only, as an attachment, pinned to `sha256` (409 when the resource
     * holds other bytes). A cross-site or same-site fetch (a hub app's own origin) is refused before any lookup.
     */
    public function bytes(Request $request, string $uuid, ContentStore $contentStore): Response
    {
        $this->enabled();
        $site = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));
        abort_if($site !== '' && ! in_array($site, self::BYTES_FETCH_SITES, true), 403);
        $sha256 = $request->query('sha256');
        abort_unless(is_string($sha256) && preg_match('/^[0-9a-f]{64}$/D', strtolower($sha256)) === 1, 422, trans('dam::app.admin.hub-resources.errors.sha256-required'));

        $admin = $this->admin();
        $resource = $this->readable(uuid: $uuid, admin: $admin);
        /** @var FileObject|null $object */
        $object = FileObject::query()->find($resource->file_object_id);
        abort_if($object === null, 404);
        abort_unless(hash_equals(strtolower((string) $object->sha256), strtolower($sha256)), 409, trans('dam::app.admin.hub-resources.errors.sha256-mismatch'));

        CreativeHubLog::write(event: 'resource_delivered', ctx: ['mode' => $resource->kind, 'ext' => $resource->format, 'size' => (int) $resource->size]);

        return $contentStore->deliver(
            object: $object,
            filename: $this->downloadName(resource: $resource),
            disposition: HeaderUtils::DISPOSITION_ATTACHMENT,
            mimeType: HubResourceInspector::MIME_TYPES[$resource->format] ?? 'application/octet-stream',
            headers: ['Cache-Control' => 'private, max-age=3600'],
            sameOrigin: true,
        );
    }

    /**
     * The groups $admin may see and share a resource with, by name. A full admin (HubResource::readsEverything) sees
     * every group; anyone else sees the groups they belong to, plus any group already on a resource they own, so
     * editing an existing share never silently drops it. The built-in Everyone group is the Everyone choice, never a
     * group here. This is the display list; what a PATCH may attach is attachableGroupIds().
     *
     * @return list<array{id: int, name: string}>
     */
    private function shareableGroups(Admin $admin): array
    {
        $query = $this->groupsBesideEveryone();

        if (! HubResource::readsEverything($admin)) {
            $query->where(fn ($discoverable) => $discoverable
                ->whereIn('id', $this->membershipsOf($admin))
                ->orWhereIn('id', fn ($attached) => $attached->select('group_access.group_id')
                    ->from('dam_hub_resource_group_access as group_access')
                    ->join('dam_hub_resources as owned', 'owned.id', '=', 'group_access.resource_id')
                    ->where('owned.owner_id', $admin->getKey())));
        }

        return $query->orderBy('name')->get(['id', 'name'])
            ->map(fn ($group): array => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->values()
            ->all();
    }

    /**
     * The group ids $admin may newly attach to a resource: every group for a full admin, else only the groups they
     * belong to now. A group on another resource they own is not enough (they may have left it); update() adds the
     * locked resource's own groups, so re-saving an existing share keeps them.
     *
     * @return list<int>
     */
    private function attachableGroupIds(Admin $admin): array
    {
        $query = $this->groupsBesideEveryone();

        if (! HubResource::readsEverything($admin)) {
            $query->whereIn('id', $this->membershipsOf($admin));
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function groupsBesideEveryone(): Builder
    {
        return DB::table('dam_groups')
            ->where(fn ($everyone) => $everyone->whereNull('system_key')->orWhere('system_key', '!=', AccessListReader::EVERYONE));
    }

    private function membershipsOf(Admin $admin): Closure
    {
        return fn ($members) => $members->select('group_id')->from('dam_group_members')->where('admin_id', $admin->getKey());
    }

    private function enabled(): void
    {
        abort_unless((bool) config('dam.hub.enabled'), 404);
    }

    private function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = auth()->guard('admin')->user();

        return $admin;
    }

    /** The resource by its uuid when $admin may read it; anything else answers like a missing one. */
    private function readable(string $uuid, Admin $admin): HubResource
    {
        /** @var HubResource|null $resource */
        $resource = HubResource::query()->where('uuid', $uuid)->visibleTo($admin)->first();
        abort_if($resource === null, 404);

        return $resource;
    }

    private function owns(HubResource $resource, Admin $admin): bool
    {
        return (int) $resource->owner_id === (int) $admin->getKey();
    }

    /**
     * @param  list<int>  $groupIds  shown to the owner only: another reader learns nothing about who else may read it
     * @return array<string, mixed>
     */
    private function present(HubResource $resource, Admin $admin, array $groupIds): array
    {
        $owned = $this->owns(resource: $resource, admin: $admin);
        $sha256 = (string) $resource->fileObject?->sha256;

        return [
            'id'              => (string) $resource->uuid,
            'kind'            => (string) $resource->kind,
            'format'          => (string) $resource->format,
            'name'            => (string) $resource->name,
            'family'          => $resource->family,
            'subfamily'       => $resource->subfamily,
            'weight'          => $resource->weight,
            'style'           => $resource->style,
            'postscript_name' => $resource->postscript_name,
            'size'            => (int) $resource->size,
            'sha256'          => $sha256,
            'variable'        => (bool) ($resource->meta['variable'] ?? false),
            'visibility'      => (string) $resource->visibility,
            'group_ids'       => $owned ? $groupIds : [],
            'license_note'    => $resource->license_note,
            'owned'           => $owned,
            // The owner's display name, for the manager page's Owner column; the page shows "You" for the viewer's own.
            'owner'           => $resource->owner?->name,
            'bytes_url'       => route('admin.dam.hub.resources.bytes', ['uuid' => $resource->uuid, 'sha256' => $sha256]),
        ];
    }

    /**
     * @param  array<int, HubResource>  $resources
     * @return array<int, list<int>> resource id => its group ids
     */
    private function groupIdsFor(array $resources): array
    {
        $ids = array_map(fn (HubResource $resource): int => (int) $resource->id, array_values($resources));

        if ($ids === []) {
            return [];
        }

        $groups = [];
        foreach (DB::table('dam_hub_resource_group_access')->whereIn('resource_id', $ids)->orderBy('group_id')->get(['resource_id', 'group_id']) as $row) {
            $groups[(int) $row->resource_id][] = (int) $row->group_id;
        }

        return $groups;
    }

    private function refusal(HubResourceRefused $refused): JsonResponse
    {
        $context = $refused->context;
        $message = trans('dam::app.admin.hub-resources.errors.'.(self::REASON_MESSAGES[$refused->reason] ?? 'refused'), [
            'size'  => $this->mebibytes((int) ($context['size'] ?? 0)),
            'limit' => $refused->reason === HubResourceRefused::QUOTA_BYTES
                ? rtrim(rtrim(number_format(((int) ($context['limit'] ?? 0)) / 1073741824, 2, '.', ''), '0'), '.')
                : $this->mebibytes((int) ($context['limit'] ?? 0)),
        ]);

        return response()->json(
            ['message' => $message, 'reason' => $refused->reason],
            $refused->reason === HubResourceRefused::NOT_OWNER ? Response::HTTP_FORBIDDEN : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function mebibytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.');
    }

    /** The resource's name, ending in its format's extension even after a rename that dropped it. */
    private function downloadName(HubResource $resource): string
    {
        $name = (string) $resource->name;
        $format = (string) $resource->format;

        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $format ? $name : $name.'.'.$format;
    }
}
