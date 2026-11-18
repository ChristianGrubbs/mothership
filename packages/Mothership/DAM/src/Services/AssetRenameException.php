<?php

namespace Mothership\DAM\Services;

/** A rename AssetRename refused: the new Library path names another asset, found up front or taken concurrently. */
class AssetRenameException extends \RuntimeException
{
    public const NAME_TAKEN = 'name_taken';

    public const NAME_CONFLICT = 'name_conflict';

    public function __construct(public readonly string $reason, public readonly ?string $conflictingName = null)
    {
        parent::__construct($reason);
    }
}
