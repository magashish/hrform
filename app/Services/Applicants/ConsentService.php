<?php

namespace App\Services\Applicants;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\AuditEvent;
use App\Models\ConsentVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createVersion(string $version, string $title, string $body, User $actor, bool $activate): ConsentVersion
    {
        return DB::transaction(function () use ($version, $title, $body, $actor, $activate) {
            $consent = new ConsentVersion(compact('version', 'title', 'body'));
            $consent->created_by = $actor->id;
            $consent->save();

            $this->audit->record('consent.version_created', $consent, ['version' => $version], hrActor: $actor);

            if ($activate) {
                $this->activate($consent, $actor);
            }

            return $consent;
        });
    }

    public function activate(ConsentVersion $consent, User $actor): void
    {
        DB::transaction(function () use ($consent, $actor) {
            ConsentVersion::where('id', '!=', $consent->id)->where('active', true)->update(['active' => false]);
            $consent->forceFill(['active' => true, 'effective_at' => now()])->save();

            $this->audit->record('consent.version_activated', $consent, ['version' => $consent->version], hrActor: $actor);
        });
    }

    public function acknowledge(Applicant $applicant, ConsentVersion $version, Request $request): void
    {
        DB::transaction(function () use ($applicant, $version, $request) {
            $applicant->consents()->firstOrCreate(
                ['consent_version_id' => $version->id],
                [
                    'acknowledged_at' => now(),
                    'ip_address' => config('portal.audit.record_ip') ? $request->ip() : null,
                    'user_agent' => config('portal.audit.record_user_agent') ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                ],
            );

            $applicant->consented_at = now();
            if ($applicant->status === ApplicantStatus::Invited) {
                $applicant->status = ApplicantStatus::InProgress;
            }
            $applicant->save();

            $this->audit->record('applicant.consent_acknowledged', $version, ['version' => $version->version],
                applicantRef: $applicant->applicant_ref,
                actorType: AuditEvent::ACTOR_APPLICANT,
                actorId: $applicant->applicant_ref,
            );
        });
    }
}
