<?php

namespace Mothership\AdminApi\Services;

enum McpAuthorizationFailure: string
{
    case PendingMissing = 'pending_missing';
    case UserUnavailable = 'user_unavailable';
    case PrincipalChanged = 'principal_changed';
    case ResourceChanged = 'resource_changed';
    case ClientUnavailable = 'client_unavailable';
    case RedirectChanged = 'redirect_changed';
    case CsrfExpired = 'csrf_expired';
}
