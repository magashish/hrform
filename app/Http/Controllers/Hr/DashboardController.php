<?php

namespace App\Http\Controllers\Hr;

use App\Enums\ApplicantStatus;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $counts = Applicant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('hr.dashboard', [
            'statuses' => ApplicantStatus::cases(),
            'counts' => $counts,
            'awaitingReview' => ApplicantDocument::whereIn('status', [DocumentStatus::Submitted->value, DocumentStatus::UnderReview->value])->count(),
            'dueForDeletion' => Applicant::whereNotNull('deletion_due_at')->where('deletion_due_at', '<=', now()->addDays(7))->count(),
            'recent' => Applicant::with('activeInvitation')->latest('updated_at')->limit(10)->get(),
        ]);
    }
}
