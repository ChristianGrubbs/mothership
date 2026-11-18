<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\DAM\Contracts\FileObject as FileObjectContract;
use Mothership\DAM\Database\Factories\FileObjectFactory;
use Mothership\DAM\Exceptions\NonContentAddressedLocator;
use Mothership\DAM\Services\ContentStore;

class FileObject extends Model implements FileObjectContract
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'dam_file_objects';

    protected $fillable = [
        'sha256',
        'size',
        'disk',
        'path',
        'mime_type',
        'sniffed_type',
        'technical',
    ];

    protected $casts = [
        'size'      => 'integer',
        'technical' => 'array',
    ];

    /**
     * The locator invariant (ADR 0009): no save may record a path other than the row's own blob. A legacy row
     * that `dam:storage:repair` could not resolve still accepts saves that leave its locator alone.
     */
    protected static function booted(): void
    {
        static::saving(function (self $object): void {
            $recordsLocator = ! $object->exists || $object->isDirty(['path', 'sha256']);

            if ($recordsLocator && ! app(ContentStore::class)->isObjectPath($object->path, $object->sha256)) {
                throw new NonContentAddressedLocator($object->path, $object->sha256);
            }
        });
    }

    public function assetFiles(): HasMany
    {
        return $this->hasMany(AssetFile::class, 'file_object_id');
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(Rendition::class, 'output_file_object_id');
    }

    /** Layered image editor resources (bases, uploads, saved copies) whose bytes are this blob. */
    public function editorResources(): HasMany
    {
        return $this->hasMany(ImageEditorResource::class, 'file_object_id');
    }

    /** Template images (P8′): a template's base, background, rendered layer or choice-option image. */
    public function templateResources(): HasMany
    {
        return $this->hasMany(TemplateResource::class, 'file_object_id');
    }

    /** Fonts and brushes uploaded for the Creative Hub apps (ADR 0015). */
    public function hubResources(): HasMany
    {
        return $this->hasMany(HubResource::class, 'file_object_id');
    }

    /** Screenshots attached to feedback and bug reports. */
    public function feedbackReports(): HasMany
    {
        return $this->hasMany(FeedbackReport::class, 'screenshot_file_object_id');
    }

    /**
     * The one definition of "still wanted" that blob reference counting uses (ADR 0009): an attachment, a
     * rendition output, an editor resource, a template resource or a Hub resource names this row. A new table that references FileObjects joins
     * here, never as another hand-written check at a deletion site.
     *
     * @param  Builder<FileObject>  $query
     */
    public function scopeReferenced(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereHas('assetFiles')->orWhereHas('renditions')->orWhereHas('editorResources')->orWhereHas('templateResources')
                ->orWhereHas('hubResources')->orWhereHas('feedbackReports');
        });
    }

    public function isReferenced(): bool
    {
        return self::query()->whereKey($this->getKey())->referenced()->exists();
    }

    protected static function newFactory(): Factory
    {
        return FileObjectFactory::new();
    }
}
