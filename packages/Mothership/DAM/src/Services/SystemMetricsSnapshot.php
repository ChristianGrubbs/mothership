<?php

namespace Mothership\DAM\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class SystemMetricsSnapshot
{
    private const MAX_BYTES = 65536;

    private const REQUIRED_SCHEMA_VERSION = 1;

    private const STALE_AFTER_SECONDS = 15;

    /**
     * @return array{available:bool,collected_at:?string,stale:bool,cpu:array,memory:array,gpu:array}
     */
    public function read(string $path, CarbonImmutable $now): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('System metrics snapshot is missing.');
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('System metrics snapshot could not be read.');
        }

        $document = json_decode($contents, true);

        if (! is_array($document) || ($document['schema_version'] ?? null) !== self::REQUIRED_SCHEMA_VERSION) {
            throw new RuntimeException('System metrics snapshot has an unsupported schema.');
        }

        $collectedAt = $this->parseTimestamp($document['collected_at'] ?? null);

        if (! $collectedAt instanceof CarbonImmutable) {
            throw new RuntimeException('System metrics snapshot has an invalid timestamp.');
        }

        $signals = is_array($document['signals'] ?? null) ? $document['signals'] : [];
        $ageSeconds = $now->getTimestamp() - $collectedAt->getTimestamp();

        return [
            'available'    => true,
            'collected_at' => $collectedAt->toIso8601String(),
            'stale'        => $ageSeconds > self::STALE_AFTER_SECONDS,
            'cpu'          => $this->percentSignal($signals['cpu'] ?? null),
            'memory'       => $this->memorySignal($signals['memory'] ?? null),
            'gpu'          => $this->percentSignal($signals['gpu'] ?? null),
        ];
    }

    /**
     * The stable shape returned when a snapshot is missing, unreadable, or structurally invalid.
     *
     * @return array{available:bool,collected_at:?string,stale:bool,cpu:array,memory:array,gpu:array}
     */
    public function unavailable(): array
    {
        return [
            'available'    => false,
            'collected_at' => null,
            'stale'        => true,
            'cpu'          => $this->percentSignal(null),
            'memory'       => $this->memorySignal(null),
            'gpu'          => $this->percentSignal(null),
        ];
    }

    /**
     * @return array{available:bool,percent:?float}
     */
    private function percentSignal(mixed $signal): array
    {
        if (! is_array($signal) || ($signal['available'] ?? null) !== true || ! is_numeric($signal['percent'] ?? null)) {
            return ['available' => false, 'percent' => null];
        }

        return ['available' => true, 'percent' => max(0.0, min(100.0, (float) $signal['percent']))];
    }

    /**
     * Impossible memory (used greater than total, negative bytes, non-positive total) is rejected
     * as one unavailable signal rather than clamped, since there is no safe display value to show.
     *
     * @return array{available:bool,used_bytes:?int,total_bytes:?int,percent:?float}
     */
    private function memorySignal(mixed $signal): array
    {
        $unavailable = ['available' => false, 'used_bytes' => null, 'total_bytes' => null, 'percent' => null];

        if (! is_array($signal) || ($signal['available'] ?? null) !== true) {
            return $unavailable;
        }

        $used = $signal['used_bytes'] ?? null;
        $total = $signal['total_bytes'] ?? null;

        if (! is_numeric($used) || ! is_numeric($total)) {
            return $unavailable;
        }

        $usedBytes = (int) $used;
        $totalBytes = (int) $total;

        if ($usedBytes < 0 || $totalBytes <= 0 || $usedBytes > $totalBytes) {
            return $unavailable;
        }

        return [
            'available'   => true,
            'used_bytes'  => $usedBytes,
            'total_bytes' => $totalBytes,
            'percent'     => round(($usedBytes / $totalBytes) * 100, 1),
        ];
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
