<?php

namespace App\Mail;

use App\Models\Applicant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reminder / correction-request email to an applicant. HR-editable subject
 * and opening paragraph (Settings). Never includes document contents, the
 * correction message itself, or an access code.
 */
class ApplicantNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $extraLines
     */
    public function __construct(
        public Applicant $applicant,
        public string $subjectLine,
        public string $intro,
        public array $extraLines = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.applicant-notice',
            text: 'mail.applicant-notice-text',
            with: [
                'firstName' => $this->applicant->first_name,
                'intro' => $this->intro,
                'extraLines' => $this->extraLines,
                'portalUrl' => route('portal.access'),
            ],
        );
    }
}
