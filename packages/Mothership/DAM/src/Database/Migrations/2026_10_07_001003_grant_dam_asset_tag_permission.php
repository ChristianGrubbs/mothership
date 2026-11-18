<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P4 D8: adding and removing tags on an asset moves from dam.asset.update to its own key, dam.asset.tag. Every
 * custom role that holds dam.asset.update today gets dam.asset.tag, so nobody loses the ability; a role saved as
 * `all` needs nothing. down() takes the key off every custom role, as no role could hold it before this ran.
 */
return new class extends Migration
{
    private const REQUIRED_KEY = 'dam.asset.update';

    private const GRANTED_KEY = 'dam.asset.tag';

    public function up(): void
    {
        $this->eachCustomRole(function (object $role, array $permissions): void {
            if (! in_array(self::REQUIRED_KEY, $permissions, true) || in_array(self::GRANTED_KEY, $permissions, true)) {
                return;
            }

            $permissions[] = self::GRANTED_KEY;
            $this->store(role: $role, permissions: $permissions);
        });
    }

    public function down(): void
    {
        $this->eachCustomRole(function (object $role, array $permissions): void {
            if (! in_array(self::GRANTED_KEY, $permissions, true)) {
                return;
            }

            $this->store(role: $role, permissions: array_values(array_diff($permissions, [self::GRANTED_KEY])));
        });
    }

    private function eachCustomRole(callable $callback): void
    {
        DB::table('roles')
            ->where('permission_type', 'custom')
            ->orderBy('id')
            ->each(function (object $role) use ($callback): void {
                $permissions = json_decode($role->permissions ?? '[]', true);

                if (is_array($permissions)) {
                    $callback($role, $permissions);
                }
            });
    }

    private function store(object $role, array $permissions): void
    {
        DB::table('roles')
            ->where('id', $role->id)
            ->update(['permissions' => json_encode(array_values(array_unique($permissions)))]);
    }
};
