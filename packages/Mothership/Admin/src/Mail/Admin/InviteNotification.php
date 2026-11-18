<?php

namespace Mothership\Admin\Mail\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Mothership\Admin\Auth\CodeTokenRepository;
use Mothership\Admin\Services\UserInvites;
use Mothership\Core\MailLinks;

/**
 * "Enter this code" mail for a person an admin invited: plain text only, no link, no image. Queued so a 50-address invite does not hold the request.
 */
class InviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $sender = core()->getSenderEmailDetails();
        $app = (string) config('documentation.name');

        return (new MailMessage)
            ->from($sender['email'], $sender['name'])
            ->subject(trans('admin::app.invites.mail.subject', ['app' => $app]))
            ->view(['text' => 'admin::emails.admin.invite-text'], [
                'app'  => $app,
                'host' => MailLinks::host(),
                'code' => CodeTokenRepository::display($this->token),
                'days' => UserInvites::VALID_DAYS,
            ]);
    }
}
