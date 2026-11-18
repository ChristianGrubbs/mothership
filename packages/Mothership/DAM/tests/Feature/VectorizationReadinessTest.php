<?php

use Mothership\User\Models\Admin;

it('rejects missing shared credentials for a valid active human owner', function () {
    $owner = Admin::factory()->create(['status' => 1]);
    config([
        'vectorization.enabled'        => true,
        'vectorization.owner_admin_id' => $owner->id,
        'vectorization.api_id'         => null,
        'vectorization.api_secret'     => null,
    ]);

    $this->artisan('dam:vectorizations:check')->assertFailed();
});

it('rejects a missing human owner when shared credentials are valid', function () {
    config([
        'vectorization.enabled'        => true,
        'vectorization.owner_admin_id' => 0,
        'vectorization.api_id'         => 'x',
        'vectorization.api_secret'     => 'y',
    ]);

    $this->artisan('dam:vectorizations:check')->assertFailed();
});

it('rejects an inactive human owner when shared credentials are valid', function () {
    $owner = Admin::factory()->create(['status' => 0]);
    config([
        'vectorization.enabled'        => true,
        'vectorization.owner_admin_id' => $owner->id,
        'vectorization.api_id'         => 'x',
        'vectorization.api_secret'     => 'y',
    ]);

    $this->artisan('dam:vectorizations:check')->assertFailed();
});

it('accepts valid shared credentials with an enabled active human owner', function () {
    $owner = Admin::factory()->create(['status' => 1]);

    config([
        'vectorization.enabled'        => true,
        'vectorization.owner_admin_id' => $owner->id,
        'vectorization.api_id'         => 'x',
        'vectorization.api_secret'     => 'y',
    ]);

    $this->artisan('dam:vectorizations:check')
        ->expectsOutput('Vectorization configuration ready.')
        ->assertSuccessful();
});
