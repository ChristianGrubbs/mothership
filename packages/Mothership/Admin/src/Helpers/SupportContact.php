<?php

namespace Mothership\Admin\Helpers;

class SupportContact
{
    /**
     * Domains that can never receive mail (reserved/placeholder TLDs).
     */
    protected const UNDELIVERABLE_SUFFIXES = ['.local', '.test', '.example', '.invalid', '.localhost'];

    /**
     * The configured admin email, or null when it is empty or not deliverable.
     */
    public static function email(): ?string
    {
        $email = core()->getAdminEmailDetails()['email'];

        return static::isDeliverable(email: $email) ? trim($email) : null;
    }

    /**
     * Whether the address is non-empty, well-formed and not on a reserved domain.
     */
    public static function isDeliverable(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = substr($email, strrpos($email, '@') + 1);

        foreach (static::UNDELIVERABLE_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
