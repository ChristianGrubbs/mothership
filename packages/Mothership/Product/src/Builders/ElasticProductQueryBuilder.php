<?php

namespace Mothership\Product\Builders;

use Mothership\Attribute\Services\AttributeService;
use Mothership\ElasticSearch\AbstractFilterableQueryBuilder as ElasticSearchAbstractFilterableQueryBuilder;
use Mothership\ElasticSearch\ElasticSearchQuery;
use Mothership\Product\Filter\FilterManager;
use Mothership\Product\Traits\ProductQueryFilter;

class ElasticProductQueryBuilder extends ElasticSearchAbstractFilterableQueryBuilder
{
    use ProductQueryFilter;

    public function __construct(
        protected AttributeService $attributeService,
        protected FilterManager $filterManager,
        ?ElasticSearchQuery $elasticSearchQuery = null,
    ) {
        parent::__construct($elasticSearchQuery);
    }

    /**
     * Add a filter condition on an attribute
     */
    protected function addAttributeFilter(
        $filter,
        $attribute,
        $operator,
        $value,
        array $context
    ): static {
        $locale = $attribute->value_per_locale ? ($context['locale'] ?? null) : null;
        $channel = $attribute->value_per_channel ? ($context['channel'] ?? null) : null;

        $filter->setQueryManager($this->elasticSearchQuery);

        if (! $filter->isOperatorAllowed($operator)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported operator. Only "%s" are supported, but "%s" was given.',
                    implode(',', array_map(fn ($allowOperator) => $allowOperator->value, $filter->getAllowedOperators())),
                    $operator->value,
                )
            );
        }

        $filter->addAttributeFilter($attribute, $operator, $value, $locale, $channel, $context);

        return $this;
    }

    /**
     * Add a filter condition on an universal attribute
     */
    protected function applyUnfilteredFilter(
        $filter,
        $attribute,
        $operator,
        $value,
        array $context
    ): static {
        $filter->setQueryManager($this->elasticSearchQuery);

        $filter->applyUnfilteredFilter($attribute, $operator, $value, $context);

        return $this;
    }
}
