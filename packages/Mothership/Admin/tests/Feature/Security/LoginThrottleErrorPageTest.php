<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

describe('Login throttle error page', function () {
    beforeEach(function () {
        config(['app.url' => 'http://localhost']);

        URL::forceRootUrl(config('app.url'));

        // The login throttle is backed by the cache; flush it so rate-limit state
        // from an earlier test in the same process cannot trip the limiter early.
        Cache::flush();
    });

    /**
     * SETUP-09: a refused attempt used to be a bare "Too Many Requests" page (or, before that, the 500 page).
     * It now stays on the sign-in page, keeps the email, and says how long to wait, debug on or off.
     */
    it('keeps a throttled sign-in on the login page with a wait message', function (bool $debug) {
        config(['app.debug' => $debug]);

        $credentials = [
            'email'    => 'throttle-page@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.session.store'), $credentials)->assertRedirect();
        }

        $response = $this->post(route('admin.session.store'), $credentials)
            ->assertStatus(429)
            ->assertViewIs('admin::users.sessions.create')
            ->assertSee('throttle-page@example.com')
            ->assertDontSee(trans('admin::app.errors.500.title'));

        $seconds = (int) $response->headers->get('Retry-After');

        expect($seconds)->toBeGreaterThan(0);
        $response->assertSee(trans('admin::app.users.sessions.too-many-attempts-wait', ['seconds' => $seconds]));
    })->with(['debug off' => false, 'debug on' => true]);

    it('returns a json 429 with the wait message for ajax login attempts past the limit', function () {
        config(['app.debug' => false]);

        $credentials = [
            'email'    => 'throttle-json@example.com',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.session.store'), $credentials)->assertRedirect();
        }

        $response = $this->postJson(route('admin.session.store'), $credentials)
            ->assertStatus(429)
            ->assertJson([
                'error'       => trans('admin::app.errors.429.title'),
                'description' => trans('admin::app.errors.429.description'),
            ]);

        expect($response->json('message'))->toBe(trans('admin::app.users.sessions.too-many-attempts-wait', ['seconds' => (int) $response->headers->get('Retry-After')]));
    });

    it('defines the 429 error translations for every locale', function () {
        $files = glob(base_path('packages/Mothership/Admin/src/Resources/lang/*/app.php'));

        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            $lines = include $file;

            $locale = basename(dirname($file));

            $this->assertTrue(
                isset($lines['errors']['429']['title'], $lines['errors']['429']['description']),
                "Missing errors.429 translations for locale [{$locale}]."
            );
        }
    });
});
