<?php

use Mothership\Admin\Mail\Admin\ResetPasswordNotification;
use Mothership\User\Models\Admin;

it('words the reset code mail for a person typing it in, with the app name in the subject', function () {
    $admin = Admin::query()->first();

    $message = (new ResetPasswordNotification('12345678'))->toMail($admin);

    expect($message->subject)->toBe('Your '.config('documentation.name').' password reset code')
        ->and((string) $message->render())->toContain('1234 5678')->toContain('expires in 60 minutes');
});

it('localises the greeting in the data transfer email template instead of hardcoding it', function () {
    $templateData = (object) [
        'meta'  => json_encode(['type' => 'import', 'code' => 'products']),
        'state' => 'completed',
    ];

    $html = view('admin::emails.data-transfer.index', ['templateData' => $templateData])->render();

    expect($html)
        ->toContain(trans('admin::app.emails.hello'))
        ->toContain("'Inter'");
});
