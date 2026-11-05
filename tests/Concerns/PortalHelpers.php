<?php

namespace Tests\Concerns;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\ApplicantInvitation;
use App\Models\ConsentVersion;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Applicants\ApplicantAccessService as Access;
use App\Services\Applicants\InvitationCode;
use App\Services\Documents\AssignmentService;
use Illuminate\Http\UploadedFile;

trait PortalHelpers
{
    protected ?ConsentVersion $consent = null;

    protected function activeConsent(): ConsentVersion
    {
        return $this->consent ??= ConsentVersion::factory()->active()->create();
    }

    /**
     * @return array{0: Applicant, 1: string} applicant and raw invitation code
     */
    protected function applicantWithInvitation(array $attributes = [], ?User $hr = null): array
    {
        $hr ??= $this->hrUser();
        $applicant = Applicant::factory()->create($attributes + ['created_by' => $hr->id]);
        $codes = app(InvitationCode::class);
        $code = $codes->generate();

        $applicant->invitations()->create([
            'token_hash' => $codes->hash($code),
            'issued_at' => now(),
            'expires_at' => now()->addDays(7),
            'created_by' => $hr->id,
        ]);

        return [$applicant, $code];
    }

    /**
     * Puts the test client into a signed-in applicant session.
     */
    protected function signInApplicant(Applicant $applicant, bool $consented = true): ApplicantInvitation
    {
        $invitation = $applicant->invitations()->whereNull('revoked_at')->latest('id')->first()
            ?? $applicant->invitations()->create([
                'token_hash' => app(InvitationCode::class)->hash(app(InvitationCode::class)->generate()),
                'issued_at' => now(),
                'expires_at' => now()->addDays(7),
                'created_by' => $applicant->created_by,
            ]);

        if ($consented) {
            $version = $this->activeConsent();
            if (! $applicant->hasAcknowledged($version)) {
                $applicant->consents()->create(['consent_version_id' => $version->id, 'acknowledged_at' => now()]);
                $applicant->forceFill(['consented_at' => now(), 'status' => 'in_progress'])->save();
            }
        }

        $this->withSession([
            Access::SESSION_APPLICANT => $applicant->id,
            Access::SESSION_INVITATION => $invitation->id,
            Access::SESSION_STARTED => now()->getTimestamp(),
            Access::SESSION_LAST_ACTIVITY => now()->getTimestamp(),
        ]);

        return $invitation;
    }

    protected function assign(Applicant $applicant, DocumentTemplate $template, ?bool $required = null): ApplicantDocument
    {
        return app(AssignmentService::class)->assign($applicant, $template, User::findOrFail($applicant->created_by), $required);
    }

    protected function pdf(string $name = 'signed.pdf', string $extra = ''): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n{$extra}\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    protected function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }
}
