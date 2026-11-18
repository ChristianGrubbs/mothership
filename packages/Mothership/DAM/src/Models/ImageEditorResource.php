<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image a layered editor document names (its base, a background, an upload). Its bytes are a FileObject
 * blob (ADR 0009), shared with the asset's original or another document wherever they are the same bytes.
 * `path` only names a pre-blob `assets/.editor/` working file left for slice 4's reclaim; new rows have none.
 */
class ImageEditorResource extends Model
{
    protected $table = 'dam_image_editor_resources';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['admin_id'=>'integer', 'file_object_id'=>'integer', 'details'=>'array', 'width'=>'integer', 'height'=>'integer'];

    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }
}
