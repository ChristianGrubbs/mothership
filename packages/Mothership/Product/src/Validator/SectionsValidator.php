<?php

namespace Mothership\Product\Validator;

use Mothership\Product\Type\AbstractType;
use Mothership\Product\Validator\Abstract\ValuesValidator;
use Mothership\Product\Validator\Rule\KeyExistsRule;

class SectionsValidator extends ValuesValidator
{
    const ALLOWED_SECTIONS = [
        AbstractType::CHANNEL_LOCALE_VALUES_KEY,
        AbstractType::LOCALE_VALUES_KEY,
        AbstractType::CHANNEL_VALUES_KEY,
        AbstractType::COMMON_VALUES_KEY,
        AbstractType::CATEGORY_VALUES_KEY,
        AbstractType::ASSOCIATION_VALUES_KEY,
    ];

    /**
     * Validation rules to be used on the data
     */
    protected function generateRules(mixed $data, ?string $productId, array $options): array
    {
        return [
            '*' => new KeyExistsRule(self::ALLOWED_SECTIONS),
        ];
    }
}
