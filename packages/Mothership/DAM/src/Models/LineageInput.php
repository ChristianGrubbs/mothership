<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One input of a derived output made from several (P3a S6), such as the base and the background of an image
 * editor save-as-new. Names the revision when the input was one, and always the bytes' checksum.
 */
class LineageInput extends Model
{
    public const ROLE_BASE = 'base';

    public const ROLE_BACKGROUND = 'background';

    /** A batch recipe's source revision (P6a). */
    public const ROLE_SOURCE = 'source';

    /** A watermark recipe's overlay image (P6a), a revision of another entry. */
    public const ROLE_OVERLAY = 'overlay';

    /** P8′: the template version an asset was made from (template_version_id; no file of its own). */
    public const ROLE_TEMPLATE = 'template';

    /** P8′: a revision a filler chose for an image slot of a template. */
    public const ROLE_SLOT_IMAGE = 'slot_image';

    /** P8′: the template author's image behind a choice-slot option the filler picked. */
    public const ROLE_CHOICE_IMAGE = 'choice_image';

    public const UPDATED_AT = null;

    protected $table = 'dam_lineage_inputs';

    protected $fillable = ['rendition_id', 'position', 'role', 'asset_file_id', 'sha256', 'template_version_id'];

    protected $casts = ['position' => 'integer', 'asset_file_id' => 'integer', 'template_version_id' => 'integer'];

    public function rendition(): BelongsTo
    {
        return $this->belongsTo(Rendition::class, 'rendition_id');
    }

    public function assetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'asset_file_id');
    }
}
