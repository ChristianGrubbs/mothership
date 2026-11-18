<?php

namespace Mothership\User\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider sign-in with no linked admin, waiting for an operator to grant or link it.
 *
 * @property int $id
 * @property string $provider
 * @property string $subject
 * @property string $email
 * @property bool $email_verified
 * @property string|null $name
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $status
 * @property Carbon $expires_at
 * @property int|null $admin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'provider',
    'subject',
    'email',
    'email_verified',
    'name',
    'ip',
    'user_agent',
    'status',
    'expires_at',
    'admin_id',
])]
#[Table(name: 'admin_access_requests')]
class AdminAccessRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GRANTED = 'granted';

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'expires_at'     => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
