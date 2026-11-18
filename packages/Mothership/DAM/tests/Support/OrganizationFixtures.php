<?php

/**
 * P4 (Library organization) fixtures, shared by the label, board, saved-search, section, sort, orientation and REST
 * spec files. Each of those files require_once's this file itself (Pest only loads the files it is given), so neither
 * tests/Pest.php nor DamTestHelpers.php changes for this slice.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

if (! function_exists('organizeRole')) {
    /** A custom role with the Library and, unless left out, every P4 permission. */
    function organizeRole(array $without = []): Role
    {
        $permissions = ['dam', 'dam.asset', 'dam.asset.view', 'dam.labels', 'dam.labels.manage', 'dam.labels.assign', 'dam.sections', 'dam.sections.manage'];

        return Role::factory()->create([
            'permission_type' => 'custom',
            'permissions'     => array_values(array_diff($permissions, $without)),
        ]);
    }
}

if (! function_exists('organizeGrant')) {
    /** Grants the role a folder (the `grants` access model) and drops the cached reader. */
    function organizeGrant(Role $role, Directory $directory): void
    {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        app(DirectoryPermissionService::class)->flush();
    }
}

if (! function_exists('organizeRevoke')) {
    function organizeRevoke(Role $role, Directory $directory): void
    {
        DB::table('dam_directory_role')->where('directory_id', $directory->id)->where('role_id', $role->id)->delete();
        app(DirectoryPermissionService::class)->flush();
    }
}

if (! function_exists('organizeMember')) {
    /** An admin on a restricted role granted `$directory` only, signed in on the admin guard. */
    function organizeMember(Directory $directory, array $without = []): Admin
    {
        $role = organizeRole($without);
        organizeGrant(role: $role, directory: $directory);
        $admin = Admin::factory()->create(['status' => 1, 'role_id' => $role->id]);
        test()->actingAs($admin, 'admin');

        return $admin;
    }
}

if (! function_exists('organizeAsset')) {
    /**
     * An asset filed in `$directory`. `$size` [width, height] becomes its embedded metadata, which is what the
     * orientation reads at ingest.
     */
    function organizeAsset(Directory $directory, string $name, array $attributes = [], ?array $size = null): Asset
    {
        $fileName = $name.'-'.uniqid().'.'.($attributes['extension'] ?? 'jpg');
        $asset = Asset::factory()->create([
            'file_name' => $fileName,
            'path'      => 'assets/'.$directory->name.'/'.$fileName,
            ...($size !== null ? ['meta_data' => ['ImageWidth' => $size[0], 'ImageHeight' => $size[1]]] : []),
            ...$attributes,
        ]);
        $directory->assets()->attach($asset->id);

        return $asset;
    }
}

if (! function_exists('organizeListedIds')) {
    /**
     * The asset ids the Library listing returns for these parameters, in order (scope all, files only).
     *
     * @return array<int, int>
     */
    function organizeListedIds(array $params = [], ?TestResponse &$response = null): array
    {
        $response = test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 250, ...$params]))->assertOk();

        return array_map('intval', array_column($response->json('assets'), 'id'));
    }
}

if (! function_exists('organizeExpectSameAnswer')) {
    /** A refused request answers byte for byte as the request for a missing id does. */
    function organizeExpectSameAnswer(TestResponse $hidden, TestResponse $missing, int $status, string $what): void
    {
        expect($hidden->getStatusCode())->toBe($status, $what)
            ->and($missing->getStatusCode())->toBe($status, $what)
            ->and($hidden->getContent())->toBe($missing->getContent(), $what);
    }
}

if (! function_exists('organizeApiHeaders')) {
    /**
     * Bearer headers for an API key (permission type `all`, or custom `$permissions`) whose admin is on `$role`. The
     * admin web guard is signed out, so every read is for the key's admin, as on a real REST request.
     */
    function organizeApiHeaders(Role $role, ?array $permissions = null): array
    {
        $headers = test()->getAuthenticationHeaders($permissions === null ? 'all' : 'custom', $permissions);
        $caller = Admin::query()->latest('id')->first();
        $caller->update(['role_id' => $role->id, 'status' => 1]);
        // Every guard resolves afresh, as on a new request: the test app keeps a resolved api user between requests.
        app('auth')->forgetGuards();
        app(DirectoryPermissionService::class)->flush();
        test()->apiCaller = $caller;

        return $headers;
    }
}
