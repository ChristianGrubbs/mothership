<?php

namespace Mothership\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\Product\Contracts\AssociationTypeTranslation as AssociationTypeTranslationContract;

class AssociationTypeTranslation extends Model implements AssociationTypeTranslationContract, HistoryContract
{
    use HistoryTrait;

    public $timestamps = false;

    protected $fillable = ['locale', 'name'];

    /** Tags for History */
    protected $historyTags = ['association_type'];

    /**
     * For Multilocale values history to display correctly
     */
    protected $historyTranslatableFields = ['name' => 'Name'];

    /**
     * These columns history will not be generated
     */
    protected $auditExclude = [
        'association_type_id',
        'id',
    ];

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->association_type_id;
    }
}
