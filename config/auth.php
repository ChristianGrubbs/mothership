<?php

use Mothership\User\Models\Admin;

return [
    'defaults' => [
        'guard'     => 'admin',
        'passwords' => 'admins',
    ],

    'guards' => [
        'admin' => [
            'driver'    => 'session',
            'provider'  => 'admins',
        ],

        'api' => [
            'driver'    => 'passport',
            'provider'  => 'admins',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model'  => Admin::class,
        ],

        'users' => [
            'driver' => 'eloquent',
            'model'  => Admin::class,
        ],
    ],

    'passwords' => [
        'admins' => [
            'provider' => 'admins',
            'table'    => 'admin_password_resets',
            'expire'   => 60,
            'throttle' => 60,
        ],

        // Invitations to set a first password: their own table, so a reset request cannot overwrite an invite.
        // 10080 minutes = 7 days.
        'admin_invites' => [
            'provider' => 'admins',
            'table'    => 'admin_invites',
            'expire'   => 10080,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | window expires and the user is prompted to re-enter their password via
    | the confirmation screen. By default, the timeout lasts for 3 hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
