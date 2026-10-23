<?php

namespace App\Notifications;

use App\Auth\MagicLogin;
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
            ->subject('Your '.config('app.name').' sign-in link')
            ->greeting('Sign in to '.config('app.name'))
            ->line('Use this one-time link to sign in. No password or password reset is needed.')
            ->action('Sign in to CRM', $this->url)
            ->line('This link expires in '.MagicLogin::LIFETIME_MINUTES.' minutes. Confirm sign-in on the page that opens.')
            ->line('If you did not request this link, you can ignore this email.');
    }
}
