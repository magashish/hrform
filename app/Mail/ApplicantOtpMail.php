<?php

namespace App\Mail;

use App\Models\Applicant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicantOtpMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Applicant $applicant, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your onboarding verification code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.applicant-otp',
            text: 'mail.applicant-otp-text',
            with: [
                'code' => $this->code,
                'minutes' => (int) config('portal.applicant_access.otp_ttl_minutes', 10),
            ],
        );
    }
}
