<?php

namespace Mothership\Admin\Http\Controllers\Settings;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mothership\Admin\DataGrids\Settings\UserDataGrid;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Http\Requests\UserForm;
use Mothership\Core\Filesystem\FileStorer;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;
use Mothership\User\Repositories\RoleRepository;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class UserController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected AdminRepository $adminRepository,
        protected RoleRepository $roleRepository,
        protected FileStorer $fileStorer
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return app(UserDataGrid::class)->toJson();
        }

        $roles = $this->assignableRoles();

        $canManageAccess = auth()->guard('admin')->user()?->role?->permission_type === 'all';

        return view('admin::settings.users.index', compact('roles', 'canManageAccess'));
    }

    /**
     * Whether the acting admin is forbidden from assigning the given role.
     *
     * A full-access admin may assign anything; anyone else is blocked from
     * assigning a role that is not explicitly `custom` — `all`, and equally any
     * unrecognised type, which every downstream check reads as full access — or a
     * custom role carrying permissions they do not themselves hold.
     */
    protected function cannotAssignRole(?Role $targetRole, ?Admin $actor = null): bool
    {
        return $this->roleAssignmentError($targetRole, $actor) !== null;
    }

    /**
     * $actor defaults to the session's admin; a write passes the actor it re-read under the full-admin lock.
     */
    protected function roleAssignmentError(?Role $targetRole, ?Admin $actor = null): ?string
    {
        if (! $targetRole) {
            return null;
        }

        return $this->adminRepository->roleGrantError(
            actor: $actor ?? auth()->guard('admin')->user(),
            permissionType: $targetRole->permission_type,
            permissions: $targetRole->permissions,
        );
    }

    /**
     * @return Collection<int, Role>
     */
    protected function assignableRoles(?int $keepRoleId = null)
    {
        return $this->roleRepository->all()
            ->filter(fn ($role): bool => (int) $role->id === $keepRoleId || ! $this->cannotAssignRole($role))
            ->values();
    }

    /**
     * Whether $target's role outranks the acting admin's, so the actor may not manage $target (UX eval
     * 2026-10-10, PERM-08): the rule for assigning a role, applied to the role $target already holds. A
     * full-access actor outranks nobody's role; anyone else is outranked by an all-access role, by a custom
     * role holding a permission they lack, and by no role at all. Nobody is outranked by their own role.
     */
    protected function outranksActor(Admin $target, ?Admin $actor = null): bool
    {
        return $this->adminRepository->outranks(target: $target, actor: $actor ?? auth()->guard('admin')->user());
    }

    /**
     * The signed-in admin re-read under the full-admin lock, with their role, when they are still active and
     * their role still grants $permission. The session's model, and the Bouncer check made on it, date from
     * before this request waited for the lock: a request that waited out its actor's demotion or deactivation
     * must not keep the authority they had (UX eval rev-C2, finding 2). Aborts 403 otherwise.
     */
    protected function lockedActorWith(string $permission): Admin
    {
        $actor = $this->adminRepository->lockedActiveActor((int) auth()->guard('admin')->id());

        abort_if(! $actor || ! $this->adminRepository->actorMay($actor, $permission), 403);

        return $actor;
    }

    protected function outrankedResponse(): JsonResponse
    {
        return new JsonResponse([
            'message' => trans('admin::app.settings.users.cannot-manage-higher-role'),
        ], JsonResponse::HTTP_FORBIDDEN);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(UserForm $request): JsonResponse
    {
        $data = $request->only([
            'name',
            'email',
            'password',
            'password_confirmation',
            'role_id',
            'ui_locale_id',
            'catalog_locale_id',
            'default_channel_id',
            'status',
            'timezone',
        ]);

        $data['use_gravatar'] = $request->boolean('use_gravatar', false);

        if ($data['password'] ?? null) {
            $data['password'] = bcrypt($data['password']);
        }

        // The create permission and the role-grant rule are decided under the full-admin lock on the actor and
        // the role as committed now (locking reads), in the transaction that creates the user: a request that
        // waited out its actor's demotion or deactivation must not create with the authority it started with
        // (UX eval rev-C3, finding 2).
        $admin = $this->adminRepository->guardingActiveFullAdmin(field: 'role_id', write: function () use ($data) {
            $actor = $this->lockedActorWith('settings.users.users.create');

            if ($error = $this->roleAssignmentError(Role::query()->lockForUpdate()->find($data['role_id']), $actor)) {
                return new JsonResponse([
                    'message' => trans($error),
                ], JsonResponse::HTTP_FORBIDDEN);
            }

            Event::dispatch('user.admin.create.before');

            $admin = $this->adminRepository->create($data);

            if (request()->hasFile('image')) {
                $image = request()->file('image');
                $image = is_array($image) ? current($image) : $image;
                $extension = $image->guessExtension() ?: strtolower($image->getClientOriginalExtension());

                $admin->image = $this->fileStorer->storeAs(
                    path: 'admins'.DIRECTORY_SEPARATOR.$admin->id,
                    name: Str::random(40).'.'.$extension,
                    file: $image,
                );

                $admin->save();
            }

            return $admin;
        });

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        Event::dispatch('user.admin.create.after', $admin);

        $response = ['message' => trans('admin::app.settings.users.create-success')];

        if (bouncer()->hasPermission('settings.users.users.edit')) {
            $response['redirect_url'] = route('admin.settings.users.edit', $admin->id);
        }

        return new JsonResponse($response);
    }

    /**
     * User Details
     *
     * @param  int  $id
     */
    public function edit($id): View|JsonResponse
    {
        $user = $this->adminRepository->findOrFail($id);

        if ($user->isApiUser()) {
            abort(404);
        }

        if ($this->outranksActor($user)) {
            abort(403, trans('admin::app.settings.users.cannot-manage-higher-role'));
        }

        $roles = $this->assignableRoles((int) $user->role_id);

        $timezone = ['id' => $user?->timezone, 'label' => $user?->timezone];

        $isSelf = $user->id === auth()->guard('admin')->id();

        if (! request()->expectsJson()) {
            return view('admin::settings.users.edit', [
                'user'                    => $user,
                'roles'                   => $roles,
                'canManage'               => true,
                'isSelf'                  => $isSelf,
                'requiresCurrentPassword' => $isSelf,
                'formId'                  => 'user-edit-form',
                'formAction'              => route('admin.settings.users.update'),
                'pageTitle'               => trans('admin::app.settings.users.edit.title'),
                'backUrl'                 => route('admin.settings.users.index'),
                'backLabel'               => trans('admin::app.settings.users.edit.back-btn'),
                'saveLabel'               => trans('admin::app.settings.users.edit.save-btn'),
            ]);
        }

        return new JsonResponse([
            'roles'    => $roles,
            'user'     => [
                ...$user->toArray(),
                'status' => (bool) $user->status,
            ],
            'timezone' => $timezone,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserForm $request): JsonResponse
    {
        $id = request()->id;

        // PERM-07: the rank and last-full-admin checks and the write share one transaction under the lock every
        // change able to cost the last active full-access admin takes, so a concurrent change cannot slip
        // between them.
        $written = $this->adminRepository->guardingActiveFullAdmin(field: 'status', write: function () use ($request, $id) {
            $data = $this->prepareUserData($request, $id);

            if (! is_array($data)) {
                return $data;
            }

            Event::dispatch('user.admin.update.before', $id);

            $admin = $this->adminRepository->update($data, $id);

            if (request()->hasFile('image')) {
                $image = request()->file('image');
                $image = is_array($image) ? current($image) : $image;
                $extension = $image->guessExtension() ?: strtolower($image->getClientOriginalExtension());

                $admin->image = $this->fileStorer->storeAs(
                    path: 'admins'.DIRECTORY_SEPARATOR.$admin->id,
                    name: Str::random(40).'.'.$extension,
                    file: $image,
                );
            } else {
                if (! request()->has('image') && $admin->image) {
                    Storage::delete($admin->image);
                    $admin->image = null;
                }
            }

            $admin->save();

            return [$admin, $data];
        });

        if ($written instanceof JsonResponse) {
            return $written;
        }

        if ($written instanceof RedirectResponse) {
            return new JsonResponse([
                'message' => session('error', trans('admin::app.settings.users.cannot-change', ['name' => ''])),
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        [$admin, $data] = $written;

        if (! empty($data['password'])) {
            // The new password hash ends every session the user had (AuthenticateSession, PERM-12). Changing one's
            // own password here keeps the session that did it, as the account page does; their API tokens are
            // revoked either way, as the account page has always done.
            if ((int) $admin->id === (int) auth()->guard('admin')->id()) {
                auth()->guard('admin')->setUser($admin);
                auth()->guard('admin')->logoutOtherDevices($request->input('password'));
            }

            $admin->tokens()->update(['revoked' => true]);

            Event::dispatch('admin.password.update.after', $admin);
        }

        Event::dispatch('user.admin.update.after', $admin);

        return new JsonResponse([
            'message'      => trans('admin::app.settings.users.update-success'),
            'redirect_url' => route('admin.settings.users.index'),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     */
    public function destroy($id): JsonResponse
    {
        try {
            // PERM-07: checks and delete under one lock and transaction (see update()).
            return $this->adminRepository->guardingActiveFullAdmin(field: 'id', write: fn () => $this->destroyGuarded($id));
        } catch (ValidationException|HttpExceptionInterface|ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
        }

        return new JsonResponse([
            'message' => trans('admin::app.settings.users.delete-failed'),
        ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * destroy()'s checks and delete, run under the full-admin lock.
     *
     * @param  int  $id
     */
    private function destroyGuarded($id): JsonResponse
    {
        $actor = $this->lockedActorWith('settings.users.users.delete');

        $user = $this->adminRepository->lockedAdmin((int) $id);

        abort_if(! $user, 404);

        if ($user->isApiUser()) {
            abort(404);
        }

        if ($this->adminRepository->count() == 1) {
            return new JsonResponse([
                'message' => trans('admin::app.settings.users.last-delete-error'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($id == auth('admin')->user()->id) {
            return new JsonResponse([
                'message' => trans('admin::app.settings.users.current-user-delete-error'),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($this->outranksActor($user, $actor)) {
            return $this->outrankedResponse();
        }

        if ($this->isActiveFullAdmin($user)) {
            $this->adminRepository->ensureActiveFullAdminRemains(field: 'id', withoutAdminIds: [$user->id]);
        }

        Event::dispatch('user.admin.delete.before', $id);

        $this->adminRepository->delete($id);

        Event::dispatch('user.admin.delete.after', $id);

        return new JsonResponse([
            'message' => trans('admin::app.settings.users.delete-success'),
        ], JsonResponse::HTTP_OK);
    }

    /**
     * Show the form for confirming the user password.
     *
     * @param  int  $id
     */
    public function confirm($id): View
    {
        $user = $this->adminRepository->findOrFail($id);

        return view('admin::customers.customers.confirm-password', compact('user'));
    }

    /**
     * Destroy current after confirming.
     */
    public function destroySelf(): JsonResponse
    {
        $password = request()->input('password');

        if (Hash::check($password, auth()->guard('admin')->user()->password)) {
            if ($this->adminRepository->count() == 1) {
                return new JsonResponse([
                    'message' => trans('admin::app.settings.users.delete-last'),
                ], JsonResponse::HTTP_BAD_REQUEST);
            } else {
                $id = auth()->guard('admin')->user()->id;

                // PERM-07: check and delete under one lock and transaction (see update()), on the account as
                // it stands now rather than as this request's guard loaded it.
                $this->adminRepository->guardingActiveFullAdmin(field: 'password', write: function () use ($id) {
                    $self = $this->lockedActorWith('settings.users.users.delete');

                    if ($this->isActiveFullAdmin($self)) {
                        $this->adminRepository->ensureActiveFullAdminRemains(field: 'password', withoutAdminIds: [$id]);
                    }

                    Event::dispatch('user.admin.delete.before', $id);

                    $this->adminRepository->delete($id);

                    Event::dispatch('user.admin.delete.after', $id);
                });

                return new JsonResponse([
                    'redirectUrl' => route('admin.session.create'),
                    'message'     => trans('admin::app.settings.users.delete-success'),
                ]);
            }
        } else {
            return new JsonResponse([
                'message' => trans('admin::app.settings.users.incorrect-password'),
                'errors'  => ['password' => [trans('admin::app.settings.users.incorrect-password')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Prepare user data.
     *
     * @param  int  $id
     * @return array|RedirectResponse|JsonResponse
     */
    private function prepareUserData(UserForm $request, $id)
    {
        $data = $request->validated();

        unset($data['current_password']);

        // Runs under the full-admin lock (update()): the actor and the target are read as committed now, with
        // locking reads, and the actor must still be active and still hold the edit permission.
        $actor = $this->lockedActorWith('settings.users.users.edit');

        $user = $this->adminRepository->lockedAdmin((int) $id);

        abort_if(! $user, 404);

        if ($user->isApiUser()) {
            abort(404);
        }

        // PERM-08: its role, password and status belong to whoever its role does not outrank.
        if ($this->outranksActor($user, $actor)) {
            return $this->outrankedResponse();
        }

        /**
         * Password check.
         */
        if (! $data['password']) {
            unset($data['password']);
        } else {
            $data['password'] = bcrypt($data['password']);
        }

        /**
         * Is user with `permission_type` all changed status.
         */
        $data['status'] = filter_var($data['status'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data['use_gravatar'] = $request->boolean('use_gravatar', (bool) $user->use_gravatar);

        $newRole = Role::query()->lockForUpdate()->find($data['role_id'] ?? $user->role_id);

        if ($this->cannotAssignRole($newRole, $actor)) {
            return $this->cannotChangeRedirectResponse('role');
        }

        // PERM-07: deactivating or demoting an active full admin must leave another one.
        if ($this->isActiveFullAdmin($user) && ! ($data['status'] && $newRole?->permission_type === 'all')) {
            $this->adminRepository->ensureActiveFullAdminRemains(
                field: $data['status'] ? 'role_id' : 'status',
                withoutAdminIds: [$user->id],
            );
        }

        unset($data['image']);

        return $data;
    }

    private function isActiveFullAdmin(Admin $admin): bool
    {
        return (bool) $admin->status && $admin->role?->permission_type === 'all';
    }

    /**
     * Cannot change redirect response.
     */
    private function cannotChangeRedirectResponse(string $columnName): RedirectResponse
    {
        session()->flash('error', trans('admin::app.settings.users.cannot-change', [
            'name' => $columnName,
        ]));

        return redirect()->route('admin.settings.users.index');
    }
}
