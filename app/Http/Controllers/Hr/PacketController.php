<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Services\Retention\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PacketController extends Controller
{
    public function __construct(private readonly RetentionService $retention) {}

    public function transfer(Request $request, Applicant $applicant): RedirectResponse
    {
        Gate::authorize('transfer', $applicant);
        $request->validate(['confirm_transfer' => ['accepted']], [
            'confirm_transfer.accepted' => 'Confirm that you have verified the downloaded files and uploaded them to the HR system.',
        ]);

        if ($applicant->transferred_at) {
            return back();
        }

        $this->retention->markTransferred($applicant, $request->user());

        return back()->with('status', 'Packet marked as transferred. It is scheduled for deletion on '
            .$applicant->fresh()->deletion_due_at?->timezone(config('app.display_timezone'))->format('F j, Y').'.');
    }

    public function legalHold(Request $request, Applicant $applicant): RedirectResponse
    {
        Gate::authorize('manageLegalHold', $applicant);
        $data = $request->validate([
            'hold' => ['required', 'boolean'],
            'reason' => ['required_if:hold,1', 'nullable', 'string', 'max:500'],
        ]);

        $this->retention->setLegalHold($applicant, (bool) $data['hold'], $data['reason'] ?? null, $request->user());

        return back()->with('status', $data['hold'] ? 'Legal hold set. This packet will not be deleted.' : 'Legal hold removed.');
    }

    public function destroy(Request $request, Applicant $applicant): RedirectResponse
    {
        Gate::authorize('delete', $applicant);
        $request->validate(['confirmation' => ['required', 'in:DELETE']], [
            'confirmation.in' => 'Type DELETE in capitals to confirm.',
        ]);

        try {
            $this->retention->purge($applicant, 'manual', $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirmation' => 'The packet could not be deleted: '.$e->getMessage()]);
        }

        return redirect()->route('hr.applicants.index')->with('status', 'Applicant packet and personal data permanently deleted. Copies may remain in backups until they expire.');
    }
}
