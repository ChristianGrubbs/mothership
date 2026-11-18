<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * P5b (D18): one embedded-metadata mapping. It reads `source_key` from the asset's raw ExifTool data
 * (meta_data.exif), runs `transforms` in order, and writes each resulting value to a tag, a typed field
 * (`target_id`) or a shared label (by name), with source `mapping:<id>`.
 *
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property string $source_key
 * @property list<array<string, mixed>>|null $transforms
 * @property string $target_type
 * @property int|null $target_id
 * @property string $write_mode
 * @property int|null $scope_directory_id
 * @property int $version
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $last_run_at
 * @property array<string, int>|null $last_run_counts
 */
class MetadataMapping extends Model
{
    public const TARGET_TAG = 'tag';

    public const TARGET_FIELD = 'field';

    public const TARGET_LABEL = 'label';

    public const TARGETS = [self::TARGET_TAG, self::TARGET_FIELD, self::TARGET_LABEL];

    protected $table = 'dam_metadata_mappings';

    protected $fillable = ['name', 'enabled', 'source_key', 'transforms', 'target_type', 'target_id', 'write_mode',
        'scope_directory_id', 'version', 'created_by', 'updated_by', 'last_run_at', 'last_run_counts'];

    protected $casts = [
        'enabled'            => 'boolean',
        'transforms'         => 'array',
        'target_id'          => 'integer',
        'scope_directory_id' => 'integer',
        'version'            => 'integer',
        'created_by'         => 'integer',
        'updated_by'         => 'integer',
        'last_run_at'        => 'datetime',
        'last_run_counts'    => 'array',
    ];
}
