<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ApplicantInvitation;
use App\Services\Applicants\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function store(Request $request, Applicant $applicant): RedirectResponse
    {
        Gate::authorize('invite', $applicant);

        $this->invitations->issue($applicant, $request->user());

        return back()->with('status', 'A new invitation has been emailed. Any previous code no longer works; the applicant\'s progress is kept.');
    }

    public function destroy(Request $request, Applicant $applicant, ApplicantInvitation $invitation): RedirectResponse
    {
        Gate::authorize('update', $applicant);
        abort_unless($invitation->applicant_id === $applicant->id, 404);

        $this->invitations->revoke($invitation, $request->user());

        return back()->with('status', 'Invitation revoked. The applicant can no longer sign in with it.');
    }
}
