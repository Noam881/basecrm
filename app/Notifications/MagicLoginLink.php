<?php

namespace App\Notifications;

use App\Auth\MagicLogin;
use App\Support\Localization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLoginLink extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(Localization::text('Your :app sign-in link', ['app' => config('app.name')]))
            ->greeting(Localization::text('Sign in to :app', ['app' => config('app.name')]))
            ->line(Localization::text('Use this one-time link to sign in. No password or password reset is needed.'))
            ->action(Localization::text('Sign in to CRM'), $this->url)
            ->line(Localization::text('This link expires in :minutes minutes. Click it to open your CRM.', ['minutes' => MagicLogin::LIFETIME_MINUTES]))
            ->line(Localization::text('If you did not request this link, you can ignore this email.'))
            ->salutation(Localization::text('Regards, :app', ['app' => config('app.name')]));
    }
}
