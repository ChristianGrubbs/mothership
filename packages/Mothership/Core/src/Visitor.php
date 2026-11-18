<?php

namespace Mothership\Core;

use Illuminate\Database\Eloquent\Model;
use Mothership\Core\Jobs\UpdateCreateVisitIndex;
use Shetabit\Visitor\Visitor as BaseVisitor;

class Visitor extends BaseVisitor
{
    /**
     * Create a visit log.
     */
    public function visit(?Model $model = null): void
    {
        foreach ($this->except as $path) {
            if ($this->request->is($path)) {
                return;
            }
        }

        dispatch(new UpdateCreateVisitIndex($model, $this->prepareLog()));
    }

    /**
     * Retrieve request's url
     */
    public function url(): string
    {
        return $this->request->url();
    }

    /**
     * Returns logs
     */
    public function getLog(): array
    {
        return $this->prepareLog();
    }
}
