<?php

namespace Awove\Vault\Notifications;

use Awove\Core\Installation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Synchronous: no usable challenge token is placed in a persistent job payload. */
class EmailChallenge extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $url)
    {
        $this->locale(Installation::manifest()['locale']);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Confirm your vault unlock'))
            ->line(__('Confirm this vault request in the same browser where you entered your vault password.'))
            ->action(__('Confirm vault email'), $this->url)
            ->line(__('This request expires in ten minutes. It does not sign you in to CRM or unlock the vault by itself.'));
    }
}
