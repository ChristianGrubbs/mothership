<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\HomeTelemetry;

/**
 * Free and total bytes of the library's store. A local disk is measured directly. A store on another machine is read
 * from the capacity file a host process keeps current; without a fresh file the store reads as unreachable.
 */
class HomeStorageReading
{
    private const CAPACITY_FILE_LIMIT_BYTES = 4096;

    private const CHECKED_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @return array{available: bool, free: float|int|null, total: float|int|null, label: string, message: string|null} */
    public function read(): array
    {
        $disk = Directory::getAssetDisk();

        if (config("filesystems.disks.$disk.driver") !== 'local') {
            return $this->remoteReading(now: now()->toImmutable());
        }

        $root = config("filesystems.disks.$disk.root");
        $measurable = is_string($root) && is_dir($root);
        $free = $measurable ? @disk_free_space($root) : false;
        $total = $measurable ? @disk_total_space($root) : false;

        return [
            'available' => $free !== false && $total !== false,
            'free'      => $free === false ? null : $free,
            'total'     => $total === false ? null : $total,
            'label'     => 'Asset storage volume',
            'message'   => null,
        ];
    }

    /** @return array{available: bool, free: int|null, total: int|null, label: string, message: string|null} */
    private function remoteReading(CarbonImmutable $now): array
    {
        $label = (string) config('dam-home.store_capacity.label');
        $capacity = $this->capacity(now: $now);

        return [
            'available' => $capacity !== null,
            'free'      => $capacity['free'] ?? null,
            'total'     => $capacity['total'] ?? null,
            'label'     => $label,
            'message'   => $capacity === null ? trans('admin::app.components.layouts.rail.storage-unreachable', ['store' => $label]) : null,
        ];
    }

    /** @return array{free: int, total: int}|null */
    private function capacity(CarbonImmutable $now): ?array
    {
        $path = (string) config('dam-home.store_capacity.path', storage_path('app/private/umbrel-capacity.json'));

        if (! is_file($path) || filesize($path) > self::CAPACITY_FILE_LIMIT_BYTES) {
            return $this->unreachable(reason: 'missing');
        }

        $data = json_decode((string) @file_get_contents($path), associative: true);
        $free = is_array($data) ? ($data['free_bytes'] ?? null) : null;
        $total = is_array($data) ? ($data['total_bytes'] ?? null) : null;
        $checkedAt = is_array($data) ? $this->checkedAt(value: $data['checked_at'] ?? null) : null;

        if (! is_int($free) || ! is_int($total) || $checkedAt === null || $free < 0 || $total <= 0 || $free > $total) {
            return $this->unreachable(reason: 'malformed');
        }

        $maxAgeMinutes = (int) config('dam-home.store_capacity.max_age_minutes');

        // A reading from the future is as untrustworthy as an old one; a minute allows for clock drift.
        if ($checkedAt->lessThan($now->subMinutes($maxAgeMinutes)) || $checkedAt->greaterThan($now->addMinute())) {
            return $this->unreachable(reason: 'stale');
        }

        return ['free' => $free, 'total' => $total];
    }

    private function checkedAt(mixed $value): ?CarbonImmutable
    {
        // The shape is checked first: createFromFormat throws on a null byte, which valid JSON can carry.
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!'.self::CHECKED_AT_FORMAT, $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format(self::CHECKED_AT_FORMAT) === $value ? CarbonImmutable::instance($parsed) : null;
    }

    private function unreachable(string $reason): null
    {
        HomeTelemetry::record(event: 'store_capacity.unreachable', ctx: ['reason' => $reason]);

        return null;
    }
}
