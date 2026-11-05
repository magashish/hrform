<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic HR notification. Content must never include document contents,
 * form answers, SSNs or access codes: only what happened and a link.
 */
class HrAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $subject,
        public string $line,
        public string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Onboarding] '.$this->subject)
            ->line($this->line)
            ->action('Open in the portal', $this->url)
            ->line('Sign-in and two-factor authentication are required to view details.');
    }
}
