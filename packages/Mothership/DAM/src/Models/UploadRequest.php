<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Mothership\User\Models\Admin;

/**
 * P5a (D1): an upload request, the link a guest uses to send files into one intake folder. The URL token is stored
 * the way share tokens are (P2 S5a): its SHA-256 hash for the lookup and an APP_KEY-encrypted copy for the people who
 * may copy the URL again (#126: the creator, the intake folder's owner, a full admin). The password, when set, is a
 * Hash::make() digest that no API returns.
 */
class UploadRequest extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'dam_upload_requests';

    protected $hidden = ['token_hash', 'token_encrypted', 'password_hash'];

    protected $fillable = [
        'title',
        'instructions',
        'directory_id',
        'token_hash',
        'token_encrypted',
        'password_hash',
        'created_by',
        'expires_at',
        'max_files',
        'max_bytes_per_file',
        'allowed_types',
        'status',
        'closed_at',
    ];

    protected $casts = [
        'expires_at'         => 'datetime',
        'closed_at'          => 'datetime',
        'max_files'          => 'integer',
        'max_bytes_per_file' => 'integer',
        'allowed_types'      => 'array',
    ];

    /** The lookup key for a URL token: SHA-256 hex, what `token_hash` stores. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token_hash: string, token_encrypted: string} */
    public static function tokenColumns(string $token): array
    {
        return ['token_hash' => self::hashToken($token), 'token_encrypted' => Crypt::encryptString($token)];
    }

    /** The URL token, decrypted; null when the copy cannot be read (an APP_KEY change). Who may see it: tokenVisibleTo(). */
    public function urlToken(): ?string
    {
        try {
            return is_string($this->token_encrypted) && $this->token_encrypted !== '' ? Crypt::decryptString($this->token_encrypted) : null;
        } catch (DecryptException) {
            return null;
        }
    }

    /** The #126 rule, as for share links: the request's creator, the intake folder's owner, or a full admin. */
    public function tokenVisibleTo(?int $adminId, bool $fullAdmin): bool
    {
        if ($fullAdmin) {
            return true;
        }

        if ($adminId === null) {
            return false;
        }

        return (int) $this->created_by === $adminId
            || ($this->directory !== null && (int) $this->directory->created_by === $adminId);
    }

    public function hasPassword(): bool
    {
        return is_string($this->password_hash) && $this->password_hash !== '';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** Whether the link accepts files now: open, not expired, and its creator still an enabled admin. */
    public function isAccepting(): bool
    {
        if ($this->isClosed() || $this->isExpired()) {
            return false;
        }

        $creator = $this->creator;

        return $creator !== null && (bool) $creator->status;
    }

    /** `open`, `closed` or `expired`, for the staff pages. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isClosed()  => self::STATUS_CLOSED,
            $this->isExpired() => 'expired',
            default            => self::STATUS_OPEN,
        };
    }

    /** @return BelongsTo<Admin, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class, 'directory_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(UploadSubmission::class, 'upload_request_id');
    }
}
