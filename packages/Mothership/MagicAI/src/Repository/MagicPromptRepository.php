<?php

namespace Mothership\MagicAI\Repository;

use Mothership\Core\Eloquent\Repository;
use Mothership\MagicAI\Contracts\MagicPrompt;

class MagicPromptRepository extends Repository
{
    /**
     * Specify the Model class name
     */
    public function model(): string
    {
        return MagicPrompt::class;
    }
}
