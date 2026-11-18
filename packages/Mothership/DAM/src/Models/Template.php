<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P8′ (D25): a template staff fill to make a new asset. Its versions hold the locked layout and the slots; the
 * published one is what fillers see. A draft has never been published; an archived template is no longer offered.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property int|null $owner_id
 * @property int|null $output_directory_id
 * @property int|null $published_version_id
 */
class Template extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    protected $table = 'dam_templates';

    protected $fillable = ['name', 'description', 'status', 'owner_id', 'output_directory_id', 'published_version_id'];

    protected $casts = [
        'owner_id'             => 'integer',
        'output_directory_id'  => 'integer',
        'published_version_id' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class, 'template_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'published_version_id');
    }

    public function latestVersion(): ?TemplateVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }

    public function resources(): HasMany
    {
        return $this->hasMany(TemplateResource::class, 'template_id');
    }

    public function outputDirectory(): BelongsTo
    {
        return $this->belongsTo(Directory::class, 'output_directory_id');
    }
}
