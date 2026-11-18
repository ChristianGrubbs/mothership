<?php

namespace Mothership\ElasticSearch\Contracts;

use Mothership\ElasticSearch\Contracts\ResultCursor as ResultCursorContract;

interface CursorFactory
{
    /**
     * Create the cursor with the correct implementation
     */
    public static function createCursor(mixed $queryBuilder, array $options = []): ResultCursorContract;
}
