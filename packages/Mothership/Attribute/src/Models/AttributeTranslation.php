<?php

namespace Mothership\Attribute\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Mothership\Attribute\Contracts\AttributeTranslation as AttributeTranslationContract;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;

#[Fillable(['name', 'instructions'])]
#[WithoutTimestamps]
class AttributeTranslation extends Model implements AttributeTranslationContract, HistoryContract
{
    use HistoryTrait;

    /**
     * These columns history will not be generated
     */
    protected $auditExclude = [
        'id',
        'locale',
        'attribute_id',
    ];

    protected $historyTags = ['attribute'];

    /**
     * Key => Label for history
     */
    protected $historyTranslatableFields = [
        'name'         => 'Name',
        'instructions' => 'Instructions',
    ];

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->attribute_id;
    }
}
