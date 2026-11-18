<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\DataGrids\Feedback\FeedbackDataGrid;
use Mothership\DAM\Models\FeedbackReport;
use Mothership\DAM\Services\Feedback\FeedbackAccess;
use Mothership\User\Models\Admin;

/**
 * Settings → Feedback: the triage page. The grid and its drawer read and
 * write through FeedbackController's show/update/addNote routes; this page only lists. FeedbackAccess::canManage()
 * decides who may open it: anyone else gets 403, and the whole page answers 404 while dam.feedback.enabled is off.
 */
class FeedbackAdminController extends Controller
{
    public function __construct(
        private FeedbackAccess $access,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        abort_unless((bool) config('dam.feedback.enabled'), 404);
        /** @var Admin|null $admin */
        $admin = auth()->guard('admin')->user();
        abort_unless($this->access->canManage($admin), 403);

        if ($request->ajax() || $request->wantsJson()) {
            return app(FeedbackDataGrid::class)->toJson();
        }

        return view('dam::feedback.admin.index', [
            'statuses'   => FeedbackReport::STATUSES,
            'types'      => FeedbackReport::TYPES,
            'severities' => FeedbackReport::SEVERITIES,
            'openReport' => $request->integer('report') ?: null,
        ]);
    }
}
