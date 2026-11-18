<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mothership\Core\Models\CoreConfig;

function mailSetting(string $field, string $value): void
{
    CoreConfig::query()->updateOrCreate(
        ['code' => 'emails.configure.email_settings.'.$field, 'channel_code' => null, 'locale_code' => null],
        ['value' => $value],
    );
}

function smtpHost(): string
{
    return (string) Mail::mailer('smtp')->getSymfonyTransport();
}

function atJobBoundary(string $connection = 'database'): void
{
    event(new JobProcessing($connection, Mockery::mock(Job::class, ['payload' => []])));
}

it('makes a queue worker pick up changed and cleared Email settings at the job boundary', function () {
    $envDefault = config('mail.default');
    $envHost = config('mail.mailers.smtp.host');

    mailSetting('mail_host', 'first-smtp.example.test');
    mailSetting('mail_port', '2525');
    expect(smtpHost())->toContain('first-smtp.example.test')
        ->and(config('mail.default'))->toBe('smtp');

    // The settings change while the worker keeps running: without the boundary the cached mailer is stale.
    mailSetting('mail_host', 'second-smtp.example.test');
    expect(smtpHost())->toContain('first-smtp.example.test');

    atJobBoundary();
    expect(smtpHost())->toContain('second-smtp.example.test');

    // Host emptied: back to the environment's transport, not the last override.
    mailSetting('mail_host', '');
    atJobBoundary();
    smtpHost();

    expect(config('mail.default'))->toBe($envDefault)
        ->and(config('mail.mailers.smtp.host'))->toBe($envHost);
});

class QueueRefreshProbeNotification extends BaseNotification
{
    public function via(): array
    {
        return ['mail'];
    }
}

function sendThroughFakes(): void
{
    Mail::to('someone@example.test')->send((new Mailable)->subject('s')->html('x'));
    Notification::route('mail', 'someone@example.test')->notify(new QueueRefreshProbeNotification);
}

it('keeps Mail and Notification fakes through a sync job', function () {
    Mail::fake();
    Notification::fake();

    dispatch(function (): void {});

    sendThroughFakes();

    Mail::assertSentCount(1);
    Notification::assertSentOnDemand(QueueRefreshProbeNotification::class);
});

it('keeps Mail and Notification fakes through a job on a real queue connection', function () {
    Mail::fake();
    Notification::fake();

    atJobBoundary('database');

    sendThroughFakes();

    Mail::assertSentCount(1);
    Notification::assertSentOnDemand(QueueRefreshProbeNotification::class);
});

it('does not drop the mailer for a sync job', function () {
    mailSetting('mail_host', 'sync-first.example.test');
    expect(smtpHost())->toContain('sync-first.example.test');

    mailSetting('mail_host', 'sync-second.example.test');
    atJobBoundary('sync');

    expect(smtpHost())->toContain('sync-first.example.test');
});
