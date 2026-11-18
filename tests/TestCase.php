<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, DatabaseTransactions;

    /**
     * A test's requests share one session, so acting as another user keeps the password hash the admin routes'
     * AuthenticateSession stored for the previous one, and that session would be signed out as if its password had
     * changed. Acting as someone starts from their own hash, as a real sign-in does; a test that needs a particular
     * session state sets it with withSession() afterwards.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($this->app->bound('session')) {
            foreach (array_keys(config('auth.guards', [])) as $guardName) {
                $this->app['session']->forget('password_hash_'.$guardName);
            }
        }

        return parent::be($user, $guard);
    }
}
