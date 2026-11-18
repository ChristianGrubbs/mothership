<?php

namespace Mothership\Admin\Mail\Admin;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Mothership\Admin\Auth\CodeTokenRepository;
use Mothership\Core\MailLinks;

/**
 * "Enter this code" password reset mail: plain text only, no link, no image.
 */
class ResetPasswordNotification extends ResetPassword
{
    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        $sender = core()->getSenderEmailDetails();
        $app = (string) config('documentation.name');

        return (new MailMessage)
            ->from($sender['email'], $sender['name'])
            ->subject(trans('admin::app.emails.admin.forgot-password.subject', ['app' => $app]))
            ->view(['text' => 'admin::emails.admin.forget-password-text'], [
                'app'     => $app,
                'name'    => $notifiable->name,
                'host'    => MailLinks::host(),
                'code'    => CodeTokenRepository::display($this->token),
                'minutes' => (int) config('auth.passwords.admins.expire'),
            ]);
    }
}
