<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Str;

/**
 * P1b: one file's upload on a path that settles it in the request (folder, re-upload, Hub, API). Its attempt and its
 * one terminal event share an `upload_id`, as on the direct and chunked paths, so a funnel joins them; a second
 * terminal call is ignored.
 */
final class UploadEvents
{
    private bool $settled = false;

    private function __construct(
        private readonly string $path,
        private readonly string $uploadId,
        private readonly ?int $directoryId,
        private readonly ?AnalyticsActor $actor,
    ) {}

    /** Records the attempt. `$actor` is null for the signed-in admin of the request. */
    public static function start(string $path, ?int $directoryId, ?AnalyticsActor $actor = null): self
    {
        $upload = new self(path: $path, uploadId: substr(str_replace('-', '', (string) Str::uuid()), 0, 24), directoryId: $directoryId, actor: $actor);
        $upload->emit(event: DamEvent::UploadAttempted, props: ['attempt' => 1]);

        return $upload;
    }

    public function succeeded(): void
    {
        $this->settle(event: DamEvent::UploadSucceeded, props: []);
    }

    public function failed(string $errorCode): void
    {
        $this->settle(event: DamEvent::UploadFailed, props: ['error_code' => substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $errorCode) ?: 'unknown', 0, 48)]);
    }

    /** @param array<string, int|string> $props */
    private function settle(DamEvent $event, array $props): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->emit(event: $event, props: $props);
    }

    /** @param array<string, int|string> $props */
    private function emit(DamEvent $event, array $props): void
    {
        DamEvents::emit(
            event: $event,
            subjectId: $this->directoryId,
            props: ['upload_id' => $this->uploadId, 'path' => $this->path] + $props,
            surface: 'upload',
            actor: $this->actor,
            businessId: $this->uploadId,
            attempt: '1',
        );
    }
}
