<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Services\Security\SecurityReports;
use Mothership\User\Models\Admin;

/**
 * Staff-only security reports (P1a). Both routes are mapped to dam.reports.security in Config/acl.php, a key no
 * Sandbox capability carries; the check here holds even if that mapping is ever lost. Each view is itself a
 * security event (`report.viewed`, §3.6.8).
 */
class SecurityReportController extends Controller
{
    public const PERMISSION = 'dam.reports.security';

    public function __construct(
        private readonly SecurityReports $reports,
        private readonly SecurityEventRecorder $recorder,
    ) {}

    public function loginAccess(Request $request): View
    {
        $days = $this->authorizeView(request: $request, report: 'login_access');

        return view('dam::reports.login-access', ['days' => $days, 'ranges' => SecurityReports::RANGES] + $this->reports->loginAccess(days: $days));
    }

    public function operationalHealth(Request $request): View
    {
        $days = $this->authorizeView(request: $request, report: 'operational_health');
        $viewer = auth('admin')->user();
        abort_unless($viewer instanceof Admin, 403);

        return view('dam::reports.operational-health', ['days' => $days, 'ranges' => SecurityReports::RANGES] + $this->reports->operationalHealth(days: $days, viewer: $viewer));
    }

    private function authorizeView(Request $request, string $report): int
    {
        abort_unless(bouncer()->hasPermission(self::PERMISSION), 403);

        $days = SecurityReports::range(days: $request->query('days'));

        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::REPORT_VIEWED,
            guard: 'admin',
            actorAdminId: (int) auth('admin')->id(),
            ctx: ['report' => $report, 'range_days' => $days],
        ));

        return $days;
    }
}
