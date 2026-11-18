<?php

namespace Mothership\Admin\Http\Controllers;

use App\Documentation\Manual;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Mothership\Admin\Helpers\VersionCheck;
use Mothership\Admin\Http\Requests\PromoDismissRequest;
use Mothership\User\Repositories\AdminPromoDismissalRepository;

class HelpController extends Controller
{
    /**
     * Create a controller instance.
     */
    public function __construct(protected VersionCheck $versionCheck) {}

    /**
     * Display the help & resources page.
     */
    public function index(): View
    {
        $data = request()->validate(['q' => ['nullable', 'string', 'max:160']]);

        return view('admin::help.index', [
            'entries' => app(Manual::class)->search($data['q'] ?? ''),
            'query'   => $data['q'] ?? '',
        ]);
    }

    /**
     * Persist a promo banner dismissal for the current admin.
     */
    public function dismissPromo(PromoDismissRequest $request): JsonResponse
    {
        $banner = $request->input('banner');

        // Derive the version server-side (mirrors PromoBanner) instead of trusting the client.
        $version = $banner === 'upgrade'
            ? (string) $this->versionCheck->latestVersion()
            : '';

        app(AdminPromoDismissalRepository::class)->dismiss(
            auth()->guard('admin')->id(),
            $banner,
            $version,
        );

        return new JsonResponse(['message' => trans('admin::app.help.banners.dismissed')]);
    }
}
