<?php

namespace Mothership\Product\Filter\ElasticSearch;

use Mothership\Product\Filter\AbstractAttributeFilter;

abstract class AbstractElasticSearchAttributeFilter extends AbstractAttributeFilter
{
    protected function getScopedAttributePath($attribute, ?string $locale = null, ?string $channel = null)
    {
        return sprintf('values.%s.%s', $attribute->getScope($locale, $channel), $attribute->code.'-'.$attribute->type);
    }
}
