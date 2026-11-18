<?php

namespace Mothership\Attribute\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Mothership\Attribute\Contracts\AttributeGroupTranslation as AttributeGroupTranslationContract;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Traits\HistoryTrait;

#[Fillable(['name'])]
#[WithoutTimestamps]
class AttributeGroupTranslation extends Model implements AttributeGroupTranslationContract, HistoryAuditable
{
    use HistoryTrait;

    protected $historyTags = ['attributeGroup'];

    /**
     * Key => Label for history
     */
    protected $historyTranslatableFields = [
        'name' => 'Name',
    ];

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->attribute_group_id;
    }
}
