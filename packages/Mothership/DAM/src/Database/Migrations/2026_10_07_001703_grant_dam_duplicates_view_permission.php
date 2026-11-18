<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P5c (D16): the Similar images page has its own key, dam.duplicates.view. The Library's Suggested stacks panel, which
 * shows the same near-duplicates, needs only dam.asset.view, so every custom role that holds dam.asset.view gets the
 * new key and nobody sees less than before; a role saved as `all` needs nothing. down() takes the key off every
 * custom role, as no role could hold it before this ran.
 */
return new class extends Migration
{
    private const REQUIRED_KEY = 'dam.asset.view';

    private const GRANTED_KEY = 'dam.duplicates.view';

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
