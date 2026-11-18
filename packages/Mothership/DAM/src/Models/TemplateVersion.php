<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of a template (P8′, D25): its TemplateSchema document (the locked editor document and the slots)
 * and the rendered locked layer. Once published it never changes: saving() refuses any edit to a published
 * version's document or layer, so every asset made from it keeps pointing at exactly what it was made from.
 *
 * @property int $id
 * @property int $template_id
 * @property int $version
 * @property array $document
 * @property string|null $layer_resource_id
 * @property string $revision
 * @property Carbon|null $published_at
 */
class TemplateVersion extends Model
{
    protected $table = 'dam_template_versions';

    protected $fillable = ['template_id', 'version', 'document', 'layer_resource_id', 'revision', 'created_by', 'published_at', 'published_by'];

    protected $casts = [
        'template_id'  => 'integer',
        'version'      => 'integer',
        'document'     => 'array',
        'created_by'   => 'integer',
        'published_by' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $version): void {
            if ($version->exists && $version->getOriginal('published_at') !== null && $version->isDirty(['document', 'layer_resource_id', 'version', 'template_id', 'published_at'])) {
                throw new LogicException('A published template version is immutable.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->published_at !== null) {
                throw new LogicException('A published template version is never deleted on its own.');
            }
        });
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(TemplateResource::class, 'layer_resource_id');
    }

    /** @return list<array<string, mixed>> */
    public function slots(): array
    {
        return $this->document['slots'] ?? [];
    }
}
