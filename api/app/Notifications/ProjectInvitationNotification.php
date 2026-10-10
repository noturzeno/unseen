<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectInvitationNotification extends Notification
{
    public function __construct(
        private readonly Project $project,
        private readonly User $inviter,
        private readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Join {$this->project->name} on ".config('app.name'))
            ->line("{$this->inviter->name} invited you to contribute to {$this->project->name}.")
            ->line('Create your account to see the project and connect your coding agent, so your work is tracked here too.')
            ->action('Accept invitation', $this->url)
            ->line('This link expires in '.ProjectInvitation::EXPIRES_AFTER_DAYS.' days.');
    }
}
