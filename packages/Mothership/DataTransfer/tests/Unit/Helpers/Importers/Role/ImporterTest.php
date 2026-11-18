<?php

namespace Mothership\DataTransfer\Tests\Unit\Helpers\Importers\Role;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mothership\DataTransfer\Helpers\Error;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\Role\Importer;
use Mothership\DataTransfer\Helpers\Source;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\DataTransfer\Models\JobTrackBatch;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

describe('Role Importer', function () {
    beforeEach(function () {
        $this->loginAsAdmin();
    });

    it('can validate a row for role import', function () {
        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_APPEND,
        ]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $validRow = [
            'name'            => 'Test Role',
            'permission_type' => 'all',
            'description'     => 'Test Description',
        ];

        expect($importer->validateRow($validRow, 1))->toBeTrue();

        $invalidRow = [
            'name'            => '',
            'permission_type' => 'invalid',
        ];

        expect($importer->validateRow($invalidRow, 2))->toBeFalse();
    });

    it('can save role data during importBatch', function () {
        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_APPEND,
        ]);

        $rolesData = [
            [
                'name'            => 'New Role '.uniqid(),
                'description'     => 'New Description',
                'permission_type' => 'all',
                'permissions'     => '',
            ],
            [
                'name'            => 'Custom Role '.uniqid(),
                'description'     => 'Custom Description',
                'permission_type' => 'custom',
                'permissions'     => 'dashboard,settings',
            ],
        ];

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => $rolesData,
        ]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $importer->importBatch($batch);

        foreach ($rolesData as $roleData) {
            $this->assertDatabaseHas('roles', [
                'name'            => $roleData['name'],
                'permission_type' => $roleData['permission_type'],
            ]);

            if ($roleData['permission_type'] === 'custom') {
                $role = Role::where('name', $roleData['name'])->first();
                expect($role->permissions)->toBe(['dashboard', 'settings']);
            }
        }
    });

    it('can delete role data during importBatch', function () {
        $roleName = 'Delete Me '.uniqid();
        $role = Role::create([
            'name'            => $roleName,
            'permission_type' => 'all',
        ]);

        $jobTrack = JobTrack::factory()->fromConsole()->create([
            'action' => Import::ACTION_DELETE,
        ]);

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => [['name' => $roleName]],
        ]);

        $importer = app(Importer::class);
        $importer->setImport($jobTrack);
        $importer->setErrorHelper(app(Error::class));

        $source = mock(Source::class);
        $source->shouldReceive('getColumnNames')->andReturn(['name']);
        $importer->setSource($source);

        $importer->validateData();

        $importer->importBatch($batch);

        $this->assertDatabaseMissing('roles', ['name' => $roleName]);
    });
});

/**
 * Role rows answer to the role screens' rules (UX eval 2026-10-10, PERM-07/PERM-08): an actor may not create,
 * rewrite or delete a role carrying access they lack, and no row may leave no active full-access admin.
 */
describe('Role Importer access rules', function () {
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

    it('refuses a custom-role actor creating an all-access role, granting unheld permissions or rewriting or deleting a stronger role', function () use ($importer) {
        $name = 'Escalated '.uniqid();

        [$valid, $codes] = $importer($this->manager->id, Import::ACTION_APPEND, [
            ['name' => $name, 'permission_type' => 'all'],
            ['name' => $name.'b', 'permission_type' => 'custom', 'permissions' => 'dashboard,settings,catalog'],
            ['name' => $this->allRole->name, 'permission_type' => 'custom', 'permissions' => 'dashboard'],
        ]);

        expect($valid)->toBe([false, false, false])
            ->and($codes)->toBe([Importer::ERROR_OUTRANKED])
            ->and($this->allRole->fresh()->permission_type)->toBe('all');
        $this->assertDatabaseMissing('roles', ['name' => $name]);
        $this->assertDatabaseMissing('roles', ['name' => $name.'b']);

        [$valid] = $importer($this->manager->id, Import::ACTION_DELETE, [['name' => $this->allRole->name]]);

        expect($valid)->toBe([false]);
        $this->assertDatabaseHas('roles', ['id' => $this->allRole->id]);
    });

    it('lets a custom-role actor import a role inside their own access', function () use ($importer) {
        $name = 'Narrow '.uniqid();

        [$valid] = $importer($this->manager->id, Import::ACTION_APPEND, [
            ['name' => $name, 'permission_type' => 'custom', 'permissions' => 'dashboard'],
        ]);

        expect($valid)->toBe([true]);
        $this->assertDatabaseHas('roles', ['name' => $name, 'permission_type' => 'custom']);
    });

    it('refuses turning custom or deleting the role of the last active full admin, even from a full admin', function () use ($importer) {
        [$valid, $codes] = $importer($this->fullAdmin->id, Import::ACTION_APPEND, [
            ['name' => $this->allRole->name, 'permission_type' => 'custom', 'permissions' => 'dashboard'],
        ]);

        expect($valid)->toBe([false])
            ->and($codes)->toBe([Importer::ERROR_LAST_FULL_ADMIN])
            ->and($this->allRole->fresh()->permission_type)->toBe('all');

        [$valid] = $importer($this->fullAdmin->id, Import::ACTION_DELETE, [['name' => $this->allRole->name]]);

        expect($valid)->toBe([false]);
        $this->assertDatabaseHas('roles', ['id' => $this->allRole->id]);
    });

    it('refuses every privileged row of a job whose admin was deleted after starting it', function () use ($importer) {
        $actor = Admin::create([
            'name'    => 'Gone', 'email' => 'gone'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $this->allRole->id, 'status' => 1,
        ]);
        $jobTrack = JobTrack::factory()->create(['action' => Import::ACTION_APPEND, 'user_id' => $actor->id]);

        // job_track.user_id is ON DELETE SET NULL: the job must not turn into a console job.
        $actor->delete();
        $jobTrack->refresh();
        expect($jobTrack->user_id)->toBeNull()->and($jobTrack->console_authority)->toBeNull();

        $name = 'Orphan '.uniqid();

        [$valid] = $importer($jobTrack, Import::ACTION_APPEND, [
            ['name' => $name, 'permission_type' => 'all'],
            ['name' => $this->managerRole->name, 'permission_type' => 'all'],
        ]);

        expect($valid)->toBe([false, false])
            ->and($this->managerRole->fresh()->permission_type)->toBe('custom');
        $this->assertDatabaseMissing('roles', ['name' => $name]);
    });

    it('refuses a later row once an earlier row in the same job took the authority it needs away', function () use ($importer) {
        $otherAll = Role::create(['name' => 'Other All '.uniqid(), 'permission_type' => 'all']);
        Admin::create([
            'name'    => 'Second Full', 'email' => 'full2'.uniqid().'@example.com', 'password' => Hash::make('x'),
            'role_id' => $otherAll->id, 'status' => 1,
        ]);
        $name = 'Late All '.uniqid();

        // Both rows pass validation; the first turns the actor's own role custom, so the second must not land.
        [$valid] = $importer($this->fullAdmin->id, Import::ACTION_APPEND, [
            ['name' => $this->allRole->name, 'permission_type' => 'custom', 'permissions' => 'dashboard'],
            ['name' => $name, 'permission_type' => 'all'],
        ]);

        expect($valid)->toBe([true, true])
            ->and($this->allRole->fresh()->permission_type)->toBe('custom');
        $this->assertDatabaseMissing('roles', ['name' => $name]);
    });
});
