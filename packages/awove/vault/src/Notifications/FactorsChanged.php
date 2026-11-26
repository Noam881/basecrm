<?php

namespace Awove\Vault\Notifications;

use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\AuthMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FactorsChanged extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public readonly string $installationId;

    public function __construct()
    {
        $this->installationId = Installation::manifest()['id'];
        $this->locale(Installation::manifest()['locale']);
        $this->onConnection('database');
        $this->beforeCommit();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(Modules::class)->enabled('vault') && Installation::manifest()['id'] === $this->installationId;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Your vault authentication factors changed'))
            ->line(AuthMode::riddle() ? __('An authorized operator changed the shared Vault riddle. Existing Vault unlocks and challenges were cancelled.') : __('An authorized administrative process replaced your vault enrollment. Existing vault unlocks and challenges were cancelled.'))
            ->line(__('If you did not expect this change, contact your administrator immediately. No secrets are included in this message.'));
    }
}
