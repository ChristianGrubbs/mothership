<?php

namespace Mothership\HistoryControl\Resolvers;

use Mothership\HistoryControl\Contracts\HistoryAuditable;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

/**
 * Resolver for history_id used for creating versions through trigger
 *
 * For more details regarding resolver check package documentation and config/audit.php
 */
class PrimaryIdResolver implements Resolver
{
    /**
     * adds value to the history_id column
     */
    public static function resolve(Auditable $auditable)
    {
        if (! $auditable instanceof HistoryAuditable) {
            return null;
        }

        return $auditable->getPrimaryModelIdForHistory();
    }
}
