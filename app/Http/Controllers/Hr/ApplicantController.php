<?php

namespace App\Http\Controllers\Hr;

use App\Enums\ApplicantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ApplicantRequest;
use App\Models\Applicant;
use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use App\Models\ExportArchive;
use App\Models\User;
use App\Services\Applicants\ApplicantService;
use App\Services\Documents\ApplicantProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicantController extends Controller
{
    public function __construct(private readonly ApplicantService $applicants) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Applicant::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ApplicantStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $applicants = Applicant::query()
            ->with('activeInvitation')
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w
                    ->whereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('hr.applicants.index', [
            'applicants' => $applicants,
            'filters' => $filters,
            'statuses' => ApplicantStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Applicant::class);

        return view('hr.applicants.form', [
            'applicant' => null,
            'templates' => DocumentTemplate::where('active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(ApplicantRequest $request): RedirectResponse
    {
        $templateIds = array_map('intval', (array) $request->input('templates', []));
        $templates = DocumentTemplate::where('active', true)->whereIn('id', $templateIds)->get();

        $applicant = $this->applicants->create(
            $request->applicantData(),
            $request->user(),
            $request->boolean('send_invitation'),
            $templates,
        );

        return redirect()->route('hr.applicants.show', $applicant)->with('status',
            $request->boolean('send_invitation') ? 'Applicant created and invitation emailed.' : 'Applicant created. No invitation has been sent yet.');
    }

    public function show(Applicant $applicant): View
    {
        Gate::authorize('view', $applicant);

        $applicant->load(['invitations.creator', 'consents.version', 'creator', 'transferrer']);
        $documents = $applicant->documents()->with(['template', 'currentVersion', 'currentSupportingFiles'])->get()
            ->sortBy(fn ($d) => [$d->template->sort_order, $d->template->name])->values();
        $assignable = DocumentTemplate::where('active', true)
            ->whereNotIn('id', $documents->pluck('document_template_id'))->orderBy('sort_order')->get();
        $exports = ExportArchive::where('applicant_id', $applicant->id)
            ->where('requested_by', request()->user()->id)->latest()->limit(5)->get();
        $summary = app(ApplicantProgressService::class)->summary($applicant);

        $activity = AuditEvent::where('applicant_ref', $applicant->applicant_ref)
            ->latest('occurred_at')->latest('id')->limit(100)->get();
        $actorNames = User::whereIn('id', $activity->where('actor_type', 'hr')->pluck('actor_id')->unique())
            ->pluck('name', 'id');

        return view('hr.applicants.show', compact('applicant', 'documents', 'activity', 'actorNames', 'assignable', 'exports', 'summary'));
    }

    public function edit(Applicant $applicant): View
    {
        Gate::authorize('update', $applicant);

        return view('hr.applicants.form', ['applicant' => $applicant, 'templates' => collect()]);
    }

    public function update(ApplicantRequest $request, Applicant $applicant): RedirectResponse
    {
        $emailChanged = $request->validated('email') !== $applicant->email;
        $this->applicants->update($applicant, $request->applicantData(), $request->user());

        return redirect()->route('hr.applicants.show', $applicant)->with('status',
            $emailChanged ? 'Applicant updated. The email changed, so any active invitation was revoked. Issue a new one.' : 'Applicant updated.');
    }
}
