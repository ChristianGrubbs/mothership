<?php

namespace Mothership\DAM\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies;

/**
 * The `web` group's EncryptCookies, except that the download-start cookies (SignalDownloadStart) are left plain: the
 * page reads them from `document.cookie`, and the name carries a random token, so no fixed name can be listed in
 * EncryptCookies::except() (it takes no wildcard, and a name added per request would pile up under Octane).
 */
class SignalDownloadStartCookies extends EncryptCookies
{
    public function isDisabled($name)
    {
        if (is_string($name) && str_starts_with($name, SignalDownloadStart::COOKIE_PREFIX)
            && preg_match(SignalDownloadStart::TOKEN_PATTERN, substr($name, strlen(SignalDownloadStart::COOKIE_PREFIX))) === 1) {
            return true;
        }

        return parent::isDisabled($name);
    }
}
