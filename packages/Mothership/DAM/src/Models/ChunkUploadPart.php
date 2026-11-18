<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChunkUploadPart extends Model
{
    protected $table = 'dam_chunk_upload_parts';

    protected $fillable = [
        'chunk_upload_id',
        'chunk_index',
        'byte_offset',
        'byte_length',
        'sha256',
        'staged_path',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'byte_offset' => 'integer',
        'byte_length' => 'integer',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ChunkUpload::class, 'chunk_upload_id');
    }
}
