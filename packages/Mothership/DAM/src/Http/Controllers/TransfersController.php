<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Transfers\TransferSession;

final class TransfersController extends Controller
{
    public function __construct(private readonly TransferSession $transfers) {}

    public function index(Request $request): View
    {
        $config = $this->transfers->clientConfig(request: $request);
        abort_if($config === null, 404);

        return view('dam::transfers.index', ['transfers' => $config]);
    }

    public function grant(Request $request): JsonResponse
    {
        return response()->json($this->transfers->issue(request: $request))
            ->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
