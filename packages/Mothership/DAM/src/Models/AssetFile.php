<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mothership\DAM\Contracts\AssetFile as AssetFileContract;
use Mothership\DAM\Database\Factories\AssetFileFactory;
use Symfony\Component\Mime\MimeTypes;

class AssetFile extends Model implements AssetFileContract
{
    use HasFactory;

    const UPDATED_AT = null;

    const ROLE_ORIGINAL = 'original';

    const ROLE_PRIMARY = 'primary';

    /** The same asset in another file format (a PSD beside its PNG, an SVG beside its JPEG, a source ZIP). */
    const ROLE_ALTERNATE = 'alternate';

    const ROLE_PROXY = 'proxy';

    const ROLE_THUMBNAIL = 'thumbnail';

    const ROLE_TEXTURE = 'texture';

    const ROLE_PLATE = 'plate';

    const ROLE_SIDECAR = 'sidecar';

    const ROLE_SUBTITLE = 'subtitle';

    const ROLE_TRANSCRIPT = 'transcript';

    const ROLE_SOURCE = 'source';

    const ROLE_OUTPUT = 'output';

    const ROLE_MASK = 'mask';

    const ROLE_PROMPT = 'prompt';

    const ROLE_PROJECT = 'project';

    const ROLE_CALIBRATION = 'calibration';

    const ROLE_VERSION = 'version';

    const ROLE_OTHER = 'other';

    const ROLES = [
        self::ROLE_ORIGINAL,
        self::ROLE_PRIMARY,
        self::ROLE_ALTERNATE,
        self::ROLE_PROXY,
        self::ROLE_THUMBNAIL,
        self::ROLE_TEXTURE,
        self::ROLE_PLATE,
        self::ROLE_SIDECAR,
        self::ROLE_SUBTITLE,
        self::ROLE_TRANSCRIPT,
        self::ROLE_SOURCE,
        self::ROLE_OUTPUT,
        self::ROLE_MASK,
        self::ROLE_PROMPT,
        self::ROLE_PROJECT,
        self::ROLE_CALIBRATION,
        self::ROLE_VERSION,
        self::ROLE_OTHER,
    ];

    /**
     * Roles a person may attach a file under. `original` belongs to the representative slot and
     * `version` means a superseded revision, so neither is attached by hand.
     */
    const ATTACHABLE_ROLES = [
        self::ROLE_PRIMARY,
        self::ROLE_ALTERNATE,
        self::ROLE_PROXY,
        self::ROLE_THUMBNAIL,
        self::ROLE_TEXTURE,
        self::ROLE_PLATE,
        self::ROLE_SIDECAR,
        self::ROLE_SUBTITLE,
        self::ROLE_TRANSCRIPT,
        self::ROLE_SOURCE,
        self::ROLE_OUTPUT,
        self::ROLE_MASK,
        self::ROLE_PROMPT,
        self::ROLE_PROJECT,
        self::ROLE_CALIBRATION,
        self::ROLE_OTHER,
    ];

    protected $table = 'dam_asset_files';

    protected $fillable = [
        'dam_asset_id',
        'file_object_id',
        'role',
        'label',
        'sort',
        'attachment_id',
        'revision_number',
        'is_current',
        'created_by',
        'note',
    ];

    protected $casts = [
        'sort'            => 'integer',
        'attachment_id'   => 'integer',
        'revision_number' => 'integer',
        'is_current'      => 'boolean',
    ];

    protected static function booted(): void
    {
        // Every row is a revision of a slot (P3a S2). Writers that name no slot (factories, attach,
        // generated outputs) get the slot a plain attach implies; AttachmentLedger names it explicitly.
        static::creating(function (self $file): void {
            if ($file->attachment_id === null) {
                self::assignImpliedSlot($file);
            }

            if ($file->revision_number === null) {
                $file->revision_number = (int) self::query()->where('attachment_id', $file->attachment_id)->max('revision_number') + 1;
            }
        });

        static::saving(function (self $file): void {
            if ($file->attachment_id === null || ! $file->isDirty(['attachment_id', 'dam_asset_id'])) {
                return;
            }

            if (! Attachment::query()->whereKey($file->attachment_id)->where('dam_asset_id', $file->dam_asset_id)->exists()) {
                throw new InvalidArgumentException('An asset file revision must belong to a slot of the same asset.');
            }
        });

        static::deleted(function (self $file): void {
            if ($file->attachment_id !== null && ! self::query()->where('attachment_id', $file->attachment_id)->exists()) {
                Attachment::query()->whereKey($file->attachment_id)->delete();
            }

            // Lineage keeps what was made and from which bytes; it stops pointing at a file that is gone.
            Rendition::query()->where('output_asset_file_id', $file->getKey())->update(['output_asset_file_id' => null]);
            LineageInput::query()->where('asset_file_id', $file->getKey())->update(['asset_file_id' => null]);

            // P7: review comments and feedback requests stay, without the revision they named.
            AssetComments::query()->where('asset_file_id', $file->getKey())->update(['asset_file_id' => null]);
            ReviewRequest::query()->where('asset_file_id', $file->getKey())->update(['asset_file_id' => null]);
        });

        // On SQL Server an optimization's asset_file_id and a vectorization's or optimization's
        // source_asset_file_id are NO ACTION rather than SET NULL (a second path from the asset,
        // error 1785), so they are cleared before the file goes.
        static::deleting(function (self $file): void {
            if ($file->getConnection()->getDriverName() === 'sqlsrv') {
                DB::table('dam_optimizations')
                    ->where('asset_file_id', $file->getKey())
                    ->update(['asset_file_id' => null]);

                foreach (['dam_vectorizations', 'dam_optimizations'] as $table) {
                    DB::table($table)
                        ->where('source_asset_file_id', $file->getKey())
                        ->update(['source_asset_file_id' => null]);
                }
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    /**
     * `original` joins the representative slot as its current revision, `version` joins it as a
     * superseded one, and any other role opens a slot of its own at the end of the entry.
     */
    private static function assignImpliedSlot(self $file): void
    {
        $history = in_array($file->role, [self::ROLE_ORIGINAL, self::ROLE_VERSION], true);

        if ($history) {
            $slot = Attachment::query()
                ->where('dam_asset_id', $file->dam_asset_id)
                ->where('is_representative', true)
                ->first();

            $slot ??= Attachment::create([
                'dam_asset_id'      => $file->dam_asset_id,
                'role'              => self::ROLE_ORIGINAL,
                'label'             => $file->label,
                'position'          => 0,
                'is_representative' => true,
                'created_by'        => $file->created_by,
            ]);
        } else {
            $slot = Attachment::create([
                'dam_asset_id' => $file->dam_asset_id,
                'role'         => $file->role,
                'label'        => $file->label,
                'position'     => (int) Attachment::query()->where('dam_asset_id', $file->dam_asset_id)->max('position') + 1,
                'created_by'   => $file->created_by,
            ]);
        }

        $file->attachment_id = $slot->getKey();
        $file->is_current = $file->role !== self::ROLE_VERSION;
    }

    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }

    /**
     * The type of THIS attachment — never read from the blob's own name, since a blob can be
     * shared by attachments with different extensions and originals are stored suffix-less
     * (ADR 0009). Order: an explicit suffix on the label, the owning asset's extension column
     * for `original`/`version` roles, the FileObject's MIME type, or '' when nothing resolves.
     */
    public function extension(): string
    {
        $fromLabel = $this->labelExtension();

        if ($fromLabel !== null) {
            return $fromLabel;
        }

        if (in_array($this->role, [self::ROLE_ORIGINAL, self::ROLE_VERSION], true)) {
            $assetExtension = strtolower((string) $this->asset?->extension);

            if ($assetExtension !== '') {
                return $assetExtension;
            }
        }

        $mime = $this->fileObject?->mime_type;

        if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream') {
            $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? null;

            if ($extension !== null) {
                return $extension;
            }
        }

        return '';
    }

    private function labelExtension(): ?string
    {
        return self::extensionFromLabel($this->label);
    }

    /**
     * An explicit suffix on a label (e.g. "flight.SRT"), lowercased and never a bare digit run ("Rev 2.0"). Up to 16
     * characters, as the Creative Hub registry spells a type (`.vectorcraft`, `.designcraft`).
     */
    public static function extensionFromLabel(?string $label): ?string
    {
        if (! is_string($label) || $label === '') {
            return null;
        }

        $extension = strtolower(pathinfo($label, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1 || ctype_digit($extension)) {
            return null;
        }

        return $extension;
    }

    protected static function newFactory(): Factory
    {
        return AssetFileFactory::new();
    }
}
