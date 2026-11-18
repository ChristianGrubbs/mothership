<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Password Policy
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the minimum length enforced when an admin
    | SETS a new password (user create/edit, account change, password reset).
    | Server rules and the client-side VeeValidate rules both read this value,
    | so the policy can never drift between the two again.
    |
    | Note: this is NOT applied to the login form or to `current_password`
    | verification — an existing admin with a shorter password must still be
    | able to authenticate. Raising this only affects newly chosen passwords.
    |
    */
    'password_min' => 8,

    /*
    |--------------------------------------------------------------------------
    | Admin Login Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum admin login attempts allowed per minute, keyed per email + IP.
    | Defaults to a strict 5 for production. Automated test environments that
    | log in repeatedly within a minute can raise it via ADMIN_LOGIN_RATE_LIMIT
    | so the throttle does not turn legitimate back-to-back logins into 429s.
    |
    */
    'login_rate_limit' => (int) env('ADMIN_LOGIN_RATE_LIMIT', 5),

    /*
    |--------------------------------------------------------------------------
    | Provider Sign-In Access Requests
    |--------------------------------------------------------------------------
    |
    | A provider account (Google) that signs in with no linked admin waits as
    | an access request until an administrator decides on Settings > Users or
    | runs mothership:access:grant or mothership:access:link, unless its
    | verified email is preapproved. Sign-in never links an account by email.
    | Requests expire after ttl_hours; at most max_pending stay open, oldest
    | dropped first. Every decision is appended as JSONL to
    | storage/logs/access-grants.jsonl unless MOTHERSHIP_AUTH_LOG=false.
    |
    */
    'access_requests' => [
        'ttl_hours'   => 24,
        'max_pending' => 20,
    ],

    'access_log' => (bool) env('MOTHERSHIP_AUTH_LOG', true),

    /*
    |--------------------------------------------------------------------------
    | Password Sign-In
    |--------------------------------------------------------------------------
    |
    | MOTHERSHIP_PASSWORD_LOGIN=false hides the email-and-password form and
    | answers 404 on the password routes (sign-in POST, forgot and reset
    | password, invite acceptance), leaving provider sign-in (Google) as the
    | only way in. It stays on while no provider is enabled, so the sign-in
    | page is never empty.
    |
    */
    'password_login' => (bool) env('MOTHERSHIP_PASSWORD_LOGIN', true),
];
