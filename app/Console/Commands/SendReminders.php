<?php

namespace App\Console\Commands;

use App\Enums\ApplicantStatus;
use App\Mail\ApplicantNoticeMail;
use App\Models\ApplicantInvitation;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends configurable applicant reminders. Idempotent: each reminder key is
 * recorded once per invitation (unique index), so re-runs never duplicate.
 */
class SendReminders extends Command
{
    protected $signature = 'portal:reminders:send';

    protected $description = 'Send invitation-expiry and incomplete-onboarding reminders to applicants';

    public function handle(SettingsService $settings, AuditLogger $audit): int
    {
        $daysBefore = array_filter(array_map('intval', explode(',', (string) $settings->get('reminder_days_before_expiry'))));
        $everyDays = (int) $settings->get('reminder_incomplete_every_days');
        $sent = 0;

        $invitations = ApplicantInvitation::with('applicant')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();

        foreach ($invitations as $invitation) {
            $applicant = $invitation->applicant;
            if (! in_array($applicant->status, [ApplicantStatus::Invited, ApplicantStatus::InProgress, ApplicantStatus::CorrectionRequired], true)) {
                continue;
            }

            $key = null;
            $kind = null;

            // Use the smallest threshold reached; each threshold fires once.
            $matching = array_filter($daysBefore, fn (int $d) => $invitation->expires_at->lte(now()->addDays($d)));
            if ($matching !== []) {
                $key = 'expiry_'.min($matching);
                $kind = 'expiry';
            }

            if ($key === null && $everyDays > 0) {
                $periods = (int) floor($invitation->issued_at->diffInDays(now()) / $everyDays);
                if ($periods >= 1) {
                    $key = 'incomplete_'.$periods;
                    $kind = 'incomplete';
                }
            }

            if ($key === null) {
                continue;
            }

            // ON CONFLICT DO NOTHING: safe inside transactions on every driver.
            $inserted = DB::table('reminder_dispatches')->insertOrIgnore([
                'applicant_id' => $applicant->id,
                'invitation_id' => $invitation->id,
                'reminder_key' => $key,
                'sent_at' => now(),
            ]);
            if ($inserted === 0) {
                continue; // already sent
            }

            $extra = $kind === 'expiry'
                ? ['Your invitation expires on '.$invitation->expires_at->timezone(config('app.display_timezone'))->format('F j, Y g:i A T').'.']
                : [];

            Mail::to($applicant->email)->queue(new ApplicantNoticeMail(
                $applicant,
                (string) $settings->get('mail_reminder_subject'),
                (string) $settings->get('mail_reminder_intro'),
                $extra,
            ));

            $audit->record('reminder.sent', $invitation, ['kind' => $kind],
                applicantRef: $applicant->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);
            $sent++;
        }

        $this->info("Reminders queued: {$sent}");

        return self::SUCCESS;
    }
}
