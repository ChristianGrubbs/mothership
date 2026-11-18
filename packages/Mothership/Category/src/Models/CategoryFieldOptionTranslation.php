<?php

namespace Mothership\Category\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Mothership\Category\Contracts\CategoryFieldOptionTranslation as CategoryFieldOptionTranslationContract;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;

#[Fillable(['label'])]
#[WithoutTimestamps]
class CategoryFieldOptionTranslation extends Model implements CategoryFieldOptionTranslationContract, HistoryContract
{
    use HistoryTrait;

    /** Tags for History */
    protected $historyTags = ['category_field'];

    /**
     * For Multilocale values history to display correctly
     */
    protected $historyTranslatableFields = ['label' => 'Option Label'];

    /**
     * These columns history will not be generated
     */
    protected $auditExclude = [
        'category_field_option_id',
        'id',
    ];

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->category_field_option_id;
    }
}
