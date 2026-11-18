<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\DAM\Contracts\AssetComments as AssetCommentsContract;
use Mothership\DAM\Database\Factories\CommentFactory;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\User\Models\Admin;

class AssetComments extends Model implements AssetCommentsContract, HistoryAuditable
{
    use HasFactory;
    use HistoryTrait;

    protected $historyTags = ['asset'];

    protected $auditExclude = [
        'id',
        'parent_id',
        'dam_asset_id',
        'asset_file_id',
        'anchor',
        'mentions',
        'resolved_by',
    ];

    protected $table = 'dam_asset_comments';

    protected $fillable = ['admin_id', 'parent_id', 'comments', 'dam_asset_id', 'asset_file_id', 'anchor', 'mentions', 'resolved_at', 'resolved_by'];

    /** P7: the revision, the place on it, the structured mentions and the resolution (all null on older comments). */
    protected $casts = [
        'asset_file_id' => 'integer',
        'anchor'        => 'array',
        'mentions'      => 'array',
        'resolved_at'   => 'datetime',
        'resolved_by'   => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AssetComments::class, 'parent_id');
    }

    /** The revision the comment is about: a dam_asset_files row of the same asset (RevisionOfAsset). */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class, 'asset_file_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
    }

    protected static function booted(): void
    {
        // Every other driver cascades parent_id; SQL Server cannot cascade a self-reference, so
        // the replies go first there. Each reply's own delete recurses into its replies.
        static::deleting(function (AssetComments $comment): void {
            if ($comment->getConnection()->getDriverName() === 'sqlsrv') {
                $comment->children()->get()->each->delete();
            }
        });
    }

    protected static function newFactory(): Factory
    {
        return CommentFactory::new();
    }

    public function getPrimaryModelIdForHistory(): int
    {
        return $this->dam_asset_id;
    }
}
