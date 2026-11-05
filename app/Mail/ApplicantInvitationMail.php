<?php

namespace App\Mail;

use App\Models\Applicant;
use App\Models\ApplicantInvitation;
use App\Models\AuditEvent;
use App\Notifications\HrAlert;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\HrNotifier;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Carries the raw invitation code, so the queued payload is encrypted
 * (ShouldBeEncrypted) and the code is never logged by the application.
 */
class ApplicantInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Applicant $applicant,
        public ApplicantInvitation $invitation,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) app(SettingsService::class)->get('mail_invitation_subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.applicant-invitation',
            text: 'mail.applicant-invitation-text',
            with: [
                'firstName' => $this->applicant->first_name,
                'intro' => (string) app(SettingsService::class)->get('mail_invitation_intro'),
                'link' => route('portal.access').'#'.$this->code,
                'code' => $this->code,
                'expiresAt' => $this->invitation->expires_at->timezone(config('app.display_timezone')),
            ],
        );
    }

    /**
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $sent = parent::send($mailer);

        $this->invitation->forceFill([
            'delivery_status' => ApplicantInvitation::DELIVERY_SENT,
            'sent_at' => now(),
        ])->save();

        app(AuditLogger::class)->record('invitation.delivered', $this->invitation,
            applicantRef: $this->applicant->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);

        return $sent;
    }

    public function failed(Throwable $e): void
    {
        $this->invitation->forceFill(['delivery_status' => ApplicantInvitation::DELIVERY_FAILED])->save();

        app(AuditLogger::class)->record('invitation.delivery_failed', $this->invitation,
            ['error' => class_basename($e)],
            applicantRef: $this->applicant->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);

        app(HrNotifier::class)->notify('delivery_failed', new HrAlert(
            'Invitation email could not be delivered',
            'An applicant invitation email failed to send. Check the email address and issue a new invitation.',
            route('hr.applicants.show', $this->applicant),
        ));
    }
}
