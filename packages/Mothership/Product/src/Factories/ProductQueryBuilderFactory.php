<?php

namespace Mothership\Product\Factories;

use Mothership\Product\Builders\DatabaseProductQueryBuilder;
use Mothership\Product\Builders\ElasticProductQueryBuilder;

class ProductQueryBuilderFactory
{
    public static function make()
    {
        return config('elasticsearch.enabled')
            ? resolve(ElasticProductQueryBuilder::class)
            : resolve(DatabaseProductQueryBuilder::class);
    }
}
