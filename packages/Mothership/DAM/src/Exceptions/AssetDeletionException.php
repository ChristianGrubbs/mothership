<?php

namespace Mothership\DAM\Exceptions;

use RuntimeException;

class AssetDeletionException extends RuntimeException
{
    public function __construct(protected array $analysis)
    {
        parent::__construct('Asset deletion is blocked by dependent assets.');
    }

    public function analysis(): array
    {
        return $this->analysis;
    }

    public function getAnalysis(): array
    {
        return $this->analysis;
    }
}
