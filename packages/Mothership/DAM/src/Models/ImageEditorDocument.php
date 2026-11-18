<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;

class ImageEditorDocument extends Model
{
    protected $table = 'dam_image_editor_documents';

    protected $guarded = [];

    protected $casts = ['document'=>'array'];
}
