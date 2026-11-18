<?php

use Illuminate\Support\Facades\Artisan;
use Mothership\Installer\Helpers\DatabaseManager;

$bootAppKey = [
    'process' => getenv('APP_KEY'),
    'env'     => [
        'present' => array_key_exists('APP_KEY', $_ENV),
        'value'   => $_ENV['APP_KEY'] ?? null,
    ],
    'server'  => [
        'present' => array_key_exists('APP_KEY', $_SERVER),
        'value'   => $_SERVER['APP_KEY'] ?? null,
    ],
];

afterEach(function () use ($bootAppKey) {
    $actual = [
        'process' => getenv('APP_KEY'),
        'env'     => [
            'present' => array_key_exists('APP_KEY', $_ENV),
            'value'   => $_ENV['APP_KEY'] ?? null,
        ],
        'server'  => [
            'present' => array_key_exists('APP_KEY', $_SERVER),
            'value'   => $_SERVER['APP_KEY'] ?? null,
        ],
    ];

    $bootAppKey['process'] === false
        ? putenv('APP_KEY')
        : putenv('APP_KEY='.$bootAppKey['process']);

    if ($bootAppKey['env']['present']) {
        $_ENV['APP_KEY'] = $bootAppKey['env']['value'];
    } else {
        unset($_ENV['APP_KEY']);
    }

    if ($bootAppKey['server']['present']) {
        $_SERVER['APP_KEY'] = $bootAppKey['server']['value'];
    } else {
        unset($_SERVER['APP_KEY']);
    }

    expect($actual)->toBe($bootAppKey);
});

describe('DatabaseManager::generateKey idempotence', function () {
    it('skips key:generate when APP_KEY is already set so the UI installer does not rotate the session-cookie cipher on retry', function () {
        config(['app.key' => 'base64:ZHVtbXkta2V5LXRoYXQtaXMtMzItYnl0ZXMtbG9uZw==']);

        Artisan::shouldReceive('call')->with('key:generate')->never();

        app(DatabaseManager::class)->generateKey();
    });

    it('runs key:generate exactly once when APP_KEY is empty', function () {
        config(['app.key' => null]);

        Artisan::shouldReceive('call')->with('key:generate')->once();

        app(DatabaseManager::class)->generateKey();
    });
});
