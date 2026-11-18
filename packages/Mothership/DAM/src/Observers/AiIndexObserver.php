<?php

declare(strict_types=1);

namespace Mothership\DAM\Observers;

use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\Asset;

/** New content → full index (replacements: CompoundAssetObserver); a rename or Library-path change → words only (spec §2 triggers). */
class AiIndexObserver
{
    public function created(Asset $asset): void
    {
        AiIndexDispatcher::full((int) $asset->id);
    }

    public function updated(Asset $asset): void
    {
        // Replaced content is indexed by CompoundAssetObserver once the graph transition commits.
        if ($asset->wasChanged(['file_name', 'path', 'meta_data'])) {
            AiIndexDispatcher::lexical([(int) $asset->id]);
        }
    }
}
