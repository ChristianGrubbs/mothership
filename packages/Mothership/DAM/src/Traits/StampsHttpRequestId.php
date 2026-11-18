<?php

declare(strict_types=1);

namespace Mothership\DAM\Traits;

use Illuminate\Database\Eloquent\Model;
use Mothership\DAM\Support\RequestId;

/**
 * A status row records the id of the request that started its work (P1a): from the request itself, or, in a
 * queued job, from the Context Laravel restored out of the job's payload. Rows nobody's request started keep
 * null.
 */
trait StampsHttpRequestId
{
    public static function bootStampsHttpRequestId(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('http_request_id') === null) {
                $model->setAttribute('http_request_id', RequestId::current());
            }
        });
    }
}
