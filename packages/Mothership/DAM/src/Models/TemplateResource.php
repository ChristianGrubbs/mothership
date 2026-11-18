<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image a template names (P8′, D25): its base, a background, the rendered locked layer or a choice option's
 * image. Its bytes are a FileObject blob (ADR 0009), shared with the Library revision they came from, and the row
 * keeps that blob referenced (FileObject::scopeReferenced) for as long as the template exists.
 *
 * @property string $id
 * @property int $template_id
 * @property string $kind
 * @property int $file_object_id
 * @property int $width
 * @property int $height
 * @property int|null $source_asset_id
 * @property int|null $source_asset_file_id
 */
class TemplateResource extends Model
{
    public const KIND_BASE = 'base';

    public const KIND_BACKGROUND = 'background';

    public const KIND_LAYER = 'layer';

    public const KIND_OPTION = 'option';

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'dam_template_resources';

    protected $fillable = ['id', 'template_id', 'kind', 'file_object_id', 'width', 'height', 'source_asset_id', 'source_asset_file_id', 'created_by'];

    protected $casts = [
        'template_id'          => 'integer',
        'file_object_id'       => 'integer',
        'width'                => 'integer',
        'height'               => 'integer',
        'source_asset_id'      => 'integer',
        'source_asset_file_id' => 'integer',
        'created_by'           => 'integer',
    ];

    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }
}
