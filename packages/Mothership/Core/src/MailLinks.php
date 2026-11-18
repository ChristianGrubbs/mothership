<?php

namespace Mothership\Core;

use Mothership\Core\Rules\MailLinkBaseUrl;

/**
 * The site address written in outgoing account mail. Account mail carries no clickable link (mail from a new
 * sender with a link lands in Junk), so the address is a bare host name: with the Email setting
 * `mail_link_base_url` set (and still acceptable, see MailLinkBaseUrl) its host, otherwise the host of APP_URL.
 */
final class MailLinks
{
    public const CONFIG_KEY = 'emails.configure.email_settings.mail_link_base_url';

    /**
     * Host name only: no scheme, no path, no port.
     */
    public static function host(): string
    {
        $base = MailLinkBaseUrl::origin(value: core()->getConfigData(self::CONFIG_KEY));

        return strtolower((string) parse_url($base ?? (string) config('app.url'), PHP_URL_HOST));
    }
}
