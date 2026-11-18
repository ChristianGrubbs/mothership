<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A web page collection (ADR 0008): shared organizer, grants nothing. */
class WebCollection extends Model
{
    protected $table = 'dam_web_collections';

    protected $fillable = ['name', 'parent_id', 'created_by'];

    protected $casts = ['parent_id' => 'integer', 'created_by' => 'integer'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'dam_web_collection_assets', 'collection_id', 'asset_id');
    }

    /** Root-first, excluding self. Depth is capped, so this walks at most the cap. */
    public function ancestors(): Collection
    {
        $chain = collect();
        $seen = [$this->id => true];
        $node = $this->parent;

        while ($node && ! isset($seen[$node->id])) {
            $seen[$node->id] = true;
            $chain->prepend($node);
            $node = $node->parent;
        }

        return $chain;
    }

    /** A root collection has depth 1. */
    public function depth(): int
    {
        return $this->ancestors()->count() + 1;
    }

    /** `Projects / Notes`. */
    public function pathString(): string
    {
        return $this->ancestors()->push($this)->pluck('name')->implode(' / ');
    }
}
