<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent immediately (not queued) so HR sign-in does not depend on the queue
 * worker, and the code is never written to the jobs table.
 */
class HrLoginCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your sign-in code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.hr-login-code', text: 'mail.hr-login-code-text');
    }
}
