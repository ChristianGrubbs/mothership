<?php

namespace Mothership\DAM\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Mothership\DAM\Contracts\Share as ShareContract;
use Mothership\DAM\Database\Factories\ShareFactory;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\User\Models\Admin;

class Share extends Model implements HistoryContract, ShareContract
{
    use HasFactory, HistoryTrait;

    public const TYPE_ASSET = 'asset';

    public const TYPE_DIRECTORY = 'directory';

    /** P4 D2: a public collection's link; `target_id` is dam_collections.id. */
    public const TYPE_COLLECTION = 'collection';

    protected $table = 'dam_shares';

    protected $historyTags = ['Share'];

    // The URL token is a bearer capability: no form of it goes into the audit trail (P2 S5a), and neither does the
    // password's hash (P2 S5b).
    protected $auditExclude = [
        'token',
        'token_hash',
        'token_encrypted',
        'password_hash',
        // P4 D23: anyone with the slug reaches the link, so it stays out of the audit trail as the token does.
        'slug',
        'view_count',
        'download_count',
        'last_accessed_at',
        'updated_at',
        'created_at',
    ];

    protected $hidden = [
        'token',
        'token_hash',
        'token_encrypted',
        'password_hash',
        'slug',
    ];

    protected $fillable = [
        'token',
        'token_hash',
        'token_encrypted',
        'password_hash',
        'allow_download',
        'requires_creator_access',
        'name',
        'share_type',
        'target_id',
        'created_by',
        'expires_at',
        'revoked_at',
        'view_count',
        'download_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'expires_at'              => 'datetime',
        'revoked_at'              => 'datetime',
        'last_accessed_at'        => 'datetime',
        'view_count'              => 'integer',
        'download_count'          => 'integer',
        'allow_download'          => 'boolean',
        'requires_creator_access' => 'boolean',
    ];

    /** The lookup key for a URL token: SHA-256 hex, what `token_hash` stores. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The stored form of a URL token (P2 S5a): its hash and an APP_KEY-encrypted copy, never the plaintext, so a
     * database dump alone yields no working link.
     *
     * @return array{token: null, token_hash: string, token_encrypted: string}
     */
    public static function tokenColumns(string $token): array
    {
        return [
            'token'           => null,
            'token_hash'      => self::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
        ];
    }

    /**
     * The usable URL token of a share row (a model or a raw `dam_shares` row): the decrypted copy, else a legacy
     * row's plaintext. Null when neither is readable, for instance after an APP_KEY change.
     */
    public static function urlTokenOf(object $row): ?string
    {
        $encrypted = $row->token_encrypted ?? null;

        if (is_string($encrypted) && $encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                return null;
            }
        }

        $legacy = $row->token ?? null;

        return is_string($legacy) && $legacy !== '' ? $legacy : null;
    }

    /** The one way to read the token that builds or shows this link's URL. Who may see it is tokenVisibleTo(). */
    public function urlToken(): ?string
    {
        return self::urlTokenOf($this);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'target_id');
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class, 'target_id');
    }

    /** P4 D2: a collection link's collection. */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(AssetCollection::class, 'target_id');
    }

    public function target(): ?Model
    {
        return match ($this->share_type) {
            self::TYPE_ASSET      => $this->asset,
            self::TYPE_COLLECTION => $this->collection,
            default               => $this->directory,
        };
    }

    /** A link that opens a set of files, each by its own id (a folder or a collection), rather than one asset. */
    public function isContainer(): bool
    {
        return in_array($this->share_type, [self::TYPE_DIRECTORY, self::TYPE_COLLECTION], true);
    }

    /**
     * Who may see and use the capability URL: the link's creator, the target's owner, or a full admin (D7).
     * The one predicate behind the share list, the revoke/delete gate and the Web Pages listing.
     */
    public function tokenVisibleTo(?int $adminId, bool $fullAdmin): bool
    {
        if ($fullAdmin) {
            return true;
        }

        if ($adminId === null) {
            return false;
        }

        if ((int) $this->created_by === $adminId) {
            return true;
        }

        $target = $this->target();

        // P4 D2: a collection's owner is `owner_id`; a file's or folder's is `created_by`.
        $ownerId = $target instanceof AssetCollection ? $target->owner_id : $target?->created_by;

        return $ownerId !== null && (int) $ownerId === $adminId;
    }

    /** Whether the link asks for a password (P2 S5b). Its hash is never returned by any API: only this bool is. */
    public function hasPassword(): bool
    {
        return is_string($this->password_hash) && $this->password_hash !== '';
    }

    /** Whether the link may hand out the original bytes (P2 S5b). Off, it is preview-only. */
    public function allowsDownload(): bool
    {
        // A row read before the S5b migration ran has no column at all: it keeps today's behaviour.
        return $this->allow_download ?? true;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function statusLabel(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'active';
    }

    protected static function newFactory(): Factory
    {
        return ShareFactory::new();
    }
}
