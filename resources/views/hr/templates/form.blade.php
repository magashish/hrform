@extends('layouts.app')

@section('title', $template ? 'Edit '.$template->name : 'New template')

@php($t = $template)
@php($tz = config('app.display_timezone'))

@section('content')
    <p><a href="{{ route('hr.templates.index') }}">&larr; Document templates</a></p>
    <h1>{{ $t ? $t->name : 'New document template' }}</h1>

    <section class="card">
        <h2>Settings</h2>
        <form method="POST" action="{{ $t ? route('hr.templates.update', $t) : route('hr.templates.store') }}" novalidate>
            @csrf
            @if ($t) @method('PUT') @endif
            <div class="grid-2">
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $t?->name) }}" maxlength="150" required>
                </div>
                <div class="field">
                    <label for="code">Code</label>
                    <input id="code" type="text" name="code" value="{{ old('code', $t?->code) }}" maxlength="40" required>
                    <p class="hint">Short unique ID, e.g. I9, SSA89, W4.</p>
                </div>
                <div class="field">
                    <label for="document_type_label">Document type in filenames</label>
                    <input id="document_type_label" type="text" name="document_type_label" value="{{ old('document_type_label', $t?->document_type_label) }}" maxlength="60" required>
                    <p class="hint">Used for {document_type} in the naming pattern, e.g. "I-9".</p>
                </div>
                <div class="field">
                    <label for="sort_order">Order</label>
                    <input id="sort_order" type="number" name="sort_order" value="{{ old('sort_order', $t?->sort_order ?? 100) }}" min="0" max="10000">
                </div>
            </div>

            <div class="field">
                <label for="description">Short description (shown in the applicant's checklist)</label>
                <input id="description" type="text" name="description" value="{{ old('description', $t?->description) }}" maxlength="2000">
            </div>
            <div class="field">
                <label for="instructions">Instructions for the applicant</label>
                <textarea id="instructions" name="instructions" rows="5" maxlength="10000">{{ old('instructions', $t?->instructions) }}</textarea>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="input_mode">How is it completed?</label>
                    <select id="input_mode" name="input_mode">
                        @foreach (\App\Enums\InputMode::cases() as $m)
                            <option value="{{ $m->value }}" @selected(old('input_mode', $t?->input_mode->value ?? 'upload') === $m->value)>{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="signature_mode">Signature</label>
                    <select id="signature_mode" name="signature_mode">
                        @foreach (\App\Enums\SignatureMode::cases() as $m)
                            <option value="{{ $m->value }}" @selected(old('signature_mode', $t?->signature_mode->value ?? 'wet_signature_upload') === $m->value) @disabled($m->value === 'provider_esign' && ! $esignAvailable)>
                                {{ $m->label() }}{{ $m->value === 'provider_esign' && ! $esignAvailable ? ' (no provider configured)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="hint">Regulated forms (I-9, SSA-89) stay on wet signature until written legal approval is recorded below.</p>
                </div>
            </div>

            <fieldset class="field">
                <legend>Accepted file types</legend>
                @foreach (config('portal.files.allowed_extensions') as $ext)
                    <label class="check inline-check"><input type="checkbox" name="allowed_extensions[]" value="{{ $ext }}" @checked(in_array($ext, old('allowed_extensions', $t?->allowed_extensions ?? ['pdf', 'jpg', 'jpeg', 'png']), true))> {{ strtoupper($ext) }}</label>
                @endforeach
            </fieldset>
            <div class="field">
                <label for="max_size_kb">Maximum file size (KB)</label>
                <input id="max_size_kb" type="number" name="max_size_kb" value="{{ old('max_size_kb', $t?->max_size_kb ?? $defaultMaxKb) }}" min="100" max="{{ config('portal.files.hard_max_upload_kb') }}">
            </div>

            <div class="field">
                <label for="supporting_requirements">Supporting files (one per line)</label>
                <textarea id="supporting_requirements" name="supporting_requirements" rows="3" maxlength="5000">{{ old('supporting_requirements', collect($t?->supportingRequirements() ?? [])->map(fn ($r) => $r['label'].($r['required'] ? '' : ' | optional'))->implode("\n")) }}</textarea>
                <p class="hint">E.g. "Photo ID (front)" or "Voided cheque | optional". Leave empty if none are needed.</p>
            </div>

            <label class="check"><input type="hidden" name="required_by_default" value="0"><input type="checkbox" name="required_by_default" value="1" @checked(old('required_by_default', $t?->required_by_default ?? true))> Required by default</label>
            <label class="check"><input type="hidden" name="auto_assign" value="0"><input type="checkbox" name="auto_assign" value="1" @checked(old('auto_assign', $t?->auto_assign ?? false))> Pre-select for new applicants</label>
            <label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $t?->active ?? true))> Active</label>

            <div class="actions"><button type="submit" class="button">{{ $t ? 'Save template' : 'Create template' }}</button></div>
        </form>
    </section>

    @if ($t)
        <section class="card">
            <h2>Printable form</h2>
            <p class="muted">For wet-signature documents, upload the blank official PDF. Applicants download it, print, sign by hand and upload a scan or photo.</p>
            @if ($t->print_asset_path)
                <p>Current file: <a href="{{ route('hr.templates.print-asset.show', $t) }}">{{ $t->print_asset_name }}</a> <span class="muted small">(SHA-256 {{ substr((string) $t->print_asset_sha256, 0, 12) }}…)</span></p>
            @endif
            <form method="POST" action="{{ route('hr.templates.print-asset', $t) }}" enctype="multipart/form-data" class="inline-form">
                @csrf
                <label for="print_asset" class="sr-only">PDF file</label>
                <input id="print_asset" type="file" name="print_asset" accept="application/pdf,.pdf" required>
                <button type="submit" class="button button-small">Upload PDF</button>
            </form>
        </section>

        @if ($t->input_mode->value === 'online_form')
            <section class="card">
                <h2>Form fields</h2>
                @if ($t->fields->isEmpty())
                    <p class="muted">No fields yet.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th scope="col">Order</th><th scope="col">Label</th><th scope="col">Key</th><th scope="col">Type</th><th scope="col">Required</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody>
                            @foreach ($t->fields as $f)
                                <tr>
                                    <td>{{ $f->sort_order }}</td>
                                    <td>{{ $f->label }}@if ($f->sensitive) <span class="badge badge-warn">sensitive</span>@endif</td>
                                    <td><code>{{ $f->field_key }}</code></td>
                                    <td>{{ $fieldTypes[$f->type] ?? $f->type }}</td>
                                    <td>{{ $f->required ? 'Yes' : 'No' }}</td>
                                    <td class="right">
                                        <form method="POST" action="{{ route('hr.templates.fields.destroy', [$t, $f]) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="link-button">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <h3 class="mt">Add a field</h3>
                <form method="POST" action="{{ route('hr.templates.fields.store', $t) }}" novalidate>
                    @csrf
                    <div class="grid-3">
                        <div class="field"><label for="f_label">Label</label><input id="f_label" type="text" name="label" maxlength="200" required></div>
                        <div class="field"><label for="f_key">Key</label><input id="f_key" type="text" name="field_key" maxlength="60" placeholder="e.g. date_of_birth" required></div>
                        <div class="field"><label for="f_type">Type</label>
                            <select id="f_type" name="type">@foreach ($fieldTypes as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                        </div>
                    </div>
                    <div class="field"><label for="f_help">Help text</label><input id="f_help" type="text" name="help_text" maxlength="1000"></div>
                    <div class="grid-3">
                        <div class="field"><label for="f_order">Order</label><input id="f_order" type="number" name="sort_order" value="{{ ($t->fields->max('sort_order') ?? 0) + 10 }}"></div>
                        <div class="field"><label for="f_maxlen">Max length (text)</label><input id="f_maxlen" type="number" name="max_length" min="1" max="5000"></div>
                        <div class="field"><label for="f_min">Min / max (number)</label><div class="inline-form"><input id="f_min" type="number" name="min" step="any" aria-label="Minimum"><input type="number" name="max" step="any" aria-label="Maximum"></div></div>
                    </div>
                    <div class="field"><label for="f_options">Options (drop-down / single choice), one per line</label><textarea id="f_options" name="options" rows="3"></textarea></div>
                    <div class="field"><label for="f_statement">Statement (attestation)</label><textarea id="f_statement" name="statement" rows="2"></textarea></div>
                    <label class="check inline-check"><input type="checkbox" name="required" value="1"> Required</label>
                    <label class="check inline-check"><input type="checkbox" name="sensitive" value="1"> Sensitive (mask for HR)</label>
                    <label class="check inline-check"><input type="checkbox" name="not_future" value="1"> Date cannot be in the future</label>
                    <label class="check inline-check"><input type="checkbox" name="integer_only" value="1"> Whole numbers only</label>
                    <div class="actions"><button type="submit" class="button button-small">Add field</button></div>
                </form>
            </section>
        @endif

        <section class="card">
            <h2>Signature-mode approvals</h2>
            <p class="muted">Record written client/legal approval before changing a regulated document's signature method. Electronic signature also needs a configured provider.</p>
            @if ($t->signatureApprovals->isNotEmpty())
                <ul class="plain small">
                    @foreach ($t->signatureApprovals as $a)
                        <li><strong>{{ $a->signature_mode->label() }}</strong>: authorized by {{ $a->authorized_by_name }} on {{ $a->authorized_at->format('Y-m-d') }} (ref: {{ $a->approval_reference }}), recorded by {{ $a->recorder?->name }}{{ $a->notes ? ': '.$a->notes : '' }}</li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('hr.templates.approvals.store', $t) }}" novalidate>
                @csrf
                <div class="grid-2">
                    <div class="field"><label for="a_mode">Signature mode</label>
                        <select id="a_mode" name="signature_mode">@foreach (\App\Enums\SignatureMode::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select>
                    </div>
                    <div class="field"><label for="a_date">Date authorized</label><input id="a_date" type="date" name="authorized_at" required></div>
                    <div class="field"><label for="a_by">Authorized by (name and role)</label><input id="a_by" type="text" name="authorized_by_name" maxlength="150" required></div>
                    <div class="field"><label for="a_ref">Reference to written approval</label><input id="a_ref" type="text" name="approval_reference" maxlength="200" required></div>
                </div>
                <div class="field"><label for="a_notes">Notes</label><input id="a_notes" type="text" name="notes" maxlength="2000"></div>
                <button type="submit" class="button button-small button-secondary">Record approval</button>
            </form>
        </section>
    @endif
@endsection
