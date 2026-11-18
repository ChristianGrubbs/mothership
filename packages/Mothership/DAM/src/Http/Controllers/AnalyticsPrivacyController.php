<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\OptOuts;
use Mothership\DAM\Support\AnalyticsLog;
use Mothership\User\Models\Admin;

/**
 * The usage-analytics notice and the per-person opt-out (P1b, §3.6.7). Open to every signed-in admin, whatever
 * their role: neither route is in Config/acl.php, because anyone must be able to read what is collected about them
 * and switch it off. Opting out takes effect on the next event, at the emitter and at ingest alike.
 */
class AnalyticsPrivacyController extends Controller
{
    public function __construct(
        private readonly OptOuts $optOuts,
    ) {}

    public function show(Request $request): View
    {
        $admin = $this->admin();

        return view('dam::analytics.privacy', [
            'optedOut'      => $this->optOuts->has($admin->id),
            'privacySignal' => ActorResolver::privacySignal($request),
            'retention'     => config('dam_events.retention'),
            'k'             => (int) config('dam_events.k', 3),
            'enabled'       => (bool) config('dam_events.enabled', true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $optedOut = $request->boolean('opt_out');

        $this->optOuts->set(adminId: (int) $admin->id, optedOut: $optedOut);
        AnalyticsLog::write(level: 'info', event: 'analytics.opt_out.changed', ctx: ['admin_id' => (int) $admin->id, 'opted_out' => $optedOut]);

        session()->flash('success', trans($optedOut ? 'dam::app.admin.analytics.privacy.opted-out' : 'dam::app.admin.analytics.privacy.opted-in'));

        return redirect()->route('admin.dam.analytics.privacy');
    }

    private function admin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
