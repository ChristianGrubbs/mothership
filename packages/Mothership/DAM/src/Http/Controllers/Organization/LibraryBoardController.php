<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Organization;

use Illuminate\Http\Request;
use Mothership\DAM\Models\Label;

/** P4 (D13): the viewer's own boards: the label endpoints, scoped to boards they own. */
class LibraryBoardController extends LibraryLabelController
{
    protected function kind(Request $request): string
    {
        return Label::KIND_BOARD;
    }
}
