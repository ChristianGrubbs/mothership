<?php

namespace Mothership\Admin\Http\Controllers\Settings;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mothership\Admin\DataGrids\Settings\RolesDataGrid;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Http\Requests\RoleForm;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;
use Mothership\User\Repositories\RoleRepository;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RoleController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected RoleRepository $roleRepository,
        protected AdminRepository $adminRepository
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return app(RolesDataGrid::class)->toJson();
        }

        return view('admin::settings.roles.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin::settings.roles.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(RoleForm $request): JsonResponse|RedirectResponse
    {
        // Inside the access-model fence, as before, then under the full-admin lock: the create permission and
        // RoleForm's rule (only a full-access admin makes a full-access role) are decided again on the actor as
        // committed now (locking reads), in the transaction that creates the role (UX eval rev-C3, finding 2).
        $this->fenceRoleWrite(fn () => $this->adminRepository->guardingActiveFullAdmin(field: 'permission_type', write: function () use ($request) {
            $actor = $this->lockedActorWith('settings.roles.create');

            abort_if($request->permission_type === 'all' && $actor->role?->permission_type !== 'all', 403);

            Event::dispatch('user.role.create.before');

            $role = $this->roleRepository->create(request()->only([
                'name',
                'description',
                'permission_type',
                'permissions',
            ]));

            Event::dispatch('user.role.create.after', $role);

            return $role;
        }));

        return $this->respondSaved(
            app(RoleBuilder::class)->withReconciliationNotice(trans('admin::app.settings.roles.create-success')),
            route('admin.settings.roles.index')
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $role = $this->roleRepository->findOrFail($id);

        return view('admin::settings.roles.edit', compact('role'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RoleForm $request, int $id): JsonResponse|RedirectResponse
    {
        $this->roleRepository->findOrFail($id);

        $this->fenceRoleWrite(fn () => $this->adminRepository->guardingActiveFullAdmin(field: 'permission_type', write: function () use ($request, $id) {
            // PERM-07: turning a full-access role custom takes every member out of full access at once, so another
            // active full admin must remain, however many members the role has. Asked inside the fence, under the
            // lock every such change takes, on the role as it stands now, so it decides with the write. The actor
            // is re-read there too, and RoleForm's rule repeated on it: only a full-access admin makes a role
            // full access.
            $actor = $this->lockedActorWith('settings.roles.edit');

            abort_if($request->permission_type === 'all' && $actor->role?->permission_type !== 'all', 403);

            $role = Role::query()->lockForUpdate()->findOrFail($id);

            if ($role->permission_type === 'all' && $request->permission_type !== 'all') {
                $this->adminRepository->ensureActiveFullAdminRemains(field: 'permission_type', withoutRoleIds: [$role->id]);
            }

            Event::dispatch('user.role.update.before', $id);

            $data = array_merge(request()->only([
                'name',
                'description',
                'permission_type',
            ]), [
                'permissions' => request()->has('permissions') ? request('permissions') : [],
            ]);

            $role = $this->roleRepository->update($data, $id);

            Event::dispatch('user.role.update.after', $role);

            return $role;
        }));

        return $this->respondSaved(
            app(RoleBuilder::class)->withReconciliationNotice(trans('admin::app.settings.roles.update-success'))
        );
    }

    /**
     * Fences a role create or update behind the DAM access-model state row (ADR 0010), where DAM is part of
     * this installation: a concurrent switch between grants and lists cannot land between the moment a
     * listener on user.role.*.before decides what this save's permissions should hold (sharing slice 5's
     * ShareKeyGrant keys, RoleBuilder::keepShareKeysUnderLists()) and the moment those permissions actually
     * commit (Codex review of PR #76, finding 1).
     *
     * AccessModel::holding() opens its own transaction when none is already open, flushes its cached model
     * read before taking the row hold, and holds it FOR SHARE for the whole callback — so dam:access:switch,
     * which takes the same row FOR UPDATE, cannot rebuild the lists or run ShareKeyGrant::grant()/revert()
     * while $write is deciding and persisting, and $write cannot start while a switch is mid-transition.
     * Runs $write directly when DAM is not part of this installation.
     */
    protected function fenceRoleWrite(callable $write): mixed
    {
        if (! app()->bound(AccessModel::class)) {
            return $write();
        }

        return app(AccessModel::class)->holding($write);
    }

    /**
     * The signed-in admin re-read under the full-admin lock, with their role, when they are still active and
     * their role still grants $permission. RoleForm and Bouncer decided on the session's model before this
     * request waited for the lock; a request that waited out its actor's demotion or deactivation must not keep
     * the authority they had (UX eval rev-C2, finding 2). Aborts 403 otherwise.
     */
    private function lockedActorWith(string $permission): Admin
    {
        $actor = $this->adminRepository->lockedActiveActor((int) auth()->guard('admin')->id());

        abort_if(! $actor || ! $this->adminRepository->actorMay($actor, $permission), 403);

        return $actor;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // PERM-07: the checks and the delete share one transaction under the lock every change able to cost
            // the last active full-access admin takes (AdminRepository::guardingActiveFullAdmin()).
            return $this->adminRepository->guardingActiveFullAdmin(field: 'id', write: fn () => $this->destroyGuarded($id));
        } catch (ValidationException|HttpExceptionInterface|ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
        }

        return new JsonResponse([
            'message' => trans('admin::app.settings.roles.delete-failed'),
        ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * destroy()'s checks and delete, run under the full-admin lock.
     */
    private function destroyGuarded(int $id): JsonResponse
    {
        $this->lockedActorWith('settings.roles.delete');

        $role = Role::query()->lockForUpdate()->findOrFail($id);

        if ($name = $role->admins()->value('name')) {
            return new JsonResponse([
                'message' => trans('admin::app.settings.roles.being-used-by', ['name' => $name]),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($this->roleRepository->count() == 1) {
            return new JsonResponse([
                'message' => trans('admin::app.settings.roles.last-delete-error'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($role->permission_type === 'all') {
            $this->adminRepository->ensureActiveFullAdminRemains(field: 'id', withoutRoleIds: [$role->id]);
        }

        Event::dispatch('user.role.delete.before', $id);

        $this->roleRepository->delete($id);

        Event::dispatch('user.role.delete.after', $id);

        return $this->respondDeleted(trans('admin::app.settings.roles.delete-success'));
    }
}
