<?php

namespace Mothership\User\Repositories;

use Illuminate\Support\Collection;
use Mothership\Core\Eloquent\Repository;
use Mothership\User\Contracts\AdminPromoDismissal;

class AdminPromoDismissalRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return AdminPromoDismissal::class;
    }

    /**
     * Record a dismissal for an admin (idempotent).
     */
    public function dismiss(int $adminId, string $banner, string $version = ''): void
    {
        // firstOrCreate keeps this idempotent without the check-then-insert race
        // that the unique (admin_id, banner, version) index would otherwise turn
        // into a duplicate-key error under concurrent requests.
        $this->firstOrCreate([
            'admin_id' => $adminId,
            'banner'   => $banner,
            'version'  => $version,
        ]);
    }

    /**
     * Dismissal rows (banner + version) for the given admin.
     */
    public function dismissedFor(int $adminId): Collection
    {
        return $this->findWhere(['admin_id' => $adminId], ['banner', 'version']);
    }
}
