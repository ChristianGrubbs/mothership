<?php

use Illuminate\Routing\Middleware\ThrottleRequests;

it('shows the code form on reset-password and sends an old token address there', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->get('/admin/reset-password')->assertOk();

    $this->get('/admin/reset-password/old-token-value')->assertRedirect(route('admin.reset_password.create'));
});
