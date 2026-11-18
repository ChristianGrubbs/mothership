<?php

declare(strict_types=1);

namespace Mothership\DAM\Observers;

use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\AssetProperty;

/** Property names and values are search words; query-builder mass deletes bypass this and are caught by the nightly reconcile. */
class AssetPropertyObserver
{
    public function created(AssetProperty $property): void
    {
        $this->refresh($property);
    }

    public function updated(AssetProperty $property): void
    {
        $this->refresh($property);
    }

    public function deleted(AssetProperty $property): void
    {
        $this->refresh($property);
    }

    private function refresh(AssetProperty $property): void
    {
        AiIndexDispatcher::lexical([(int) $property->dam_asset_id]);
    }
}
