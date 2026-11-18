<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\FeedbackNote;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Feedback\FeedbackAccess;
use Mothership\DAM\Services\Feedback\FeedbackLog;
use Mothership\DAM\Services\Feedback\FeedbackStore;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feedback & bug reports. Every signed-in person sends reports and reads their
 * own; FeedbackAccess::canManage() decides who triages. A report the caller may not read answers 404, like a missing one.
 */
class FeedbackController extends Controller
{
    public function __construct(
        private FeedbackAccess $access,
        private FeedbackStore $store,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $admin = $this->submitter();
        // One report, screenshot included, stays under this whatever else the request carries.
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > (int) config('dam.feedback.request_max_bytes'), 413);
        $contextMax = (int) config('dam.feedback.context_max_bytes');
        $data = $request->validate([
            'type'        => ['required', Rule::in(FeedbackReport::TYPES)],
            'severity'    => ['nullable', Rule::in(FeedbackReport::SEVERITIES)],
            'title'       => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'page_url'    => ['nullable', 'string', 'max:4096'],
            'route_name'  => ['nullable', 'string', 'max:191'],
            'hub_app'     => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'context'     => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($contextMax): void {
                if (is_string($value) && strlen($value) > $contextMax) {
                    $fail(trans('dam::app.admin.feedback.errors.context'));
                }
            }],
            'screenshot'  => ['nullable', 'file', 'max:'.intdiv((int) config('dam.feedback.screenshot_max_bytes'), 1024)],
        ]);

        $context = [];

        if (isset($data['context'])) {
            $decoded = json_decode($data['context'], true);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['context' => trans('dam::app.admin.feedback.errors.context')]);
            }

            $context = $decoded;
        }

        /** @var UploadedFile|null $screenshot */
        $screenshot = $request->file('screenshot');

        if ($screenshot !== null) {
            $this->assertScreenshot(file: $screenshot);
        }

        $report = $this->store->create(reporter: $admin, input: ['context' => $context] + $data, screenshot: $screenshot);

        return new JsonResponse([
            'message' => trans('dam::app.admin.feedback.sent', ['id' => $report->id]),
            'report'  => $this->present(report: $report, viewer: $admin),
        ], 201);
    }

    /** The caller's own reports, newest first: the My reports page, or JSON when asked for it. */
    public function mine(Request $request): JsonResponse|View
    {
        $admin = $this->submitter();
        $reports = FeedbackReport::query()->where('admin_id', $admin->getKey())->orderByDesc('id')->limit(200)->get();

        if ($request->wantsJson()) {
            return new JsonResponse(['data' => $reports->map(fn (FeedbackReport $report): array => $this->present(report: $report, viewer: $admin, withNotes: false, asReporter: true))->all()]);
        }

        return view('dam::feedback.mine', [
            'reports' => $reports->map(fn (FeedbackReport $report): array => $this->present(report: $report, viewer: $admin, asReporter: true))->all(),
        ]);
    }

    public function show(int $report): JsonResponse
    {
        $admin = $this->submitter();

        return new JsonResponse(['report' => $this->present(report: $this->readable(id: $report, admin: $admin), viewer: $admin)]);
    }

    public function screenshot(int $report, ContentStore $contentStore): Response
    {
        $admin = $this->submitter();
        $found = $this->readable(id: $report, admin: $admin);
        $object = $found->screenshot_file_object_id === null ? null : FileObject::query()->find($found->screenshot_file_object_id);
        abort_if($object === null, 404);

        return $contentStore->deliver(
            object: $object,
            filename: 'feedback-'.$found->id.'.'.($object->extension ?: 'png'),
            disposition: HeaderUtils::DISPOSITION_INLINE,
            mimeType: (string) ($object->mime_type ?: 'image/png'),
            headers: ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff'],
            sameOrigin: true,
        );
    }

    /** Triage: status and the linked GitHub issue. */
    public function update(Request $request, int $report): JsonResponse
    {
        $admin = $this->manager();
        $found = $this->readable(id: $report, admin: $admin);
        $data = $request->validate([
            'status'           => ['sometimes', Rule::in(FeedbackReport::STATUSES)],
            'github_issue_url' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(issues|pull)/\d+$#'],
        ]);

        if (array_key_exists('github_issue_url', $data)) {
            $found->github_issue_url = $data['github_issue_url'];
            $found->save();
        }

        if (isset($data['status'])) {
            $this->store->setStatus(report: $found, actor: $admin, status: $data['status']);
        }

        return new JsonResponse(['report' => $this->present(report: $found->refresh(), viewer: $admin)]);
    }

    public function addNote(Request $request, int $report): JsonResponse
    {
        $admin = $this->manager();
        $found = $this->readable(id: $report, admin: $admin);
        $data = $request->validate([
            'body'                => ['required', 'string', 'max:5000'],
            'visible_to_reporter' => ['sometimes', 'boolean'],
        ]);

        $this->store->addNote(report: $found, author: $admin, body: $data['body'], visibleToReporter: (bool) ($data['visible_to_reporter'] ?? false));

        return new JsonResponse(['report' => $this->present(report: $found->refresh(), viewer: $admin)], 201);
    }

    /**
     * The JSON a report reads as. The reporter sees the visible notes only; a triager sees all of them and the context.
     * $asReporter keeps to the reporter's view even for a triager, as My reports always does.
     *
     * @return array<string, mixed>
     */
    public function present(FeedbackReport $report, Admin $viewer, bool $withNotes = true, bool $asReporter = false): array
    {
        $manager = ! $asReporter && $this->access->canManage($viewer);
        $out = [
            'id'               => (int) $report->id,
            'type'             => $report->type,
            'severity'         => $report->severity,
            'title'            => $report->title,
            'description'      => $report->description,
            'status'           => $report->status,
            'page_url'         => $report->page_url,
            'route_name'       => $report->route_name,
            'hub_app'          => $report->hub_app,
            'has_screenshot'   => $report->screenshot_file_object_id !== null,
            'screenshot_url'   => $report->screenshot_file_object_id !== null ? route('admin.dam.feedback.screenshot', $report->id) : null,
            'github_issue_url' => $report->github_issue_url,
            'created_at'       => $report->created_at?->toIso8601String(),
            'updated_at'       => $report->updated_at?->toIso8601String(),
            'resolved_at'      => $report->resolved_at?->toIso8601String(),
            'context'          => $report->context,
        ];

        if ($manager) {
            $out['reporter'] = $report->reporter === null ? null : ['id' => (int) $report->reporter->id, 'name' => $report->reporter->name, 'email' => $report->reporter->email];
        }

        if ($withNotes) {
            $notes = FeedbackNote::query()->where('report_id', $report->id)->with('author:id,name')->orderBy('id')->get();
            $out['notes'] = [];

            foreach ($notes as $note) {
                if (! $manager && ! $note->visible_to_reporter) {
                    continue;
                }

                $out['notes'][] = [
                    'id'                  => (int) $note->id,
                    'kind'                => $note->kind,
                    'body'                => $note->body,
                    'visible_to_reporter' => (bool) $note->visible_to_reporter,
                    'from_status'         => $note->from_status,
                    'to_status'           => $note->to_status,
                    'author'              => $note->author === null ? null : (string) $note->author->name,
                    'created_at'          => $note->created_at?->toIso8601String(),
                ];
            }
        }

        return $out;
    }

    private function assertScreenshot(UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $size = @getimagesize((string) $file->getRealPath());
        $max = (int) config('dam.feedback.screenshot_max_px');

        if (! array_key_exists($mime, FeedbackStore::SCREENSHOT_MIMES) || $size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > $max || $size[1] > $max) {
            FeedbackLog::write(event: 'screenshot_refused', ctx: ['reason' => $size === false ? 'unreadable' : 'type_or_size']);

            throw ValidationException::withMessages(['screenshot' => trans('dam::app.admin.feedback.errors.screenshot', ['px' => $max])]);
        }
    }

    private function readable(int $id, Admin $admin): FeedbackReport
    {
        $report = FeedbackReport::query()->find($id);
        abort_unless($report !== null && $this->access->canView($admin, $report), 404);

        return $report;
    }

    private function submitter(): Admin
    {
        abort_unless((bool) config('dam.feedback.enabled'), 404);
        /** @var Admin|null $admin */
        $admin = auth()->guard('admin')->user();
        abort_unless($this->access->canSubmit($admin), 403);

        return $admin;
    }

    private function manager(): Admin
    {
        $admin = $this->submitter();
        abort_unless($this->access->canManage($admin), 403);

        return $admin;
    }
}
