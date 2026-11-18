<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\DB;
use Mothership\User\Models\Admin;

it('scrubs unused legacy admin API tokens without changing password hashes', function () {
    $admin = Admin::factory()->create();
    $passwordHash = $admin->password;

    DB::table('admins')->where('id', $admin->id)->update(['api_token' => 'legacy']);

    $migration = require __DIR__.'/../../src/Database/Migrations/2026_10_18_210000_retire_legacy_admin_api_tokens.php';
    $migration->up();
    $migration->up();

    $stored = DB::table('admins')->where('id', $admin->id)->first(['password', 'api_token']);

    expect($stored->api_token)->toBeNull()
        ->and($stored->password)->toBe($passwordHash);
});

it('encrypts session payloads before storing them', function () {
    expect(config('session.encrypt'))->toBeTrue();

    $session = app('session')->driver('array');
    expect($session)->toBeInstanceOf(EncryptedStore::class);

    $session->start();
    $marker = 'session-storage-check';
    $session->put('marker', $marker);
    $session->save();

    $raw = $session->getHandler()->read($session->getId());
    expect($raw)->not->toContain($marker);

    $restored = new EncryptedStore('account-security-check', $session->getHandler(), app('encrypter'), $session->getId());
    $restored->start();

    expect($restored->get('marker'))->toBe($marker);
});

it('treats plaintext and differently keyed sessions as empty during encryption rollout', function () {
    $session = app('session')->driver('array');
    $handler = $session->getHandler();
    $encrypter = app('encrypter');

    foreach ([
        serialize(['marker' => 'legacy']),
        (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encrypt(serialize(['marker' => 'other-key'])),
    ] as $payload) {
        $id = bin2hex(random_bytes(20));
        $handler->write($id, $payload);

        $restored = new EncryptedStore('account-security-check', $handler, $encrypter, $id);
        $restored->start();

        expect($restored->has('marker'))->toBeFalse()
            ->and(array_keys($restored->all()))->toBe(['_token']);
    }
});
