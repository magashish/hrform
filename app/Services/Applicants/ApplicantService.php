<?php

namespace App\Services\Applicants;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\AssignmentService;
use Illuminate\Support\Facades\DB;

class ApplicantService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvitationService $invitations,
        private readonly AssignmentService $assignments,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string}  $data
     * @param  iterable<DocumentTemplate>  $templates  documents to assign
     */
    public function create(array $data, User $createdBy, bool $sendInvitation = true, iterable $templates = []): Applicant
    {
        $applicant = DB::transaction(function () use ($data, $createdBy, $templates) {
            $applicant = new Applicant($data);
            $applicant->created_by = $createdBy->id;
            $applicant->status = ApplicantStatus::Invited;
            $applicant->save();

            $this->audit->record('applicant.created', $applicant, hrActor: $createdBy, applicantRef: $applicant->applicant_ref);
            $this->assignments->assignMany($applicant, $templates, $createdBy);

            return $applicant;
        });

        if ($sendInvitation) {
            $this->invitations->issue($applicant, $createdBy);
        }

        return $applicant;
    }

    /**
     * Updates identity fields. Changing the email revokes any active
     * invitation, because it was sent to the old address.
     *
     * @param  array{first_name: string, last_name: string, email: string}  $data
     */
    public function update(Applicant $applicant, array $data, User $actor): void
    {
        DB::transaction(function () use ($applicant, $data, $actor) {
            $applicant->fill($data);
            $changed = array_keys($applicant->getDirty());

            if ($changed === []) {
                return;
            }

            $emailChanged = $applicant->isDirty('email');
            $applicant->save();

            $this->audit->record('applicant.updated', $applicant, [
                'fields' => implode(',', $changed),
            ], hrActor: $actor, applicantRef: $applicant->applicant_ref);

            if ($emailChanged) {
                $this->invitations->revokeActive($applicant, $actor, 'email_changed');
            }
        });
    }
}
