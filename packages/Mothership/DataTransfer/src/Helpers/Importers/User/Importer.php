<?php

namespace Mothership\DataTransfer\Helpers\Importers\User;

use Illuminate\Http\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Illuminate\Support\Facades\Validator;
use Mothership\Core\Filesystem\FileStorer;
use Mothership\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Mothership\DataTransfer\Helpers\Formatters\EscapeFormulaOperators;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\AbstractImporter;
use Mothership\DataTransfer\Helpers\Importers\Concerns\GuardsAdminAccess;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;
use Mothership\User\Repositories\RoleRepository;

class Importer extends AbstractImporter
{
    use GuardsAdminAccess;

    /**
     * Filter value to import active rows only.
     */
    public const FILTER_STATUS_ACTIVE = 'active';

    /**
     * Filter value to import all rows.
     */
    public const FILTER_STATUS_ALL = 'all';

    /**
     * Error code for non existing email
     */
    public const ERROR_EMAIL_NOT_FOUND_FOR_DELETE = 'email_not_found_to_delete';

    /**
     * Error code for invalid role
     */
    public const ERROR_INVALID_ROLE = 'invalid_role';

    /**
     * Error code for invalid locale
     */
    public const ERROR_INVALID_LOCALE = 'invalid_locale';

    /**
     * Permanent entity columns
     */
    protected array $validColumnNames = [
        'name',
        'email',
        'image',
        'status',
        'role_name',
        'permission_type',
        'permissions',
        'timezone',
        'ui_locale_code',
    ];

    /**
     * Permanent entity columns
     */
    protected array $permanentAttributes = ['email', 'name', 'role_name'];

    /**
     * Error message templates
     */
    protected array $messages = [
        self::ERROR_EMAIL_NOT_FOUND_FOR_DELETE => 'data_transfer::app.importers.users.validation.errors.email-not-found-to-delete',
        self::ERROR_INVALID_ROLE               => 'data_transfer::app.importers.users.validation.errors.invalid-role',
        self::ERROR_INVALID_LOCALE             => 'data_transfer::app.importers.users.validation.errors.invalid-locale',
        self::ERROR_OUTRANKED                  => 'data_transfer::app.importers.users.validation.errors.outranked',
        self::ERROR_LAST_FULL_ADMIN            => 'data_transfer::app.importers.users.validation.errors.last-full-admin',
    ];

    /**
     * Create a new helper instance.
     */
    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected AdminRepository $adminRepository,
        protected RoleRepository $roleRepository,
        protected Storage $userStorage,
        protected FileStorer $fileStorer
    ) {
        parent::__construct($importBatchRepository);
    }

    /**
     * Initialize error templates
     */
    protected function initErrorMessages(): void
    {
        foreach ($this->messages as $errorCode => $message) {
            $this->errorHelper->addErrorMessage($errorCode, trans($message));
        }

        parent::initErrorMessages();
    }

    public function validateData(): void
    {
        $this->userStorage->init();

        parent::validateData();
    }

    public function validateRow(array $rowData, int $rowNumber): bool
    {
        if (isset($this->validatedRows[$rowNumber])) {
            return ! $this->errorHelper->isRowInvalid($rowNumber);
        }

        $this->validatedRows[$rowNumber] = true;

        if ($this->import->action == Import::ACTION_DELETE) {
            $this->userStorage->loadAdmins([$rowData['email']]);

            if (! $this->userStorage->getAdminId($rowData['email'])) {
                $this->skipRow($rowNumber, self::ERROR_EMAIL_NOT_FOUND_FOR_DELETE, $rowData['email']);

                return false;
            }

            if ($refusal = $this->deleteRefusal($this->userStorage->getAdminId($rowData['email']), $this->import)) {
                $this->skipRow($rowNumber, $refusal, 'email');

                return false;
            }

            return true;
        }

        if (! $this->shouldImportRow($rowData)) {
            return false;
        }

        $validator = Validator::make($rowData, [
            'name'            => ['required', 'string'],
            'email'           => ['required', 'email'],
            'role_name'       => ['required', 'string'],
            'permission_type' => ['nullable', 'in:all,custom'],
            'permissions'     => ['nullable', 'string'],
            'status'          => ['nullable', 'in:active,inactive'],
            'timezone'        => ['nullable', 'string'],
            'ui_locale_code'  => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->getMessages() as $attributeCode => $message) {
                $this->skipRow($rowNumber, 'invalid_field', $attributeCode, current($message));
            }
        }

        if (! empty($rowData['role_name']) && ! $this->userStorage->getRoleId($rowData['role_name'])) {
            $this->skipRow($rowNumber, self::ERROR_INVALID_ROLE, $rowData['role_name']);
        }

        if (! empty($rowData['ui_locale_code']) && ! $this->userStorage->getLocaleId($rowData['ui_locale_code'])) {
            $this->skipRow($rowNumber, self::ERROR_INVALID_LOCALE, $rowData['ui_locale_code']);
        }

        if (! $this->errorHelper->isRowInvalid($rowNumber) && $refusal = $this->saveRefusal($rowData, $this->import)) {
            $this->skipRow($rowNumber, $refusal, 'email');
        }

        return ! $this->errorHelper->isRowInvalid($rowNumber);
    }

    protected function adminAccessRepository(): AdminRepository
    {
        return $this->adminRepository;
    }

    /**
     * Error code when the job's actor may not delete this admin, or when deleting them leaves no active
     * full-access admin; null when the delete may go ahead. Asked at validation and again before each write,
     * since earlier rows in the same job change the answer.
     */
    protected function deleteRefusal(?int $adminId, mixed $jobTrack): ?string
    {
        $target = $adminId ? $this->adminRepository->lockedAdmin($adminId) : null;

        if (! $target) {
            return null;
        }

        if ($this->actorOutrankedBy($jobTrack, $target)) {
            return self::ERROR_OUTRANKED;
        }

        if ($this->isActiveFullAdmin($target) && ! $this->activeFullAdminRemains(withoutAdminIds: [$target->id])) {
            return self::ERROR_LAST_FULL_ADMIN;
        }

        return null;
    }

    /**
     * Error code when the job's actor may not write this row — the user it updates outranks them, the role it
     * assigns, creates or rewrites carries access they do not hold — or when the row would leave no active
     * full-access admin (by deactivating or demoting one, or by turning an all-access role custom); null when
     * the row may be written.
     */
    protected function saveRefusal(array $rowData, mixed $jobTrack, ?array $subjects = null): ?string
    {
        [$target, $role] = $subjects ?? $this->lockedRowSubjects($rowData);
        $rewritesRole = ! empty($rowData['permission_type']);

        $permissionType = $rewritesRole ? $rowData['permission_type'] : $role?->permission_type;
        $permissions = $rewritesRole
            ? (empty($rowData['permissions']) ? [] : explode(',', $rowData['permissions']))
            : $role?->permissions;

        // The named role vanished (deleted or renamed) between validation and this locked read, and the row does not
        // create one: refuse it rather than write the user with no role (or detach a valid one).
        if (! empty($rowData['role_name']) && ! $role && ! $rewritesRole) {
            return self::ERROR_INVALID_ROLE;
        }

        if ($this->actorOutrankedBy($jobTrack, $target)) {
            return self::ERROR_OUTRANKED;
        }

        if ($role && $rewritesRole && $this->roleGrantErrorFor($jobTrack, $role->permission_type, $role->permissions)) {
            return self::ERROR_OUTRANKED;
        }

        if ($this->roleGrantErrorFor($jobTrack, $permissionType, $permissions)) {
            return self::ERROR_OUTRANKED;
        }

        $withoutAdminIds = [];
        $withoutRoleIds = [];

        if ($role && $rewritesRole && $role->permission_type === 'all' && $permissionType !== 'all') {
            $withoutRoleIds[] = $role->id;
        }

        if (
            $target
            && $this->isActiveFullAdmin($target)
            && (($rowData['status'] ?? 'active') !== 'active' || $permissionType !== 'all')
        ) {
            $withoutAdminIds[] = $target->id;
        }

        if (! $this->activeFullAdminRemains(withoutAdminIds: $withoutAdminIds, withoutRoleIds: $withoutRoleIds)) {
            return self::ERROR_LAST_FULL_ADMIN;
        }

        return null;
    }

    /**
     * The admin the row names (by email) and the role it names (by name), read with locking reads: the write step
     * runs inside the import's outer transaction, whose plain reads — the storage's email and name maps included —
     * may answer from an older REPEATABLE READ snapshot (MySQL/MariaDB), when another admin had that email or
     * another role that name. The write step decides on these two and writes to exactly these two (UX eval
     * rev-C3, finding 1).
     *
     * @return array{0: ?Admin, 1: ?Role}
     */
    private function lockedRowSubjects(array $rowData): array
    {
        $targetId = Admin::query()->where('email', $rowData['email'])->lockForUpdate()->value('id');

        return [
            $targetId ? $this->adminRepository->lockedAdmin((int) $targetId) : null,
            Role::query()->where('name', $rowData['role_name'])->lockForUpdate()->first(),
        ];
    }

    private function isActiveFullAdmin(Admin $admin): bool
    {
        return (bool) $admin->status && $admin->type === 'user' && $admin->role?->permission_type === 'all';
    }

    protected function shouldImportRow(array $rowData): bool
    {
        if ($this->getStatusFilter() !== self::FILTER_STATUS_ACTIVE) {
            return true;
        }

        return ($rowData['status'] ?? self::FILTER_STATUS_ACTIVE) === self::FILTER_STATUS_ACTIVE;
    }

    protected function getStatusFilter(): string
    {
        $filters = $this->import->jobInstance->filters ?? [];

        return $filters['status'] ?? self::FILTER_STATUS_ALL;
    }

    public function importBatch(JobTrackBatchContract $batch): bool
    {
        Event::dispatch('data_transfer.imports.batch.import.before', $batch);

        if ($batch->jobTrack->action == Import::ACTION_DELETE) {
            $this->deleteUserData($batch);
        } else {
            $this->saveUserData($batch);
        }

        $batch = $this->importBatchRepository->update([
            'state'   => Import::STATE_PROCESSED,
            'summary' => [
                'created' => $this->getCreatedItemsCount(),
                'updated' => $this->getUpdatedItemsCount(),
                'deleted' => $this->getDeletedItemsCount(),
            ],
        ], $batch->id);

        Event::dispatch('data_transfer.imports.batch.import.after', $batch);

        return true;
    }

    protected function deleteUserData(JobTrackBatchContract $batch): bool
    {
        foreach (array_unique(Arr::pluck($batch->data, 'email')) as $email) {
            // The admin is looked up by email under the lock (a locking read), not taken from the storage's
            // snapshot-era map, so the delete removes the admin its check was about.
            $id = null;
            $target = null;

            [$refusal] = $this->writeUnderAdminLock(
                refusal: function () use ($email, $batch, &$id, &$target): ?string {
                    $id = Admin::query()->where('email', $email)->lockForUpdate()->value('id');
                    $target = $id ? $this->adminRepository->lockedAdmin((int) $id) : null;

                    return $target ? $this->deleteRefusal((int) $id, $batch->jobTrack) : null;
                },
                // The locked model itself: AdminRepository::delete() would re-find it with a plain read, which
                // under REPEATABLE READ can miss an admin committed after the import's snapshot.
                write: function () use (&$target) {
                    return $target ? $this->adminRepository->deleteModel($target) : null;
                },
            );

            if ($refusal) {
                $this->logRefusedRow($refusal, "admin #{$id}");

                continue;
            }

            if ($id) {
                $this->deletedItemsCount++;
            }
        }

        return true;
    }

    protected function saveUserData(JobTrackBatchContract $batch): bool
    {
        $this->userStorage->init();

        $this->userStorage->loadAdmins(Arr::pluck($batch->data, 'email'));

        foreach ($batch->data as $rowData) {
            // The target and the role are resolved once, under the lock, and the write uses exactly those.
            $subjects = [null, null];

            [$refusal, $written] = $this->writeUnderAdminLock(
                refusal: function () use ($rowData, $batch, &$subjects): ?string {
                    $subjects = $this->lockedRowSubjects($rowData);

                    return $this->saveRefusal($rowData, $batch->jobTrack, $subjects);
                },
                write: function () use ($rowData, &$subjects): array {
                    return $this->writeUserRow($rowData, target: $subjects[0], role: $subjects[1]);
                },
            );

            if ($refusal) {
                $this->logRefusedRow($refusal, (string) $rowData['email']);

                continue;
            }

            [$created, $roleCreated, $admin] = $written;

            if ($roleCreated) {
                $this->userStorage->loadRoles();
            }

            if ($created) {
                $this->userStorage->setAdmin($admin->email, $admin->id);
                $this->createdItemsCount++;
            } else {
                $this->updatedItemsCount++;
            }
        }

        return true;
    }

    /**
     * Writes one user row (and the role it rewrites or creates) to the target and role lockedRowSubjects()
     * resolved for its check. Runs inside writeUnderAdminLock(); the caller updates the in-memory storage and
     * counts once the transaction has committed.
     *
     * @return array{0: bool, 1: bool, 2: mixed} whether the admin was created, whether a role was created, the admin
     */
    private function writeUserRow(array $rowData, ?Admin $target, ?Role $role): array
    {
        $roleId = $role?->id;
        $roleCreated = false;

        if (! empty($rowData['permission_type'])) {
            $roleData = [
                'name'            => $rowData['role_name'],
                'permission_type' => $rowData['permission_type'],
                'permissions'     => empty($rowData['permissions']) ? null : explode(',', $rowData['permissions']),
            ];

            if ($role) {
                $this->roleRepository->updateModel($role, $roleData);
            } else {
                $role = $this->roleRepository->create($roleData);
                $roleId = $role->id;
                $roleCreated = true;
            }
        }

        $data = [
            'name'         => EscapeFormulaOperators::unescapeValue($rowData['name']),
            'email'        => $rowData['email'],
            'status'       => ($rowData['status'] ?? 'active') == 'active' ? 1 : 0,
            'role_id'      => $roleId,
            'timezone'     => $rowData['timezone'] ?? config('app.timezone'),
            'ui_locale_id' => $this->userStorage->getLocaleId($rowData['ui_locale_code'] ?? ''),
        ];

        // Writes go to the locked models themselves (Repository::updateModel()): update() would re-find them with a
        // plain read, which under REPEATABLE READ answers from the import's snapshot — missing a row committed
        // since, or reading a since-changed column as its old value, so writing that old value back is not dirty
        // and is dropped (UX eval rev-C4).
        if ($target) {
            if ($imagePath = $this->storeUserImage($rowData['image'] ?? null, $target)) {
                $data['image'] = $imagePath;
            }

            $this->adminRepository->updateModel($target, $data);

            return [false, $roleCreated, $target];
        }

        $data['password'] = null;

        $admin = $this->adminRepository->create($data);

        if ($imagePath = $this->storeUserImage($rowData['image'] ?? null, $admin)) {
            $this->adminRepository->updateModel($admin, ['image' => $imagePath]);
        }

        return [true, $roleCreated, $admin];
    }

    protected function storeUserImage(?string $image, mixed $admin): ?string
    {
        $image = $this->resolveImportImagePath($image);

        if (! $image || ! StorageFacade::disk('public')->exists($image)) {
            return null;
        }

        $newPath = $this->fileStorer->storeAs(
            path: 'admins'.DIRECTORY_SEPARATOR.$admin->id,
            name: basename($image),
            file: new File(StorageFacade::disk('public')->path($image)),
            options: ['disk' => 'public']
        );

        if (
            ! empty($admin->image)
            && $admin->image !== $newPath
            && StorageFacade::disk('public')->exists($admin->image)
        ) {
            StorageFacade::disk('public')->delete($admin->image);
        }

        return $newPath;
    }

    protected function resolveImportImagePath(?string $image): ?string
    {
        $image = $image ? ltrim(trim($image), '/') : null;

        if (! $image) {
            return null;
        }

        $basePath = trim((string) ($this->import->images_directory_path ?? ''), '/');

        return $basePath !== '' && $basePath !== '0' ? $basePath.'/'.$image : $image;
    }
}
