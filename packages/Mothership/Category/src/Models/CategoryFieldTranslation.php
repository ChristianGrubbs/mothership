<?php

namespace Mothership\Category\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Mothership\Category\Contracts\CategoryFieldTranslation as CategoryFieldTranslationContract;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;

#[Fillable(['name'])]
#[WithoutTimestamps]
class CategoryFieldTranslation extends Model implements CategoryFieldTranslationContract, HistoryContract
{
    use HistoryTrait;

    /** Tags for History */
    protected $historyTags = ['category_field'];

    /**
     * For Multilocale values history to display correctly
     */
    protected $historyTranslatableFields = ['name' => 'Name'];

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->category_field_id;
    }
}
