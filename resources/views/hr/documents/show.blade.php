@extends('layouts.app')

@section('title', $document->template->name)

@php($tz = config('app.display_timezone'))

@section('content')
    <p><a href="{{ route('hr.applicants.show', $applicant) }}">&larr; {{ $applicant->fullName() }}</a></p>
    <div class="page-header">
        <h1>{{ $document->template->name }}</h1>
        @include('partials.status-badge', ['status' => $document->status])
    </div>
    <p class="muted">{{ $document->required ? 'Required' : 'Optional' }} · {{ $document->input_mode_snapshot->label() }} · {{ $document->signature_mode_snapshot->label() }}
        @if ($document->submitted_at) · Submitted {{ $document->submitted_at->timezone($tz)->format('M j, Y g:i A') }} @endif</p>

    @if ($document->hasForm())
        <section class="card">
            <h2>Form answers</h2>
            @if (empty($values))
                <p class="muted">No answers saved yet.</p>
            @else
                <dl class="answers">
                    @foreach ($document->template->fields as $field)
                        <dt>{{ $field->label }}</dt>
                        <dd>
                            {{ $types->display($field, $values[$field->field_key] ?? null, in_array($field->field_key, $revealed, true)) }}
                            @if (($field->type === 'ssn' || $field->sensitive) && ! empty($values[$field->field_key]) && ! in_array($field->field_key, $revealed, true))
                                <form method="POST" action="{{ route('hr.documents.reveal', [$applicant, $document]) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="field" value="{{ $field->field_key }}">
                                    <button type="submit" class="link-button">Reveal</button>
                                </form>
                            @endif
                        </dd>
                    @endforeach
                </dl>
                <p class="hint">Sensitive values are masked. Each reveal requires your password and is recorded in the audit log.</p>
            @endif
        </section>
    @endif

    @if ($document->isAcknowledgement())
        <section class="card">
            <h2>Acknowledgement</h2>
            <p>{{ $document->acknowledged_at ? 'Acknowledged on '.$document->acknowledged_at->timezone($tz)->format('M j, Y g:i A T') : 'Not yet acknowledged.' }}</p>
        </section>
    @endif

    <section class="card">
        <h2>Files</h2>
        @php($files = $document->versions->map(fn ($v) => ['kind' => 'version', 'f' => $v, 'label' => 'Signed/uploaded document v'.$v->version_number])
            ->concat($document->supportingFiles->map(fn ($s) => ['kind' => 'supporting', 'f' => $s, 'label' => (collect($document->template->supportingRequirements())->firstWhere('key', $s->requirement_key)['label'] ?? $s->requirement_key).' v'.$s->version_number])))
        @if ($files->isEmpty())
            <p class="muted">No files uploaded.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">File</th><th scope="col">Name</th><th scope="col">Uploaded</th><th scope="col">Scan</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    @foreach ($files as $row)
                        @php($f = $row['f'])
                        <tr @class(['superseded' => $f->superseded_at])>
                            <td>{{ $row['label'] }} @if ($f->superseded_at)<span class="badge badge-muted">replaced</span>@endif</td>
                            <td><code class="small">{{ $f->generated_filename }}</code><br><span class="muted small">{{ number_format($f->size / 1024) }} KB · SHA-256 {{ substr($f->sha256, 0, 12) }}…</span></td>
                            <td>{{ $f->uploaded_at->timezone($tz)->format('M j, g:i A') }}</td>
                            <td>{{ str_replace('_', ' ', $f->scan_status->value) }}</td>
                            <td class="right nowrap">
                                <a href="{{ route('hr.files.show', [$applicant, $row['kind'], $f->id, 'inline' => 1]) }}" target="_blank" rel="noopener">Preview</a> ·
                                <a href="{{ route('hr.files.show', [$applicant, $row['kind'], $f->id]) }}">Download</a>
                                <form method="POST" action="{{ route('hr.files.destroy', [$applicant, $row['kind'], $f->id]) }}" class="inline">
                                    @csrf @method('DELETE')
                                    · <button type="submit" class="link-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid-2">
        <section class="card">
            <h2>Approve</h2>
            @if (in_array($document->status->value, ['submitted', 'under_review'], true))
                <form method="POST" action="{{ route('hr.documents.approve', [$applicant, $document]) }}">
                    @csrf
                    <button type="submit" class="button">Approve document</button>
                </form>
            @else
                <p class="muted">Available once the applicant has submitted this document.</p>
            @endif
        </section>
        <section class="card">
            <h2>Request a correction</h2>
            @if (in_array($document->status->value, ['submitted', 'under_review', 'approved'], true))
                <form method="POST" action="{{ route('hr.documents.corrections', [$applicant, $document]) }}" novalidate>
                    @csrf
                    <div class="field">
                        <label for="scope">What needs correcting</label>
                        <select id="scope" name="scope">
                            <option value="document">The document in general</option>
                            @if ($document->hasForm())<option value="form">Form answers</option>@endif
                            @if ($document->needsMainUpload())<option value="signed_upload">Signed/uploaded document</option>@endif
                            @foreach ($document->template->supportingRequirements() as $req)
                                <option value="supporting:{{ $req['key'] }}">{{ $req['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="message">Message to the applicant</label>
                        <textarea id="message" name="message" rows="4" maxlength="2000" required></textarea>
                        <p class="hint">Shown to the applicant when they sign in. The email only says a correction is needed. Avoid including sensitive details.</p>
                    </div>
                    <button type="submit" class="button button-secondary">Send correction request</button>
                </form>
            @else
                <p class="muted">Available once the applicant has submitted this document.</p>
            @endif
        </section>
    </div>

    @if ($document->corrections->isNotEmpty())
        <section class="card">
            <h2>Correction history</h2>
            <ul class="plain">
                @foreach ($document->corrections as $c)
                    <li><strong>{{ $c->created_at->timezone($tz)->format('M j, g:i A') }}</strong> by {{ $c->requester?->name }} ({{ $c->status }}): {{ $c->message }}</li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
