<?php

namespace Mothership\ElasticSearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @deprecated Resolve and own a Mothership\ElasticSearch\ElasticSearchQuery instance
 *             for each query-building operation.
 */
class ElasticSearchQuery extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'elastic-search-query';
    }
}
