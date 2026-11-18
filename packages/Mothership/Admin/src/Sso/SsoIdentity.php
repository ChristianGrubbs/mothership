<?php

namespace Mothership\Admin\Sso;

final readonly class SsoIdentity
{
    /**
     * @param  string  $identifier  Provider-side immutable subject id. Accounts are matched on it
     *                              alone; the email is never used to find or link an admin.
     * @param  bool  $emailVerified  Whether the provider vouches for the address. An unverified
     *                               address can still be recorded, but is never granted.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $identifier,
        public string $email,
        public ?string $name = null,
        public array $raw = [],
        public bool $emailVerified = false,
    ) {}
}
