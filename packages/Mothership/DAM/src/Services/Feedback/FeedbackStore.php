<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Feedback;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FeedbackNote;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\User\Models\Admin;
use RuntimeException;

/**
 * Writes reports and their notes. A screenshot becomes a content-addressed FileObject (ADR 0009) that the report row
 * holds; the bytes are written first and retained inside the transaction that creates the row, as HubResourceStore
 * does, and released again when the row is never made.
 */
final class FeedbackStore
{
    public const SCREENSHOT_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** The context keys a report keeps; anything else the browser sends is dropped. */
    private const CONTEXT_KEYS = ['browser', 'os', 'viewport', 'dpr', 'client_time', 'timezone', 'app_version', 'language'];

    private const CONSOLE_KINDS = ['error', 'unhandledrejection', 'console'];

    private const CONSOLE_MAX = 20;

    public function __construct(
        private ContentStore $contentStore,
        private AssetDeletionService $deletion,
        private FeedbackRedactor $redactor,
        private FeedbackNotifier $notifier,
    ) {}

    /**
     * @param  array{type:string, severity?:?string, title:string, description:string, page_url?:?string, route_name?:?string, hub_app?:?string, context?:array<string, mixed>}  $input
     */
    public function create(Admin $reporter, array $input, ?UploadedFile $screenshot = null): FeedbackReport
    {
        $attributes = [
            'admin_id'    => (int) $reporter->getKey(),
            'type'        => $input['type'],
            'severity'    => $input['type'] === 'bug' ? ($input['severity'] ?? null) : null,
            'title'       => trim($input['title']),
            'description' => trim($input['description']),
            'status'      => FeedbackReport::STATUS_NEW,
            'page_url'    => $this->redactor->url($input['page_url'] ?? null),
            'route_name'  => $this->shortString($input['route_name'] ?? null, max: 191),
            'hub_app'     => $this->shortString($input['hub_app'] ?? null, max: 64),
            'context'     => $this->context(context: $input['context'] ?? []),
        ];

        if ($screenshot === null) {
            $report = FeedbackReport::query()->create($attributes);
            FeedbackLog::write(event: 'report_created', ctx: ['report_id' => (int) $report->id, 'type' => $report->type, 'screenshot' => false]);
            $this->notifier->reportCreated(report: $report);

            return $report;
        }

        $mime = (string) $screenshot->getMimeType();
        $object = $this->contentStore->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: (string) $screenshot->getRealPath(),
            mimeType: $mime,
            extension: self::SCREENSHOT_MIMES[$mime] ?? 'png',
        );
        $objectIds = [(int) $object->getKey()];

        try {
            $report = DB::transaction(function () use ($object, $attributes, &$objectIds): FeedbackReport {
                if (DB::table('admins')->where('id', $attributes['admin_id'])->lockForUpdate()->first() === null) {
                    throw new RuntimeException('The reporter no longer exists.');
                }

                $live = $this->contentStore->retain($object);
                $objectIds[] = (int) $live->getKey();

                return FeedbackReport::query()->create($attributes + ['screenshot_file_object_id' => (int) $live->getKey()]);
            });
        } catch (\Throwable $failure) {
            $this->releaseAfterFailedAttach(objectIds: $objectIds);

            throw $failure;
        }

        FeedbackLog::write(event: 'report_created', ctx: ['report_id' => (int) $report->id, 'type' => $report->type, 'screenshot' => true, 'size' => (int) $screenshot->getSize()]);
        $this->notifier->reportCreated(report: $report);

        return $report;
    }

    /** Moves a report to $status, recording the change as a note the reporter can see, and tells the reporter. */
    public function setStatus(FeedbackReport $report, Admin $actor, string $status): FeedbackReport
    {
        $from = (string) $report->status;

        if ($from === $status) {
            return $report;
        }

        DB::transaction(function () use ($report, $actor, $status, $from): void {
            $report->status = $status;
            $report->resolved_at = in_array($status, FeedbackReport::RESOLVED_STATUSES, true) ? now() : null;
            $report->save();

            FeedbackNote::query()->create([
                'report_id'           => (int) $report->id,
                'admin_id'            => (int) $actor->getKey(),
                'kind'                => FeedbackNote::KIND_STATUS,
                'visible_to_reporter' => true,
                'from_status'         => $from,
                'to_status'           => $status,
            ]);
        });

        FeedbackLog::write(event: 'status_changed', ctx: ['report_id' => (int) $report->id, 'from' => $from, 'to' => $status]);
        $this->notifier->statusChanged(report: $report, actor: $actor, to: $status);

        return $report;
    }

    public function addNote(FeedbackReport $report, Admin $author, string $body, bool $visibleToReporter): FeedbackNote
    {
        $note = FeedbackNote::query()->create([
            'report_id'           => (int) $report->id,
            'admin_id'            => (int) $author->getKey(),
            'kind'                => FeedbackNote::KIND_NOTE,
            'body'                => trim($body),
            'visible_to_reporter' => $visibleToReporter,
        ]);
        $report->touch();

        FeedbackLog::write(event: 'note_added', ctx: ['report_id' => (int) $report->id, 'visible' => $visibleToReporter]);

        return $note;
    }

    /**
     * Keeps the allowlisted context keys as short strings, and the console lines as {ts, kind, message, source, line}
     * with every message redacted. The server adds what it knows itself (build, time) instead of trusting the browser.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        $kept = [];

        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? null;

            if (is_string($value) || is_int($value) || is_float($value)) {
                $kept[$key] = $this->redactor->console((string) $value, max: 120);
            }
        }

        $console = [];

        foreach (array_slice(is_array($context['console'] ?? null) ? $context['console'] : [], -self::CONSOLE_MAX) as $line) {
            if (! is_array($line) || ! is_string($line['message'] ?? null)) {
                continue;
            }

            $console[] = array_filter([
                'ts'      => $this->timestamp($line['ts'] ?? null),
                'kind'    => in_array($line['kind'] ?? null, self::CONSOLE_KINDS, true) ? $line['kind'] : 'console',
                'message' => $this->redactor->console($line['message']),
                'source'  => is_string($line['source'] ?? null) ? $this->redactor->url($line['source'], max: 300) : null,
                'line'    => is_int($line['line'] ?? null) ? $line['line'] : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        $kept['console'] = $console;
        $kept['build'] = FeedbackLog::build();
        $kept['server_time'] = now()->toIso8601String();

        return $kept;
    }

    /** A console line's time, only when it is an ISO-8601 timestamp: anything else is dropped, never stored as given. */
    private function timestamp(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 40 && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $value) === 1 ? $value : null;
    }

    private function shortString(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /** @param  array<int, int>  $objectIds */
    private function releaseAfterFailedAttach(array $objectIds): void
    {
        foreach (array_unique($objectIds) as $objectId) {
            try {
                $tombstones = DB::transaction(function () use ($objectId): array {
                    $object = FileObject::query()->whereKey($objectId)->lockForUpdate()->first();

                    if ($object === null || $object->isReferenced()) {
                        return [];
                    }

                    $object->delete();

                    return $this->deletion->queueFileDeletions(collect([
                        ['disk' => (string) $object->disk, 'path' => (string) $object->path],
                        ['disk' => (string) $object->disk, 'path' => $this->contentStore->objectPath((string) $object->sha256)],
                    ])->unique(fn (array $location): string => $location['disk']."\0".$location['path'])->values()->all());
                });

                $this->reap(tombstoneIds: $tombstones);
            } catch (\Throwable $e) {
                // The original failure is what the caller needs; this one is reported and its row stays for repair.
                report($e);
                FeedbackLog::write(event: 'screenshot_release_failed', ctx: ['error_class' => class_basename($e)], level: 'error');
            }
        }
    }

    /**
     * Unlinks the tombstoned blobs once the outermost transaction commits (at once outside one), as HubResourceStore
     * does: a caller's rollback can restore a row whose bytes must still be there. A failure stays on its tombstone for
     * `dam:storage:reap`.
     *
     * @param  array<int, int>  $tombstoneIds
     */
    private function reap(array $tombstoneIds): void
    {
        if ($tombstoneIds === []) {
            return;
        }

        DB::afterCommit(function () use ($tombstoneIds): void {
            try {
                $this->deletion->processPendingFileDeletions($tombstoneIds);
            } catch (\Throwable $e) {
                report($e);
                FeedbackLog::write(event: 'screenshot_reap_deferred', ctx: ['count' => count($tombstoneIds), 'error_class' => class_basename($e)], level: 'error');
            }
        });
    }
}
