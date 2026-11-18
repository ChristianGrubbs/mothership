<?php

namespace Mothership\Completeness\Repositories;

use Mothership\Completeness\Contracts\CompletenessSetting;
use Mothership\Core\Eloquent\Repository;

class CompletenessSettingsRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return CompletenessSetting::class;
    }
}
