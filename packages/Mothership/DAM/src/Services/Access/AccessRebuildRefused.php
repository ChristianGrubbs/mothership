<?php

namespace Mothership\DAM\Services\Access;

use RuntimeException;

/**
 * A rebuild would change the members of a generated role group that a folder grant uses, and so change
 * who can open folders under the grants model. The rebuild writes nothing (ADR 0010).
 */
class AccessRebuildRefused extends RuntimeException
{
    public const REMEDY = 'For each group listed, either remove its folder grants on the folder access panel, or give it back exactly the admins who hold its role on the Groups page. Then run the command again.';

    /**
     * @param  list<array{group_id: int, members_to_add: int, members_to_remove: int}>  $groups
     */
    public function __construct(public readonly array $groups)
    {
        parent::__construct(sprintf(
            'Refused: a rebuild would change the members of %d generated role group(s) that a folder grant uses, and so change who can open folders under grants. Nothing was written.',
            count($groups)
        ));
    }
}
