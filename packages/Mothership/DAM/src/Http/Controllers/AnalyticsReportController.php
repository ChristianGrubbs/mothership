<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Mothership\DAM\Analytics\AnalyticsReports;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * The product analytics dashboards (P1b, §3.6.8-§3.6.9). Each route maps to its key in Config/acl.php and checks it
 * again here: dam.reports.view for the aggregate dashboards, dam.reports.journeys for session timelines, and
 * dam.reports.identify, which no route carries, decides whether a timeline names the person or shows a pseudonym.
 * Every figure is of what the viewer can see now (AnalyticsReports via ReportScope). Each view is audited as a
 * security event, as the P1a reports are.
 */
class AnalyticsReportController extends Controller
{
    public const VIEW = 'dam.reports.view';

    public const JOURNEYS = 'dam.reports.journeys';

    public const IDENTIFY = 'dam.reports.identify';

    public function __construct(
        private readonly AnalyticsReports $reports,
        private readonly SecurityEventRecorder $recorder,
    ) {}

    public function engagement(Request $request): View
    {
        [$viewer, $days] = $this->authorizeView(request: $request, permission: self::VIEW, report: 'engagement');

        return view('dam::reports.analytics.engagement', $this->shell(current: 'engagement', days: $days) + $this->reports->engagement(viewer: $viewer, days: $days));
    }

    public function friction(Request $request): View
    {
        [$viewer, $days] = $this->authorizeView(request: $request, permission: self::VIEW, report: 'friction');

        return view('dam::reports.analytics.friction', $this->shell(current: 'friction', days: $days) + $this->reports->friction(viewer: $viewer, days: $days));
    }

    public function adoption(Request $request): View
    {
        [$viewer, $days] = $this->authorizeView(request: $request, permission: self::VIEW, report: 'adoption');
        $roles = Role::query()->orderBy('name')->pluck('name', 'id')->all();
        $roleId = $request->filled('role') && isset($roles[(int) $request->query('role')]) ? (int) $request->query('role') : null;

        return view('dam::reports.analytics.adoption', $this->shell(current: 'adoption', days: $days) + [
            'roles'  => $roles,
            'roleId' => $roleId,
        ] + $this->reports->adoption(viewer: $viewer, days: $days, roleId: $roleId));
    }

    public function journeys(Request $request): View
    {
        [$viewer, $days] = $this->authorizeView(request: $request, permission: self::JOURNEYS, report: 'journeys');

        return view('dam::reports.analytics.journeys', $this->shell(current: 'journeys', days: $days) + [
            'identify' => bouncer()->hasPermission(self::IDENTIFY),
            'sessions' => $this->reports->sessions(viewer: $viewer, days: $days, identify: bouncer()->hasPermission(self::IDENTIFY)),
        ]);
    }

    public function journey(Request $request, string $session): View
    {
        [$viewer, $days] = $this->authorizeView(request: $request, permission: self::JOURNEYS, report: 'journey');
        $timeline = $this->reports->timeline(viewer: $viewer, sessionId: $session, identify: bouncer()->hasPermission(self::IDENTIFY));

        // A session with nothing the viewer may see is answered like one that never existed.
        abort_if($timeline['events'] === [], 404);

        return view('dam::reports.analytics.journey', $this->shell(current: 'journeys', days: $days) + ['session' => $session] + $timeline);
    }

    /** @return array{0: Admin, 1: int} */
    private function authorizeView(Request $request, string $permission, string $report): array
    {
        abort_unless(bouncer()->hasPermission($permission), 403);
        $viewer = auth('admin')->user();
        abort_unless($viewer instanceof Admin, 403);

        $days = AnalyticsReports::range(days: $request->query('days'));

        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::REPORT_VIEWED,
            guard: 'admin',
            actorAdminId: (int) $viewer->id,
            ctx: ['report' => $report, 'range_days' => $days],
        ));

        return [$viewer, $days];
    }

    /** @return array<string, mixed> */
    private function shell(string $current, int $days): array
    {
        return ['current' => $current, 'days' => $days, 'ranges' => AnalyticsReports::RANGES];
    }
}
