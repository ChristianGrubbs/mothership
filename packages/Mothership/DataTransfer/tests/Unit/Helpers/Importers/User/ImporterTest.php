<?php

namespace Mothership\DataTransfer\Tests\Unit\Helpers\Importers\User;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mothership\Core\Models\Locale;
use Mothership\DataTransfer\Helpers\Error;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\User\Importer;
use Mothership\DataTransfer\Helpers\Source;
use Mothership\DataTransfer\Models\JobInstances;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\DataTransfer\Models\JobTrackBatch;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Psr\Log\LoggerInterface;

describe('User Importer', function () {
    beforeEach(function () {
        $this->loginAsAdmin();

        $this->role = Role::first() ?: Role::create(['name' => 'Administrator', 'permission_type' => 'all']);
        $this->locale = Locale::first() ?: Locale::create(['code' => 'en_US', 'name' => 'English']);
    });

    it('can validate a row for user import (including role permissions)', function () {
        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_APPEND,
        ]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn($importer->getValidColumnNames());
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);

        $importer->validateData();

        $validRow = [
            'name'            => 'Test User',
            'email'           => 'test@example.com',
            'role_name'       => $this->role->name,
            'permission_type' => 'all',
            'status'          => 'active',
            'ui_locale_code'  => $this->locale->code,
        ];

        expect($importer->validateRow($validRow, 1))->toBeTrue();

        $invalidRow = [
            'name'            => '',
            'email'           => 'invalid-email',
            'role_name'       => 'Some Role',
            'permission_type' => 'invalid',
        ];

        expect($importer->validateRow($invalidRow, 2))->toBeFalse();
    });

    it('can create a role and user during importBatch', function () {
        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_APPEND,
        ]);

        $roleName = 'New Manager '.uniqid();
        $usersData = [
            [
                'name'            => 'New User '.uniqid(),
                'email'           => 'new'.uniqid().'@example.com',
                'status'          => 'active',
                'role_name'       => $roleName,
                'permission_type' => 'custom',
                'permissions'     => 'dashboard,catalog.products',
                'ui_locale_code'  => $this->locale->code,
            ],
        ];

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn($importer->getValidColumnNames());
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);

        $importer->validateData();

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => $usersData,
        ]);

        $importer->importBatch($batch);

        $this->assertDatabaseHas('roles', [
            'name'            => $roleName,
            'permission_type' => 'custom',
        ]);

        $role = Role::where('name', $roleName)->first();
        expect($role->permissions)->toEqual(['dashboard', 'catalog.products']);

        foreach ($usersData as $userData) {
            $this->assertDatabaseHas('admins', [
                'email'   => $userData['email'],
                'role_id' => $role->id,
            ]);

            $admin = Admin::where('email', $userData['email'])->first();
            expect($admin->password)->toBeNull();
        }
    });

    it('can update role permissions during user import', function () {
        $roleName = 'Existing Role '.uniqid();
        $role = Role::create([
            'name'            => $roleName,
            'permission_type' => 'all',
        ]);

        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_APPEND,
        ]);

        $usersData = [
            [
                'name'            => 'Update Role User',
                'email'           => 'updater'.uniqid().'@example.com',
                'role_name'       => $roleName,
                'permission_type' => 'custom',
                'permissions'     => 'catalog.categories',
            ],
        ];

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn($importer->getValidColumnNames());
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);

        $importer->validateData();

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => $usersData,
        ]);

        $importer->importBatch($batch);

        $role->refresh();
        expect($role->permission_type)->toBe('custom');
        expect($role->permissions)->toEqual(['catalog.categories']);
    });

    it('imports only active users when the import profile filter is active', function () {
        $jobInstance = JobInstances::factory()->create([
            'entity_type'         => 'users',
            'type'                => 'import',
            'action'              => Import::ACTION_APPEND,
            'validation_strategy' => 'skip-erros',
            'file_path'           => 'imports/users-active.csv',
            'filters'             => ['status' => 'active'],
        ]);

        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'job_instances_id' => $jobInstance->id,
            'action'           => Import::ACTION_APPEND,
        ]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack->fresh('jobInstance'));
        $importer->setErrorHelper(app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn($importer->getValidColumnNames());
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);

        $importer->validateData();

        $activeUser = [
            'name'           => 'Active Import User',
            'email'          => 'active-import-'.uniqid().'@example.com',
            'status'         => 'active',
            'role_name'      => $this->role->name,
            'ui_locale_code' => $this->locale->code,
        ];

        $inactiveUser = [
            'name'           => 'Inactive Import User',
            'email'          => 'inactive-import-'.uniqid().'@example.com',
            'status'         => 'inactive',
            'role_name'      => $this->role->name,
            'ui_locale_code' => $this->locale->code,
        ];

        expect($importer->validateRow($activeUser, 1))->toBeTrue();
        expect($importer->validateRow($inactiveUser, 2))->toBeFalse();

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => [$activeUser],
        ]);

        $importer->importBatch($batch);

        $this->assertDatabaseHas('admins', [
            'email' => $activeUser['email'],
            'name'  => $activeUser['name'],
        ]);

        $this->assertDatabaseMissing('admins', [
            'email' => $inactiveUser['email'],
        ]);
    });

    it('can delete user data during importBatch', function () {
        $email = 'delete'.uniqid().'@example.com';
        Admin::create([
            'name'     => 'Delete Me',
            'email'    => $email,
            'password' => Hash::make('password'),
            'role_id'  => $this->role->id,
        ]);

        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_DELETE,
        ]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn(['email']);
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);

        $importer->validateData();

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => [['email' => $email]],
        ]);

        $importer->importBatch($batch);

        $this->assertDatabaseMissing('admins', ['email' => $email]);
    });
});

/**
 * The importer writes admins and roles directly, so it answers to the user screens' rules (UX eval 2026-10-10,
 * PERM-07/PERM-08): the job's actor may not touch a user or hand out a role that outranks them, and no row may
 * leave the site without an active full-access admin.
 */
describe('User Importer access rules', function () {
    beforeEach(function () {
        DB::table('admins')->update(['status' => 0]);

        $this->allRole = Role::create(['name' => 'All '.uniqid(), 'permission_type' => 'all']);
        $this->fullAdmin = Admin::create([
            'name'    => 'Only Full', 'email' => 'full'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->allRole->id, 'status' => 1,
        ]);

        $this->managerRole = Role::create([
            'name' => 'Manager '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['dashboard', 'settings'],
        ]);
        $this->manager = Admin::create([
            'name'    => 'Manager', 'email' => 'mgr'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->managerRole->id, 'status' => 1,
        ]);
    });

    $importer = function (int|JobTrack $actor, string $action, array $rows): array {
        $jobTrack = $actor instanceof JobTrack
            ? $actor
            : JobTrack::factory()->create(['action' => $action, 'user_id' => $actor]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper($errors = app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn($importer->getValidColumnNames());
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);
        $importer->validateData();

        $valid = [];

        foreach ($rows as $i => $row) {
            $valid[] = $importer->validateRow($row, $i + 1);
        }

        // The write step re-checks every row on its own, so feed it the rows validation refused as well.
        $importer->importBatch(JobTrackBatch::factory()->create(['job_track_id' => $jobTrack->id, 'data' => $rows]));

        return [$valid, array_keys($errors->getAllErrorsGroupedByCode())];
    };

    $row = fn (Admin $admin, array $overrides = []): array => array_merge([
        'name' => $admin->name, 'email' => $admin->email, 'role_name' => $admin->role->name, 'status' => 'active',
    ], $overrides);

    it('refuses a custom-role actor changing a full admin, granting an all-access role or deleting a full admin', function () use ($importer, $row) {
        [$valid, $codes] = $importer($this->manager->id, Import::ACTION_APPEND, [
            $row($this->fullAdmin, ['name' => 'Renamed', 'role_name' => $this->managerRole->name]),
            ['name' => 'Sneaky', 'email' => 'sneaky'.uniqid().'@example.com', 'role_name' => $this->allRole->name, 'status' => 'active'],
            ['name' => 'Sneaky2', 'email' => 'sneaky2'.uniqid().'@example.com', 'role_name' => $this->managerRole->name, 'permission_type' => 'all'],
        ]);

        expect($valid)->toBe([false, false, false])
            ->and($codes)->toBe([Importer::ERROR_OUTRANKED]);

        $this->fullAdmin->refresh();
        expect($this->fullAdmin->name)->toBe('Only Full')
            ->and($this->fullAdmin->role_id)->toBe($this->allRole->id)
            ->and($this->managerRole->fresh()->permission_type)->toBe('custom');
        $this->assertDatabaseMissing('admins', ['name' => 'Sneaky']);
        $this->assertDatabaseMissing('admins', ['name' => 'Sneaky2']);

        [$valid] = $importer($this->manager->id, Import::ACTION_DELETE, [['email' => $this->fullAdmin->email]]);

        expect($valid)->toBe([false]);
        $this->assertDatabaseHas('admins', ['id' => $this->fullAdmin->id]);
    });

    it('lets a custom-role actor import a user with a role inside their own access', function () use ($importer) {
        $weaker = Role::create(['name' => 'Weaker '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['dashboard']]);
        $email = 'ok'.uniqid().'@example.com';

        [$valid, $codes] = $importer($this->manager->id, Import::ACTION_APPEND, [
            ['name' => 'Fine', 'email' => $email, 'role_name' => $weaker->name, 'status' => 'active'],
        ]);

        expect($valid)->toBe([true])->and($codes)->toBe([]);
        $this->assertDatabaseHas('admins', ['email' => $email, 'role_id' => $weaker->id]);
    });

    it('refuses rows that would leave no active full-access admin, even from a full admin', function () use ($importer, $row) {
        [$valid, $codes] = $importer($this->fullAdmin->id, Import::ACTION_APPEND, [
            $row($this->fullAdmin, ['status' => 'inactive']),
            $row($this->fullAdmin, ['role_name' => $this->managerRole->name]),
            $row($this->manager, ['role_name' => $this->allRole->name, 'permission_type' => 'custom', 'permissions' => 'dashboard']),
        ]);

        expect($valid)->toBe([false, false, false])
            ->and($codes)->toBe([Importer::ERROR_LAST_FULL_ADMIN]);

        $this->fullAdmin->refresh();
        expect($this->fullAdmin->status)->toBe(1)
            ->and($this->fullAdmin->role_id)->toBe($this->allRole->id)
            ->and($this->allRole->fresh()->permission_type)->toBe('all');

        [$valid] = $importer($this->fullAdmin->id, Import::ACTION_DELETE, [['email' => $this->fullAdmin->email]]);

        expect($valid)->toBe([false]);
        $this->assertDatabaseHas('admins', ['id' => $this->fullAdmin->id]);
    });

    it('refuses a row whose role validated but vanished before the guarded write', function () {
        $role = Role::create(['name' => 'Vanishing '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['dashboard']]);
        $this->manager->update(['role_id' => $role->id]);

        $jobTrack = JobTrack::factory()->create(['action' => Import::ACTION_APPEND, 'user_id' => $this->fullAdmin->id]);
        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $logger = mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, $this->manager->email) && str_contains($message, Importer::ERROR_INVALID_ROLE)
        );
        $importer->setLogger($logger);

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn($importer->getValidColumnNames());
        $source->shouldReceive('rewind');
        $source->shouldReceive('valid')->andReturn(false);
        $importer->setSource($source);
        $importer->validateData();

        // A changed name and status make a partial write visible: the whole row must be refused.
        $rows = [['name' => 'Changed Name', 'email' => $this->manager->email, 'role_name' => $role->name, 'status' => 'inactive']];

        expect($importer->validateRow($rows[0], 1))->toBeTrue();

        // Another session renames the role after validation, before the batch is written.
        DB::table('roles')->where('id', $role->id)->update(['name' => 'Renamed '.uniqid()]);

        $importer->importBatch(JobTrackBatch::factory()->create(['job_track_id' => $jobTrack->id, 'data' => $rows]));

        // The write step logs its refusal (it is not a validation error); nothing about the user changed.
        $manager = $this->manager->fresh();

        expect($manager->role_id)->toBe($role->id)
            ->and($manager->name)->toBe('Manager')
            ->and((int) $manager->status)->toBe(1);
    });

    it('fails validation for an otherwise valid row naming an unknown role', function () use ($importer, $row) {
        [$valid, $codes] = $importer($this->fullAdmin->id, Import::ACTION_APPEND, [
            $row($this->manager, ['role_name' => 'Never Existed '.uniqid()]),
        ]);

        expect($valid)->toBe([false])
            ->and($codes)->toBe([Importer::ERROR_INVALID_ROLE]);
    });

    it('lets a full admin step down once another active full admin exists', function () use ($importer, $row) {
        Admin::create([
            'name'    => 'Second Full', 'email' => 'full2'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->allRole->id, 'status' => 1,
        ]);

        [$valid] = $importer($this->fullAdmin->id, Import::ACTION_APPEND, [$row($this->fullAdmin, ['status' => 'inactive'])]);

        expect($valid)->toBe([true])->and($this->fullAdmin->fresh()->status)->toBe(0);
    });

    it('refuses every privileged row of a job whose admin was deleted after starting it', function () use ($importer, $row) {
        $actor = Admin::create([
            'name'    => 'Gone', 'email' => 'gone'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->allRole->id, 'status' => 1,
        ]);
        $jobTrack = JobTrack::factory()->create(['action' => Import::ACTION_APPEND, 'user_id' => $actor->id]);

        // job_track.user_id is ON DELETE SET NULL: the job must not turn into a console job.
        $actor->delete();
        $jobTrack->refresh();
        expect($jobTrack->user_id)->toBeNull()->and($jobTrack->console_authority)->toBeNull();

        $email = 'orphan'.uniqid().'@example.com';

        [$valid] = $importer($jobTrack, Import::ACTION_APPEND, [
            ['name' => 'Orphan', 'email' => $email, 'role_name' => $this->allRole->name, 'status' => 'active'],
            $row($this->manager, ['role_name' => $this->allRole->name]),
        ]);

        expect($valid)->toBe([false, false])
            ->and($this->manager->fresh()->role_id)->toBe($this->managerRole->id);
        $this->assertDatabaseMissing('admins', ['email' => $email]);
    });

    it('refuses a later row once an earlier row in the same job demoted or deleted the actor', function () use ($importer, $row) {
        Admin::create([
            'name'    => 'Second Full', 'email' => 'full2'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->allRole->id, 'status' => 1,
        ]);
        $email = 'late'.uniqid().'@example.com';

        // Both rows pass validation; the first demotes the actor, so the second (an all-access grant) must not land.
        [$valid] = $importer($this->fullAdmin->id, Import::ACTION_APPEND, [
            $row($this->fullAdmin, ['role_name' => $this->managerRole->name]),
            ['name' => 'Late', 'email' => $email, 'role_name' => $this->allRole->name, 'status' => 'active'],
        ]);

        expect($valid)->toBe([true, true])
            ->and($this->fullAdmin->fresh()->role_id)->toBe($this->managerRole->id);
        $this->assertDatabaseMissing('admins', ['email' => $email]);

        // A full admin deleting themselves first leaves the job no authority for the next row.
        $actor = Admin::create([
            'name'    => 'Leaving', 'email' => 'leaving'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->allRole->id, 'status' => 1,
        ]);

        [$valid] = $importer($actor->id, Import::ACTION_DELETE, [
            ['email' => $actor->email],
            ['email' => $this->manager->email],
        ]);

        expect($valid)->toBe([true, true]);
        $this->assertDatabaseMissing('admins', ['id' => $actor->id]);
        $this->assertDatabaseHas('admins', ['id' => $this->manager->id]);
    });
});
