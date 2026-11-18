<?php

namespace Mothership\Product\Filter;

use Mothership\ElasticSearch\Filter\AbstractFilter as BaseAbstractFilter;

abstract class AbstractFilter extends BaseAbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function setQueryManager($queryBuilder): void
    {
        if (config('elasticsearch.enabled')) {
            parent::setQueryManager($queryBuilder);

            return;
        }

        $this->queryBuilder = $queryBuilder;
    }
}
