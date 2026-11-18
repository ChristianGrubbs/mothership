<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\DirectoryPermissionService;

class UploadController extends Controller
{
    public function __construct(
        protected DirectoryPermissionService $permissionService,
    ) {}

    public function startSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_uuid' => 'required|uuid',
            'directory_id' => ['required', new VisibleDirectory],
            'total'        => 'nullable|integer|min:0',
        ]);

        if (! $this->permissionService->canAccess((int) $data['directory_id'])) {
            return $this->unauthorized();
        }

        abort_unless(auth('admin')->id() !== null, 403);

        $tracker = UploadTracker::firstOrCreate(['uuid' => $data['session_uuid']], [
            'user_id'      => auth('admin')->id(),
            'directory_id' => (int) $data['directory_id'],
            'state'        => UploadTracker::STATE_PROCESSING,
            'started_at'   => now(),
        ]);

        abort_unless(
            $tracker->user_id !== null
            && (int) $tracker->user_id === (int) auth('admin')->id()
            && $tracker->directory_id !== null
            && (int) $tracker->directory_id === (int) $data['directory_id'],
            404,
        );

        $tracker->fill([
            'state'        => UploadTracker::STATE_PROCESSING,
            'total_files'  => (int) ($data['total'] ?? $tracker->total_files ?? 0),
        ]);

        $tracker->save();

        return new JsonResponse(['success' => true, 'tracker' => $this->present($tracker)], 201);
    }

    public function stats(string $uuid): JsonResponse
    {
        $tracker = $this->authorizedTracker($uuid);

        if (! $tracker instanceof UploadTracker) {
            return $tracker;
        }

        return new JsonResponse(['success' => true, 'tracker' => $this->present($tracker)]);
    }

    public function pause(string $uuid): JsonResponse
    {
        $tracker = $this->authorizedTracker($uuid);

        if (! $tracker instanceof UploadTracker) {
            return $tracker;
        }

        if ($tracker->isActive()) {
            $tracker->update(['state' => UploadTracker::STATE_PAUSED]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => trans('dam::app.admin.dam.upload.paused'),
            'tracker' => $this->present($tracker->refresh()),
        ]);
    }

    public function resume(string $uuid): JsonResponse
    {
        $tracker = $this->authorizedTracker($uuid);

        if (! $tracker instanceof UploadTracker) {
            return $tracker;
        }

        if ($tracker->state === UploadTracker::STATE_PAUSED) {
            $tracker->update(['state' => UploadTracker::STATE_PROCESSING]);

            $this->redispatch($tracker, [UploadBatch::STATE_PENDING]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => trans('dam::app.admin.dam.upload.resumed'),
            'tracker' => $this->present($tracker->refresh()),
        ]);
    }

    public function cancel(string $uuid): JsonResponse
    {
        $tracker = $this->authorizedTracker($uuid);

        if (! $tracker instanceof UploadTracker) {
            return $tracker;
        }

        if ($tracker->isActive()) {
            $tracker->batches()
                ->where('state', UploadBatch::STATE_PENDING)
                ->update(['state' => UploadBatch::STATE_CANCELLED]);

            $tracker->update([
                'state'        => UploadTracker::STATE_CANCELLED,
                'completed_at' => now(),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => trans('dam::app.admin.dam.upload.cancelled'),
            'tracker' => $this->present($tracker->refresh()),
        ]);
    }

    public function retry(string $uuid): JsonResponse
    {
        $tracker = $this->authorizedTracker($uuid);

        if (! $tracker instanceof UploadTracker) {
            return $tracker;
        }

        $failed = $tracker->batches()->where('state', UploadBatch::STATE_FAILED)->get();

        if ($failed->isNotEmpty()) {
            $tracker->batches()
                ->whereIn('id', $failed->pluck('id'))
                ->update([
                    'state'             => UploadBatch::STATE_PENDING,
                    'error'             => null,
                    'recovery_attempts' => 0,
                    'last_recovered_at' => null,
                ]);

            $tracker->update([
                'state'        => UploadTracker::STATE_PROCESSING,
                'failed_files' => max(0, $tracker->failed_files - $failed->count()),
                'completed_at' => null,
            ]);

            foreach ($failed as $batch) {
                if ($batch->asset_id) {
                    ProcessAssetUpload::dispatch($batch->asset_id, $batch->id);
                }
            }
        }

        return new JsonResponse([
            'success'      => true,
            'message'      => trans('dam::app.admin.dam.upload.retried'),
            'retried'      => $failed->count(),
            'tracker'      => $this->present($tracker->refresh()),
        ]);
    }

    public function complete(string $uuid): JsonResponse
    {
        $tracker = $this->authorizedTracker($uuid);

        if (! $tracker instanceof UploadTracker) {
            return $tracker;
        }

        if (in_array($tracker->state, [UploadTracker::STATE_CANCELLED, UploadTracker::STATE_FAILED], true)) {
            return new JsonResponse(['success' => true, 'tracker' => $this->present($tracker)]);
        }

        $tracker->update(['total_files' => $tracker->batches()->count()]);

        $open = $tracker->batches()
            ->whereIn('state', [UploadBatch::STATE_PENDING, UploadBatch::STATE_PROCESSING])
            ->exists();

        if (! $open) {
            $tracker->update([
                'state'        => UploadTracker::STATE_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        return new JsonResponse(['success' => true, 'tracker' => $this->present($tracker->refresh())]);
    }

    protected function redispatch(UploadTracker $tracker, array $states): void
    {
        $batches = $tracker->batches()
            ->whereIn('state', $states)
            ->whereNotNull('asset_id')
            ->get();

        foreach ($batches as $batch) {
            $batch->update(['recovery_attempts' => 0, 'last_recovered_at' => null, 'updated_at' => now()]);
            ProcessAssetUpload::dispatch($batch->asset_id, $batch->id);
        }
    }

    protected function authorizedTracker(string $uuid)
    {
        $tracker = $this->findTracker($uuid);

        if (! $tracker) {
            return new JsonResponse(['success' => false], 404);
        }

        if ($tracker->user_id === null
            || auth('admin')->id() === null
            || (int) $tracker->user_id !== (int) auth('admin')->id()) {
            return new JsonResponse(['success' => false], 404);
        }

        return $tracker;
    }

    protected function findTracker(string $uuid): ?UploadTracker
    {
        return UploadTracker::where('uuid', $uuid)->first();
    }

    protected function present(UploadTracker $tracker): array
    {
        return [
            'uuid'            => $tracker->uuid,
            'state'           => $tracker->state,
            'total_files'     => (int) $tracker->total_files,
            'processed_files' => (int) $tracker->processed_files,
            'failed_files'    => (int) $tracker->failed_files,
        ];
    }

    protected function unauthorized(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => trans('dam::app.admin.permissions.denied-upload'),
        ], 403);
    }
}
