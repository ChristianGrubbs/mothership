<?php

namespace Mothership\User\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider account (provider + stable subject id) that may sign in as one admin.
 *
 * @property int $id
 * @property int $admin_id
 * @property string $provider
 * @property string $subject
 * @property string|null $email_at_link
 * @property Carbon|null $linked_at
 * @property Carbon|null $last_used_at
 */
#[Fillable([
    'admin_id',
    'provider',
    'subject',
    'email_at_link',
    'linked_at',
    'last_used_at',
])]
#[Table(name: 'admin_identities')]
class AdminIdentity extends Model
{
    protected function casts(): array
    {
        return [
            'linked_at'    => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
