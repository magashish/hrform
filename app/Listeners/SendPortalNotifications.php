<?php

namespace App\Listeners;

use App\Events\CorrectionRequested;
use App\Events\DocumentSubmitted;
use App\Events\PacketCompleted;
use App\Events\SupportingFileUploaded;
use App\Mail\ApplicantNoticeMail;
use App\Notifications\HrAlert;
use App\Services\Notifications\HrNotifier;
use App\Services\Settings\SettingsService;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Mail;

/**
 * HR emails identify the applicant only through the authenticated link,
 * never by name or document content.
 */
class SendPortalNotifications
{
    public function __construct(
        private readonly HrNotifier $hr,
        private readonly SettingsService $settings,
    ) {}

    public function onDocumentSubmitted(DocumentSubmitted $event): void
    {
        $doc = $event->document;
        $this->hr->notify('document_submitted', new HrAlert(
            'Document submitted: '.$doc->template->name,
            'An applicant has submitted "'.$doc->template->name.'" and it is ready for review.',
            route('hr.documents.show', [$doc->applicant, $doc]),
        ));
    }

    public function onFileUploaded(SupportingFileUploaded $event): void
    {
        $doc = $event->document;
        $this->hr->notify('supporting_uploaded', new HrAlert(
            ($event->replacement ? 'File replaced: ' : 'File uploaded: ').$doc->template->name,
            'An applicant has '.($event->replacement ? 'replaced' : 'uploaded').' a file for "'.$doc->template->name.'".',
            route('hr.documents.show', [$doc->applicant, $doc]),
        ));
    }

    public function onPacketCompleted(PacketCompleted $event): void
    {
        $this->hr->notify('packet_completed', new HrAlert(
            'Onboarding packet completed',
            'An applicant has submitted all required onboarding documents.',
            route('hr.applicants.show', $event->applicant),
        ));
    }

    public function onCorrectionRequested(CorrectionRequested $event): void
    {
        $applicant = $event->document->applicant;

        Mail::to($applicant->email)->queue(new ApplicantNoticeMail(
            $applicant,
            (string) $this->settings->get('mail_correction_subject'),
            (string) $this->settings->get('mail_correction_intro'),
            ['Sign in to the portal to see what needs to be updated.'],
        ));

        $this->hr->log('correction_requested', 'applicant', $applicant->applicant_ref, 'queued');
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            DocumentSubmitted::class => 'onDocumentSubmitted',
            SupportingFileUploaded::class => 'onFileUploaded',
            PacketCompleted::class => 'onPacketCompleted',
            CorrectionRequested::class => 'onCorrectionRequested',
        ];
    }
}
