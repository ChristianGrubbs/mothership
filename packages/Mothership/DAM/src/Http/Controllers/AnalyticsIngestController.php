<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Mothership\DAM\Analytics\Ingest;
use Mothership\User\Models\Admin;

/**
 * The collector's endpoints (P1b, §3.6.6): POST /admin/dam/t for staff pages and POST /share/t for share pages. Both
 * answer 204 with no body as soon as the batch is checked and stored, so tracking never holds up the page, and the
 * collector drops a batch it cannot send. The body is read as JSON whatever its content type, because
 * navigator.sendBeacon posts text/plain.
 *
 * Neither route takes the CSRF token: the signed ingest capability in the body is page-bound and short-lived, so a
 * cross-site form cannot produce one, and a beacon cannot carry a header.
 */
class AnalyticsIngestController extends Controller
{
    /** The header an admin page's collector reads a renewed capability from. */
    public const RENEW_HEADER = 'X-Dam-T';

    public function __construct(
        private readonly Ingest $ingest,
    ) {}

    public function admin(Request $request): Response
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $this->handle(request: $request, admin: $admin);
    }

    public function public(Request $request): Response
    {
        return $this->handle(request: $request, admin: null);
    }

    private function handle(Request $request, ?Admin $admin): Response
    {
        $body = $request->getContent();

        if (strlen($body) > (int) config('dam_events.ingest.max_bytes', 16384)) {
            return response()->noContent(413);
        }

        $batch = json_decode($body, true);

        if (! is_array($batch)) {
            return response()->noContent(400);
        }

        $result = $this->ingest->handle(batch: $batch, request: $request, admin: $admin);

        if (! $result->accepted) {
            return response()->noContent(403);
        }

        $response = response()->noContent();

        if ($result->renewal !== null) {
            $response->headers->set(self::RENEW_HEADER, $result->renewal);
        }

        return $response;
    }
}
