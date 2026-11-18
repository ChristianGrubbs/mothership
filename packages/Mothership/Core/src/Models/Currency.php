<?php

namespace Mothership\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Mothership\Core\Contracts\Currency as CurrencyContract;
use Mothership\Core\Database\Factories\CurrencyFactory;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Symfony\Component\Intl\Currencies;

#[Appends([
    'name',
])]
#[Fillable([
    'code',
    'symbol',
    'decimal',
    'status',
])]
class Currency extends Model implements CurrencyContract, HistoryAuditable
{
    use HasFactory;
    use HistoryTrait;

    /**
     * @var array<int, string>
     */
    protected array $historyTags = ['currency'];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    /**
     * Set currency code in capital
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn ($code): array => ['code' => strtoupper((string) $code)]);
    }

    /**
     * Get the exchange rate associated with the currency.
     */
    public function exchange_rate(): HasOne
    {
        return $this->hasOne(CurrencyExchangeRateProxy::modelClass(), 'target_currency');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return CurrencyFactory::new();
    }

    /**
     * Get the associated channels with this currency
     */
    public function channel(): BelongsToMany
    {
        return $this->belongsToMany(ChannelProxy::modelClass(), 'channel_currencies');
    }

    /**
     * checks whether this currency is linked to any channel
     */
    public function isCurrencyBeingUsed(): bool
    {
        return $this->channel()->exists();
    }

    /**
     * Accessor function for name property gets called whenever the name attribute is used
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes) {
                try {
                    return Currencies::getName($attributes['code'], \Locale::getPrimaryLanguage(app()->getLocale()));
                } catch (\Exception) {
                    return $attributes['code'];
                }
            }
        )->shouldCache();
    }
}
