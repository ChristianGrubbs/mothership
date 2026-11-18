<?php

namespace Mothership\Attribute\Services\Normalizers;

use Mothership\Attribute\Contracts\Attribute;
use Mothership\Attribute\Contracts\AttributeNormalizerInterface;

class DefaultNormalizer extends AbstractNormalizer implements AttributeNormalizerInterface
{
    /**
     * Normalize the given attribute value.
     */
    public function getData(mixed $data, ?Attribute $attribute = null, array $options = [])
    {
        return $data;
    }
}
