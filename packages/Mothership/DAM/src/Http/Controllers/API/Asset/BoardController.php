<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\Request;
use Mothership\DAM\Models\Label;

/** P4 (D13, REST): the API key's admin's own boards; anyone else's board answers 404 like a missing one. */
class BoardController extends LabelController
{
    protected function kind(Request $request): string
    {
        return Label::KIND_BOARD;
    }
}
