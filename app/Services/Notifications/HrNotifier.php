<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Sends HR notifications to the configured recipients, if the event type is
 * enabled in settings. Records a delivery log without message content.
 */
class HrNotifier
{
    public function __construct(private readonly SettingsService $settings) {}

    public function notify(string $event, Notification $notification): void
    {
        $enabled = (array) $this->settings->get('hr_notification_events');
        if (! in_array($event, $enabled, true)) {
            return;
        }

        $recipients = trim((string) $this->settings->get('hr_notification_recipients'));

        try {
            if ($recipients === '') {
                $users = User::whereNull('disabled_at')->get();
                NotificationFacade::send($users, $notification);
                $this->log($event, 'hr_users', (string) $users->count(), 'queued');
            } else {
                $emails = array_filter(array_map('trim', explode(',', $recipients)));
                NotificationFacade::route('mail', $emails)->notify($notification);
                $this->log($event, 'hr_mailbox', (string) count($emails), 'queued');
            }
        } catch (Throwable $e) {
            $this->log($event, 'hr', null, 'failed', class_basename($e));
            report($e);
        }
    }

    public function log(string $event, string $recipientType, ?string $recipientRef, string $status, ?string $error = null): void
    {
        DB::table('notification_deliveries')->insert([
            'event' => $event,
            'recipient_type' => $recipientType,
            'recipient_ref' => $recipientRef,
            'status' => $status,
            'error_class' => $error,
            'sent_at' => $status === 'queued' ? now() : null,
            'created_at' => now(),
        ]);
    }
}
