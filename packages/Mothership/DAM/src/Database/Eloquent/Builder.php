<?php

namespace Mothership\DAM\Database\Eloquent;

use Closure;
use Kalnoy\Nestedset\QueryBuilder as BaseBuilder;
use Mothership\DAM\Services\DirectoryTreeLock;

class Builder extends BaseBuilder
{
    public function ancestorsAndSelfAndDefaultOrder(int $id, array $columns = ['*'])
    {
        return $this->whereAncestorOf($id, true)->defaultOrder()->get($columns);
    }

    /**
     * Take the directory tree lock before Eloquent can open a transaction of its own (ADR 0004, and ADR
     * 0010's fence since sharing slice 2a).
     *
     * `createOrFirst()` wraps its insert in a transaction only when one is already open
     * (Builder::withSavepointIfNeeded checks transactionLevel()), and Laravel's DatabaseTransactions test
     * wrapper means one always is. So `Directory::firstOrCreate()` used to put the tree lock — and the
     * fence's hold, which `run()` takes as that transaction's first statement — one level deeper in a test
     * than in production, through no change in the code under test. ADR 0010's fence reads "the outermost
     * transaction the application opened", and that has to mean the same thing in both.
     *
     * Taking the lock here makes it so: `run()` opens that transaction, and the savepoint below is always
     * inside it. `run()` is re-entrant per connection, so the `save()` inside `create()` joins this hold
     * rather than taking anything again, and a caller that already holds the lock pays nothing.
     */
    public function createOrFirst(array $attributes = [], Closure|array $values = [])
    {
        return app(DirectoryTreeLock::class)->run(fn () => parent::createOrFirst($attributes, $values));
    }
}
