<?php

namespace Mothership\Product\Validator;

use Mothership\Attribute\Services\AttributeService;
use Mothership\Product\Type\AbstractType;
use Mothership\Product\Validator\Abstract\ValuesValidator;
use Mothership\Product\Validator\Rule\AttributeValueRule;

class CommonValuesValidator extends ValuesValidator
{
    /**
     * create localeValuesValidator
     */
    public function __construct(
        protected AttributeService $attributeService
    ) {}

    /**
     * Validation rules to be used on the data
     */
    protected function generateRules(mixed $data, ?string $productId, array $options): array
    {
        return [
            AbstractType::COMMON_VALUES_KEY.'.*' => new AttributeValueRule(attributeService: $this->attributeService, isChannelBased: false, isLocaleBased: false, productId: $productId),
        ];
    }
}
