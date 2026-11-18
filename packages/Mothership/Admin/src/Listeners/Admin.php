<?php

namespace Mothership\Admin\Listeners;

use Illuminate\Support\Facades\Mail;

class Admin
{
    /**
     * Send mail on updating password.
     *
     * @param  \Mothership\User\Models\Admin  $admin
     * @return void
     */
    public function afterPasswordUpdated($admin) {}
}
