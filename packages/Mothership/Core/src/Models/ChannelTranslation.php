<?php

namespace Mothership\Core\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Mothership\Core\Contracts\ChannelTranslation as ChannelTranslationContract;
use Mothership\Core\Database\Factories\ChannelTranslationFactory;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;

class ChannelTranslation extends Model implements ChannelTranslationContract, HistoryContract
{
    use HasFactory;
    use HistoryTrait;

    protected $historyTags = ['channel'];

    /**
     * Key => Label for history
     */
    protected $historyTranslatableFields = [
        'name' => 'Name',
    ];

    /**
     * Guarded.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return ChannelTranslationFactory::new();
    }

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->channel_id;
    }

    /**
     * Castable.
     */
    protected function casts(): array
    {
        return [];
    }
}
