<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\User\Models\Admin;

/**
 * A tokenized embed of one video or audio asset (P6 D26). The URL token is the whole credential and is never stored:
 * `token_hash` is its SHA-256, so the row finds the embed and nothing in the database rebuilds the URL.
 */
class Embed extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'dam_embeds';

    protected $fillable = [
        'asset_id',
        'token_hash',
        'created_by',
        'expires_at',
        'revoked_at',
        'allowed_origins',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at'      => 'datetime',
        'revoked_at'      => 'datetime',
        'allowed_origins' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return self::STATUS_REVOKED;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }
}
