<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Support\GuestIntakeLog;
use Mothership\User\Models\Admin;

/**
 * P5a (D1): staff side of upload requests, shared by the admin pages and REST so the two never drift.
 *
 * - **Who sees a request:** its creator, a full admin, or anyone who can open its intake folder. Anyone else gets the
 *   404 a missing id gets.
 * - **Who changes one** (edit, close, Reissue): its creator or a full admin (403 for anyone else who sees it).
 * - **Who sees its URL:** the #126 rule, as for share links: the creator, the intake folder's owner, a full admin.
 * - The intake folder is the one the staff member names when creating the request and never changes afterwards; the
 *   creator must be able to open it.
 */
class UploadRequestService
{
    public const DEFAULT_EXPIRY_DAYS = 14;

    public function __construct(
        protected DirectoryPermissionService $permissions,
    ) {}

    /**
     * The validation rules for create (`$creating`) or update. Every option is optional on update; an absent one is
     * left as it is.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title'              => [$required, 'string', 'max:191'],
            'instructions'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'directory_id'       => $creating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'expires_at'         => ['sometimes', 'nullable', 'date', 'after:now'],
            'expiry_days'        => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'no_expiry'          => ['sometimes', 'boolean'],
            'max_files'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('dam.upload_requests.max_files_cap')],
            'max_bytes_per_file' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('dam.explorer.upload.max_file_bytes')],
            'allowed_types'      => ['sometimes', 'nullable'],
            'allowed_types.*'    => ['string', 'max:130'],
            'password'           => ['sometimes', 'nullable', 'string', 'min:'.ShareAccessOptions::PASSWORD_MIN, 'max:'.ShareAccessOptions::PASSWORD_MAX, 'prohibited_if_accepted:remove_password'],
            'remove_password'    => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A new open request on `$directory` and its URL token, which is shown to the creator now and stored only hashed
     * and encrypted.
     *
     * @param  array<string, mixed>  $data  validated by rules(creating: true)
     * @return array{0: UploadRequest, 1: string} the request and its URL token
     */
    public function create(Admin $actor, Directory $directory, array $data): array
    {
        $token = $this->generateToken();
        $request = UploadRequest::query()->create(UploadRequest::tokenColumns($token) + $this->columns(data: $data, creating: true) + [
            'directory_id' => $directory->id,
            'created_by'   => $actor->id,
            'status'       => UploadRequest::STATUS_OPEN,
        ]);

        DB::afterCommit(fn () => GuestIntakeLog::write(event: 'upload_request.created', ctx: [
            'upload_request_id' => (int) $request->id, 'directory_id' => (int) $directory->id, 'actor_admin_id' => (int) $actor->id,
            'has_password'      => $request->hasPassword(), 'max_files' => $request->max_files,
        ]));

        return [$request->fresh(), $token];
    }

    /** @param  array<string, mixed>  $data  validated by rules(creating: false) */
    public function update(Admin $actor, UploadRequest $request, array $data): UploadRequest
    {
        $request->fill($this->columns(data: $data, creating: false))->save();

        GuestIntakeLog::write(event: 'upload_request.updated', ctx: ['upload_request_id' => (int) $request->id, 'actor_admin_id' => (int) $actor->id]);

        return $request->fresh();
    }

    /** Closes the link: it answers like an unknown one from now on, and an upload under way fails at finalize. */
    public function close(Admin $actor, UploadRequest $request): UploadRequest
    {
        UploadRequest::query()->where('id', $request->id)->where('status', UploadRequest::STATUS_OPEN)
            ->update(['status' => UploadRequest::STATUS_CLOSED, 'closed_at' => now()]);

        GuestIntakeLog::write(event: 'upload_request.closed', ctx: ['upload_request_id' => (int) $request->id, 'actor_admin_id' => (int) $actor->id]);

        return $request->fresh();
    }

    /**
     * Reissue (as P2 S5a for share links): a new token for the same request, in place. The old URL's hash then matches
     * nothing, and it answers exactly like a token that never existed; a visit cookie issued under it stops opening
     * the portal too (its signature covers the token hash). Null for a closed request.
     *
     * @return array{0: UploadRequest, 1: string}|null
     */
    public function reissue(Admin $actor, UploadRequest $request): ?array
    {
        $token = $this->generateToken();
        $updated = UploadRequest::query()->where('id', $request->id)->where('status', UploadRequest::STATUS_OPEN)
            ->update(UploadRequest::tokenColumns($token));

        if ($updated !== 1) {
            return null;
        }

        GuestIntakeLog::write(event: 'upload_request.reissued', ctx: ['upload_request_id' => (int) $request->id, 'actor_admin_id' => (int) $actor->id]);

        return [$request->fresh(), $token];
    }

    /** The request with this id when `$actor` may see it; null answers like a missing id. */
    public function visible(Admin $actor, int $id): ?UploadRequest
    {
        $request = UploadRequest::query()->with(['directory', 'creator'])->where('id', $id)->first();

        return $request !== null && $this->canSee(actor: $actor, request: $request) ? $request : null;
    }

    public function canSee(Admin $actor, UploadRequest $request): bool
    {
        return $this->isFullAdmin($actor)
            || (int) $request->created_by === (int) $actor->id
            || $this->permissionsFor($actor)->canAccess((int) $request->directory_id);
    }

    public function canManage(Admin $actor, UploadRequest $request): bool
    {
        return $this->isFullAdmin($actor) || (int) $request->created_by === (int) $actor->id;
    }

    /** Whether `$actor` may name `$directory` as an intake folder: they can open it. */
    public function canUseAsIntake(Admin $actor, Directory $directory): bool
    {
        return $this->permissionsFor($actor)->canAccess((int) $directory->id);
    }

    /** The requests `$actor` may see, newest first. */
    public function visibleQuery(Admin $actor): Builder
    {
        $query = UploadRequest::query()->with(['directory', 'creator'])->orderByDesc('id');

        if ($this->isFullAdmin($actor)) {
            return $query;
        }

        $folders = $this->permissionsFor($actor)->directlyGrantedIds();

        return $query->where(fn (Builder $q) => $q->where('created_by', $actor->id)->orWhereIn('directory_id', $folders));
    }

    /**
     * What every staff API shows of a request: never the password or its hash, and the URL only to the people the
     * #126 rule names.
     *
     * @return array<string, mixed>
     */
    public function present(UploadRequest $request, Admin $actor, ?string $freshToken = null): array
    {
        $tokenVisible = $request->tokenVisibleTo(adminId: (int) $actor->id, fullAdmin: $this->isFullAdmin($actor));
        $token = $tokenVisible ? ($freshToken ?? $request->urlToken()) : null;
        $counts = $this->counts([(int) $request->id])[(int) $request->id] ?? ['submissions' => 0, 'files' => 0, 'pending' => 0];

        return [
            'id'                 => (int) $request->id,
            'title'              => $request->title,
            'instructions'       => $request->instructions,
            'directory'          => $request->directory ? ['id' => (int) $request->directory->id, 'name' => $request->directory->name, 'path' => $request->directory->generatePath()] : null,
            'status'             => $request->statusLabel(),
            'expires_at'         => $request->expires_at?->toIso8601String(),
            'max_files'          => $request->max_files,
            'max_bytes_per_file' => $request->max_bytes_per_file,
            'allowed_types'      => $request->allowed_types ?? [],
            'has_password'       => $request->hasPassword(),
            'created_by'         => $request->creator ? ['id' => (int) $request->creator->id, 'name' => $request->creator->name] : null,
            'created_at'         => $request->created_at?->toIso8601String(),
            'counts'             => $counts,
            'url'                => $token !== null ? route('dam.upload_portal.show', ['token' => $token]) : null,
            'can_manage'         => $this->canManage(actor: $actor, request: $request),
        ];
    }

    /**
     * Submissions, files that arrived and files still pending, per request: counts only, never which files.
     *
     * @param  list<int>  $requestIds
     * @return array<int, array{submissions: int, files: int, pending: int}>
     */
    public function counts(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $submissions = DB::table('dam_upload_submissions')->whereIn('upload_request_id', $requestIds)->where('file_count', '>', 0)
            ->groupBy('upload_request_id')->select('upload_request_id', DB::raw('COUNT(*) as n'), DB::raw('SUM(file_count) as files'))->get();
        $pending = DB::table('dam_assets')
            ->join('dam_upload_submissions', 'dam_upload_submissions.id', '=', 'dam_assets.upload_submission_id')
            ->whereIn('dam_upload_submissions.upload_request_id', $requestIds)
            ->whereNull('dam_assets.deleted_at')
            ->where('dam_assets.availability', Asset::AVAILABILITY_PENDING_REVIEW)
            ->groupBy('dam_upload_submissions.upload_request_id')
            ->select('dam_upload_submissions.upload_request_id', DB::raw('COUNT(*) as n'))
            ->pluck('n', 'upload_request_id');

        $counts = [];

        foreach ($requestIds as $id) {
            $row = $submissions->firstWhere('upload_request_id', $id);
            $counts[$id] = ['submissions' => (int) ($row->n ?? 0), 'files' => (int) ($row->files ?? 0), 'pending' => (int) ($pending[$id] ?? 0)];
        }

        return $counts;
    }

    /**
     * The request columns `$data` sets. An absent option is left alone on update; on create it takes its default
     * (14-day expiry, no limits beyond the Library's, no password).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function columns(array $data, bool $creating): array
    {
        $columns = [];

        if (array_key_exists('title', $data)) {
            $columns['title'] = trim((string) $data['title']);
        }

        if (array_key_exists('instructions', $data)) {
            $instructions = trim((string) $data['instructions']);
            $columns['instructions'] = $instructions === '' ? null : $instructions;
        }

        if (! empty($data['no_expiry'])) {
            $columns['expires_at'] = null;
        } elseif (! empty($data['expires_at'])) {
            $columns['expires_at'] = Carbon::parse($data['expires_at'])->setTimezone(config('app.timezone'));
        } elseif (! empty($data['expiry_days'])) {
            $columns['expires_at'] = now()->addDays((int) $data['expiry_days']);
        } elseif ($creating) {
            $columns['expires_at'] = now()->addDays(self::DEFAULT_EXPIRY_DAYS);
        }

        foreach (['max_files', 'max_bytes_per_file'] as $limit) {
            if (array_key_exists($limit, $data)) {
                $columns[$limit] = $data[$limit] === null || $data[$limit] === '' ? null : (int) $data[$limit];
            }
        }

        if (array_key_exists('allowed_types', $data)) {
            $types = AllowedTypes::normalize($data['allowed_types']);
            $columns['allowed_types'] = $types === [] ? null : $types;
        }

        if (! empty($data['remove_password'])) {
            $columns['password_hash'] = null;
        } elseif (is_string($data['password'] ?? null) && $data['password'] !== '') {
            $columns['password_hash'] = Hash::make($data['password']);
        }

        return $columns;
    }

    protected function generateToken(): string
    {
        do {
            $token = Str::random(40);
        } while (UploadRequest::query()->where('token_hash', UploadRequest::hashToken($token))->exists());

        return $token;
    }

    protected function isFullAdmin(Admin $actor): bool
    {
        $role = $actor->role;

        return $role === null || $role->permission_type === 'all';
    }

    protected function permissionsFor(Admin $actor): DirectoryPermissionService
    {
        $session = auth('admin')->user() ?? auth('api')->user();

        return $session !== null && (int) $session->getKey() === (int) $actor->id
            ? $this->permissions
            : (new DirectoryPermissionService)->forAdmin($actor);
    }
}
