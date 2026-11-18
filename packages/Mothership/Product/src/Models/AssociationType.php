<?php

namespace Mothership\Product\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\Core\Eloquent\TranslatableModel;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\Product\Contracts\AssociationType as AssociationTypeContract;

class AssociationType extends TranslatableModel implements AssociationTypeContract, HistoryContract
{
    use HistoryTrait;

    /** Tags for History */
    protected $historyTags = ['association_type'];

    public $translatedAttributes = ['name'];

    protected $fillable = [
        'code',
        'status',
        'position',
        'is_user_defined',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(AssociationTypeFieldProxy::modelClass())->orderBy('position');
    }
}
