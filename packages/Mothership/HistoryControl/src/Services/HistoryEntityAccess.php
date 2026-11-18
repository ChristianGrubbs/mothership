<?php

namespace Mothership\HistoryControl\Services;

use OwenIt\Auditing\Models\Audit;

/**
 * Who may read one entity's history. The history routes check only the history ACL, so a package whose records
 * are not visible to every admin (a DAM asset, a share link) registers its own rule here, and the history
 * controller answers a refused id exactly like history that does not exist. Entities without a rule keep the ACL
 * alone.
 */
class HistoryEntityAccess
{
    /** @var array<string, callable(int): bool> */
    protected array $rules = [];

    /** @param callable(int): bool $canView */
    public function register(string $entityName, callable $canView): void
    {
        $this->rules[$entityName] = $canView;
    }

    public function allows(string $entityName, int $id): bool
    {
        // The history query matches tags with the database collation, which on MySQL can treat a differently
        // spelled name as `asset`. Rules therefore apply to every stored tag that name matches, as well as to the
        // name as requested: no spelling reaches rows its rule refuses. The match ignores the id, so a hidden id and
        // a missing one meet the same rule and answer alike.
        $tags = Audit::query()->where('tags', $entityName)->distinct()->pluck('tags')
            ->push($entityName)->unique();

        foreach ($tags as $tag) {
            $rule = $this->rules[(string) $tag] ?? null;

            if ($rule !== null && ! $rule($id)) {
                return false;
            }
        }

        return true;
    }
}
