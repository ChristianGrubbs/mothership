<?php

namespace Mothership\Admin\Exceptions;

/**
 * The provider account is valid but linked to no admin: it now waits as an access request.
 */
class SsoAccessPendingException extends SsoAuthenticationException
{
    public function __construct(public readonly int $requestId, ?string $email = null)
    {
        parent::__construct(trans('admin::app.users.sessions.access-pending.title'), 'info', $email);
    }
}
