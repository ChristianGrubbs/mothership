<?php

namespace Mothership\DataTransfer\Helpers\Importers\Role;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Mothership\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Mothership\DataTransfer\Helpers\Formatters\EscapeFormulaOperators;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\AbstractImporter;
use Mothership\DataTransfer\Helpers\Importers\Concerns\GuardsAdminAccess;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;
use Mothership\User\Repositories\RoleRepository;

class Importer extends AbstractImporter
{
    use GuardsAdminAccess;

    /**
     * Error code for duplicated name
     */
    public const ERROR_DUPLICATE_NAME = 'duplicate_name';

    /**
     * Error code for non existing name
     */
    public const ERROR_CODE_NOT_FOUND_FOR_DELETE = 'name_not_found_to_delete';

    /**
     * Permanent entity columns
     */
    protected array $validColumnNames = [
        'name',
        'description',
        'permission_type',
        'permissions',
    ];

    /**
     * Permanent entity columns
     */
    protected array $permanentAttributes = ['name', 'permission_type'];

    /**
     * Error message templates
     */
    protected array $messages = [
        self::ERROR_DUPLICATE_NAME            => 'data_transfer::app.importers.roles.validation.errors.duplicate-name',
        self::ERROR_CODE_NOT_FOUND_FOR_DELETE => 'data_transfer::app.importers.roles.validation.errors.name-not-found-to-delete',
        self::ERROR_OUTRANKED                 => 'data_transfer::app.importers.roles.validation.errors.outranked',
        self::ERROR_LAST_FULL_ADMIN           => 'data_transfer::app.importers.roles.validation.errors.last-full-admin',
    ];

    /**
     * Create a new helper instance.
     */
    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected RoleRepository $roleRepository,
        protected Storage $roleStorage,
        protected AdminRepository $adminRepository
    ) {
        parent::__construct($importBatchRepository);
    }

    protected function initErrorMessages(): void
    {
        foreach ($this->messages as $errorCode => $message) {
            $this->errorHelper->addErrorMessage($errorCode, trans($message));
        }

        parent::initErrorMessages();
    }

    public function validateData(): void
    {
        $this->roleStorage->init();

        parent::validateData();
    }

    public function validateRow(array $rowData, int $rowNumber): bool
    {
        if (isset($this->validatedRows[$rowNumber])) {
            return ! $this->errorHelper->isRowInvalid($rowNumber);
        }

        $this->validatedRows[$rowNumber] = true;

        if ($this->import->action == Import::ACTION_DELETE) {
            if (! $this->roleStorage->get($rowData['name'])) {
                $this->skipRow($rowNumber, self::ERROR_CODE_NOT_FOUND_FOR_DELETE, $rowData['name']);

                return false;
            }

            if ($refusal = $this->deleteRefusal($this->roleStorage->get($rowData['name']), $this->import)) {
                $this->skipRow($rowNumber, $refusal, 'name');

                return false;
            }

            return true;
        }

        $validator = Validator::make($rowData, [
            'name'            => ['required', 'string'],
            'permission_type' => ['required', 'in:all,custom'],
            'description'     => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->getMessages() as $attributeCode => $message) {
                $this->skipRow($rowNumber, 'invalid_field', $attributeCode, current($message));
            }
        }

        if (! $this->errorHelper->isRowInvalid($rowNumber) && $refusal = $this->saveRefusal($rowData, $this->import)) {
            $this->skipRow($rowNumber, $refusal, 'permission_type');
        }

        return ! $this->errorHelper->isRowInvalid($rowNumber);
    }

    protected function adminAccessRepository(): AdminRepository
    {
        return $this->adminRepository;
    }

    /**
     * Error code when the job's actor may not delete this role (it carries access they do not hold) or when
     * deleting it leaves no active full-access admin; null when it may go. Asked at validation and again
     * before each write.
     */
    protected function deleteRefusal(?int $roleId, mixed $jobTrack): ?string
    {
        $role = $roleId ? Role::query()->lockForUpdate()->find($roleId) : null;

        if (! $role) {
            return null;
        }

        if ($this->roleGrantErrorFor($jobTrack, $role->permission_type, $role->permissions)) {
            return self::ERROR_OUTRANKED;
        }

        if ($role->permission_type === 'all' && ! $this->activeFullAdminRemains(withoutRoleIds: [$role->id])) {
            return self::ERROR_LAST_FULL_ADMIN;
        }

        return null;
    }

    /**
     * Error code when the job's actor may not write this row — the role it rewrites, or the role it would
     * leave, carries access they do not hold — or when turning an all-access role custom leaves no active
     * full-access admin; null when the row may be written.
     */
    protected function saveRefusal(array $rowData, mixed $jobTrack, ?Role $role = null, bool $resolved = false): ?string
    {
        $role = $resolved ? $role : $this->lockedRoleNamed((string) $rowData['name']);
        $permissions = empty($rowData['permissions']) ? [] : explode(',', $rowData['permissions']);

        if ($role && $this->roleGrantErrorFor($jobTrack, $role->permission_type, $role->permissions)) {
            return self::ERROR_OUTRANKED;
        }

        if ($this->roleGrantErrorFor($jobTrack, $rowData['permission_type'] ?? null, $permissions)) {
            return self::ERROR_OUTRANKED;
        }

        if (
            $role
            && $role->permission_type === 'all'
            && ($rowData['permission_type'] ?? null) !== 'all'
            && ! $this->activeFullAdminRemains(withoutRoleIds: [$role->id])
        ) {
            return self::ERROR_LAST_FULL_ADMIN;
        }

        return null;
    }

    /**
     * The role with this name, read with a locking read: the write step runs inside the import's outer
     * transaction, whose plain reads — the storage's name map included — may answer from an older REPEATABLE
     * READ snapshot (MySQL/MariaDB), when another role had this name. The write step decides on this role and
     * writes to exactly this role (UX eval rev-C3, finding 1).
     */
    private function lockedRoleNamed(string $name): ?Role
    {
        return Role::query()->where('name', $name)->lockForUpdate()->first();
    }

    public function importBatch(JobTrackBatchContract $batch): bool
    {
        Event::dispatch('data_transfer.imports.batch.import.before', $batch);

        if ($batch->jobTrack->action == Import::ACTION_DELETE) {
            $this->deleteRoleData($batch);
        } else {
            $this->saveRoleData($batch);
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

    protected function deleteRoleData(JobTrackBatchContract $batch): bool
    {
        foreach (array_unique(Arr::pluck($batch->data, 'name')) as $name) {
            // Looked up by name under the lock, not from the storage's snapshot-era map.
            $id = null;
            $role = null;

            [$refusal] = $this->writeUnderAdminLock(
                refusal: function () use ($name, $batch, &$id, &$role): ?string {
                    $role = $this->lockedRoleNamed((string) $name);
                    $id = $role?->id;

                    return $id ? $this->deleteRefusal((int) $id, $batch->jobTrack) : null;
                },
                // The locked model itself: RoleRepository::delete() would re-find it with a plain read, which under
                // REPEATABLE READ can miss a role committed after the import's snapshot (UX eval rev-C4).
                write: function () use (&$role) {
                    return $role ? $this->roleRepository->deleteModel($role) : null;
                },
            );

            if ($refusal) {
                $this->logRefusedRow($refusal, "role #{$id}");

                continue;
            }

            if ($id) {
                $this->deletedItemsCount++;
            }
        }

        return true;
    }

    protected function saveRoleData(JobTrackBatchContract $batch): bool
    {
        $this->roleStorage->load(Arr::pluck($batch->data, 'name'));

        foreach ($batch->data as $rowData) {
            $data = [
                'name'            => EscapeFormulaOperators::unescapeValue($rowData['name']),
                'description'     => EscapeFormulaOperators::unescapeValue($rowData['description'] ?? ''),
                'permission_type' => $rowData['permission_type'],
                'permissions'     => empty($rowData['permissions']) ? [] : explode(',', $rowData['permissions']),
            ];

            // The role is resolved once, under the lock, and the write uses exactly that one.
            $role = null;

            [$refusal, $created] = $this->writeUnderAdminLock(
                refusal: function () use ($rowData, $batch, &$role): ?string {
                    $role = $this->lockedRoleNamed((string) $rowData['name']);

                    return $this->saveRefusal($rowData, $batch->jobTrack, role: $role, resolved: true);
                },
                write: function () use ($data, &$role) {
                    if ($role) {
                        // The locked model itself (Repository::updateModel()), not a plain re-find by id.
                        $this->roleRepository->updateModel($role, $data);

                        return null;
                    }

                    return $this->roleRepository->create($data);
                },
            );

            if ($refusal) {
                $this->logRefusedRow($refusal, (string) $rowData['name']);

                continue;
            }

            if ($created) {
                $this->roleStorage->set($created->name, $created->id);
                $this->createdItemsCount++;
            } else {
                $this->updatedItemsCount++;
            }
        }

        return true;
    }
}
