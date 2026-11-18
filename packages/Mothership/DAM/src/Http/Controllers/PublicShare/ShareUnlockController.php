<?php

namespace Mothership\DAM\Http\Controllers\PublicShare;

use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Share\ShareGate;

/**
 * P2 S5b: the password prompt's answer. A right password sets the unlock cookie and returns the visitor to the page
 * they asked for; a wrong one shows the prompt again, until the throttle locks it for the rest of the minute.
 */
class ShareUnlockController extends Controller
{
    public function __construct(
        protected ShareGate $gate,
    ) {}

    public function unlock(Request $request, string $token)
    {
        $share = $this->gate->activeShare($token);
        $assetId = filter_var($request->input('asset_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        // Unknown, expired, revoked, closed or unprotected: the landing page gives that link its usual answer.
        if ($share === null || ! $share->hasPassword()) {
            return redirect()->route('dam.share.show', ['token' => $token]);
        }

        // Only ever back to this link's own landing or member page, never a URL the form names.
        $back = $assetId !== null && $share->isContainer()
            ? route('dam.share.asset_view', ['token' => $token, 'assetId' => $assetId])
            : route('dam.share.show', ['token' => $token]);

        $password = $request->input('password');
        $result = $this->gate->attempt(request: $request, share: $share, password: is_string($password) ? $password : '');

        if ($result['outcome'] === 'ok') {
            return redirect()->to($back)->withCookie($this->gate->unlockCookie(request: $request, share: $share));
        }

        $status = $result['outcome'] === 'locked' ? 429 : 401;

        return response()->view('dam::share.public.password', [
            'share'      => $share,
            'assetId'    => $share->isContainer() ? $assetId : null,
            'error'      => $result['outcome'],
            'retryAfter' => $result['retry_after'],
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
