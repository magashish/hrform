<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Applicants\CurrentApplicant;
use App\Services\Documents\ApplicantProgressService;
use App\Services\Documents\DocumentReadiness;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(CurrentApplicant $current, ApplicantProgressService $progress, DocumentReadiness $readiness): View
    {
        $applicant = $current->get();
        $documents = $applicant->documents()->with(['template', 'openCorrections'])->get()
            ->sortBy(fn ($d) => [$d->template->sort_order, $d->template->name])->values();

        return view('portal.home', [
            'applicant' => $applicant,
            'invitation' => $current->invitation(),
            'documents' => $documents,
            'summary' => $progress->summary($applicant),
        ]);
    }
}
