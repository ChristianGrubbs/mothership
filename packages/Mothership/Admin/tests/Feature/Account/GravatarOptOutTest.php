<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\DataGrids\Settings\UserDataGrid;
use Mothership\Core\Models\Locale;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

describe('Gravatar opt-out persistence', function () {
    it('persists the gravatar opt-out from the account form and back', function () {
        $this->loginAsAdmin();

        $admin = auth()->guard('admin')->user();

        $payload = [
            'name'             => $admin->name,
            'email'            => $admin->email,
            'current_password' => 'password',
            'timezone'         => 'UTC',
            'ui_locale_id'     => $admin->ui_locale_id,
        ];

        $this->put(route('admin.account.update'), $payload + ['use_gravatar' => 0])->assertOk();
        expect($admin->fresh()->use_gravatar)->toBeFalse();

        $this->put(route('admin.account.update'), $payload + ['use_gravatar' => 1])->assertOk();
        expect($admin->fresh()->use_gravatar)->toBeTrue();
    });

    it('does not persist the gravatar as an uploaded image on account save', function () {
        $this->loginAsAdmin();

        $admin = auth()->guard('admin')->user();

        expect($admin->image)->toBeNull();

        $this->put(route('admin.account.update'), [
            'name'             => $admin->name,
            'email'            => $admin->email,
            'current_password' => 'password',
            'timezone'         => 'UTC',
            'ui_locale_id'     => $admin->ui_locale_id,
            'use_gravatar'     => 1,
        ])->assertOk();

        expect($admin->fresh()->image)->toBeNull();
    });

    it('defaults use_gravatar to false when creating a user without the field, so no lookup leaves the server unasked', function () {
        $this->loginAsAdmin();

        $role = Role::firstOrFail();

        $this->postJson(route('admin.settings.users.store'), [
            'name'                  => 'No Field User',
            'email'                 => 'no.field@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role_id'               => $role->id,
            'ui_locale_id'          => Locale::where('code', 'en_US')->value('id'),
            'timezone'              => 'UTC',
            'status'                => 1,
        ])->assertOk();

        expect(Admin::where('email', 'no.field@example.com')->value('use_gravatar'))->toBeFalse();
    });

    it('stores false for rows written without the field (installer, default user), and leaves stored true values alone', function () {
        $this->loginAsAdmin();

        $role = Role::firstOrFail();
        $row = fn (string $email, array $extra = []) => [
            'name'   => $email, 'email' => $email, 'password' => bcrypt('x'), 'api_token' => $email, 'role_id' => $role->id,
            'status' => 1, 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ] + $extra;

        // The query-builder paths (Installer seeder, DefaultUser, installer wizard) never name the column.
        DB::table('admins')->insert($row('raw.insert@example.com'));
        DB::table('admins')->updateOrInsert(['email' => 'raw.upsert@example.com'], $row('raw.upsert@example.com'));
        // Decision: existing accounts keep their stored value; only the default changed.
        DB::table('admins')->insert($row('legacy.true@example.com', ['use_gravatar' => true]));

        expect((bool) Admin::where('email', 'raw.insert@example.com')->value('use_gravatar'))->toBeFalse()
            ->and((bool) Admin::where('email', 'raw.upsert@example.com')->value('use_gravatar'))->toBeFalse()
            ->and((bool) Admin::where('email', 'legacy.true@example.com')->value('use_gravatar'))->toBeTrue();
    });

    it('persists an explicit gravatar opt-out when creating a user', function () {
        $this->loginAsAdmin();

        $role = Role::firstOrFail();

        $this->postJson(route('admin.settings.users.store'), [
            'name'                  => 'Opted Out User',
            'email'                 => 'opted.out@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role_id'               => $role->id,
            'ui_locale_id'          => Locale::where('code', 'en_US')->value('id'),
            'timezone'              => 'UTC',
            'status'                => 1,
            'use_gravatar'          => 0,
        ])->assertOk();

        expect(Admin::where('email', 'opted.out@example.com')->value('use_gravatar'))->toBeFalse();
    });

    it('omits the gravatar in the user datagrid for opted-out users', function () {
        $this->loginAsAdmin();

        $optedIn = Admin::factory()->create(['image' => null, 'use_gravatar' => true]);
        $optedOut = Admin::factory()->create(['image' => null, 'use_gravatar' => false]);

        // The grid only renders a gravatar the cached upstream lookup already confirmed, so prime
        // both rows: the opted-out row must then stay empty because of the flag, not a cache miss.
        foreach ([$optedIn, $optedOut] as $user) {
            Cache::put('admin.gravatar.'.md5(mb_strtolower(trim($user->email))), [
                'found'        => true,
                'body'         => '',
                'content_type' => 'image/png',
            ]);
        }

        $records = collect(app(UserDataGrid::class)->toJson()->getData(true)['records']);

        $in = $records->firstWhere('user_id', $optedIn->id);
        $out = $records->firstWhere('user_id', $optedOut->id);

        expect($in['user_img'])->toContain('avatar/u/');
        expect($out['user_img'])->toBeNull();
    });
});
