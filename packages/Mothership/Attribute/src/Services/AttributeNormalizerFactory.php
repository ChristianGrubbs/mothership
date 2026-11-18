<?php

namespace Mothership\Attribute\Services;

use Illuminate\Contracts\Container\Container;
use Mothership\Attribute\Contracts\AttributeNormalizerInterface;
use Mothership\Attribute\Models\Attribute;
use Mothership\Attribute\Services\Normalizers\DefaultNormalizer;
use Mothership\Attribute\Services\Normalizers\OptionNormalizer;
use Mothership\Attribute\Services\Normalizers\PriceNormalizer;

class AttributeNormalizerFactory
{
    protected array $normalizers = [
        Attribute::PRICE_FIELD_TYPE         => PriceNormalizer::class,
        Attribute::SELECT_FIELD_TYPE        => OptionNormalizer::class,
        Attribute::MULTISELECT_FIELD_TYPE   => OptionNormalizer::class,
        Attribute::CHECKBOX_FIELD_TYPE      => OptionNormalizer::class,
    ];

    /** @var array<string, AttributeNormalizerInterface> */
    protected array $instances = [];

    public function __construct(protected Container $app) {}

    /**
     * It is responsible for managing attribute normalizers.
     */
    public function getNormalizer(string $type): AttributeNormalizerInterface
    {
        if (! isset($this->instances[$type])) {
            $class = $this->normalizers[$type] ?? DefaultNormalizer::class;
            $this->instances[$type] = $this->app->make($class);
        }

        return $this->instances[$type];
    }
}
