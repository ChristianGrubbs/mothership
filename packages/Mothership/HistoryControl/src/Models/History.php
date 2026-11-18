<?php

namespace Mothership\HistoryControl\Models;

use Illuminate\Database\Eloquent\Model;
use Mothership\HistoryControl\Contracts\History as HistoryContract;

class History extends Model implements HistoryContract
{
    protected $fillable = [];
}
