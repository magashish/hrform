@extends('layouts.portal')

@section('title', $template->name)

@php($tz = config('app.display_timezone'))

@section('content')
    <p><a href="{{ route('portal.home') }}">&larr; My documents</a></p>
    <div class="page-header">
        <h1>{{ $template->name }}</h1>
        @include('partials.status-badge', ['status' => $document->status])
    </div>

    @foreach ($document->openCorrections as $c)
        <div class="alert alert-warning" role="alert">
            <p><strong>Correction requested by HR:</strong></p>
            <p>{!! nl2br(e($c->message)) !!}</p>
        </div>
    @endforeach

    @if ($template->instructions)
        <section class="card prose">{!! nl2br(e($template->instructions)) !!}</section>
    @endif

    @unless ($editable)
        <div class="alert alert-success" role="status">You have submitted this document. HR will contact you if anything needs to change.</div>
    @endunless

    {{-- Online form --}}
    @if ($document->hasForm())
        <section class="card">
            <h2>Your information</h2>
            <form method="POST" action="{{ route('portal.documents.draft', $document->id) }}" novalidate>
                @csrf @method('PUT')
                <fieldset @disabled(! $editable)>
                    @foreach ($template->fields as $field)
                        @include('portal.partials.field', ['field' => $field, 'value' => old($field->field_key, $values[$field->field_key] ?? null)])
                    @endforeach
                </fieldset>
                @if ($editable)
                    <div class="actions">
                        <button type="submit" class="button button-secondary">Save draft</button>
                        <button type="submit" name="next" value="review" class="button">Save and review</button>
                    </div>
                @endif
            </form>
        </section>
    @endif

    {{-- Acknowledgement --}}
    @if ($document->isAcknowledgement())
        <section class="card">
            @if ($document->acknowledged_at)
                <p>You acknowledged this on {{ $document->acknowledged_at->timezone($tz)->format('F j, Y') }}.</p>
            @elseif ($editable)
                <form method="POST" action="{{ route('portal.documents.acknowledge', $document->id) }}">
                    @csrf
                    <label class="check"><input type="checkbox" name="acknowledge" value="1"> I have read and understood this document.</label>
                    <div class="actions"><button type="submit" class="button">Confirm</button></div>
                </form>
            @endif
        </section>
    @endif

    {{-- Wet signature / main upload --}}
    @if ($document->needsMainUpload())
        <section class="card">
            @if ($document->signature_mode_snapshot->value === 'wet_signature_upload')
                <h2>Sign by hand and upload</h2>
                <ol>
                    <li>@if ($template->hasPrintAsset())<a href="{{ route('portal.documents.print', $document->id) }}">Download the form (PDF)</a> and print it.@else Print the form HR has given you (if you don't have it, contact HR).@endif</li>
                    <li>Complete it and <strong>sign it by hand in ink</strong>.</li>
                    <li>Scan it or take a clear, well-lit photo of every page.</li>
                    <li>Upload it below as a {{ strtoupper(implode(', ', $template->allowed_extensions)) }} file.</li>
                </ol>
                <p class="hint">Electronic or typed signatures are not accepted for this document.</p>
            @else
                <h2>Upload your document</h2>
            @endif

            @if ($document->currentVersion)
                <p>Uploaded: <a href="{{ route('portal.documents.file', [$document->id, 'version', $document->currentVersion->id]) }}">{{ $document->currentVersion->generated_filename }}</a>
                    <span class="muted small">({{ $document->currentVersion->uploaded_at->timezone($tz)->format('M j, g:i A') }})</span></p>
            @endif

            @if ($editable)
                <form method="POST" action="{{ route('portal.documents.upload', $document->id) }}" enctype="multipart/form-data" data-upload>
                    @csrf
                    <div class="field">
                        <label for="file">{{ $document->currentVersion ? 'Replace file' : 'Choose file' }}</label>
                        <input id="file" type="file" name="file" accept="{{ collect($template->allowed_extensions)->map(fn ($e) => '.'.$e)->implode(',') }}" required>
                        <p class="hint">Maximum {{ round($template->max_size_kb / 1024, 1) }} MB.</p>
                    </div>
                    <progress class="upload-progress" max="100" value="0" hidden></progress>
                    <button type="submit" class="button">Upload</button>
                </form>
            @endif
        </section>
    @endif

    {{-- Supporting files --}}
    @if ($template->supportingRequirements())
        <section class="card">
            <h2>Supporting documents</h2>
            @foreach ($template->supportingRequirements() as $req)
                @php($current = $document->currentSupportingFiles->firstWhere('requirement_key', $req['key']))
                <div class="supporting">
                    <h3>{{ $req['label'] }} @unless ($req['required'])<span class="muted small">(optional)</span>@endunless</h3>
                    @if ($current)
                        <p>Uploaded: <a href="{{ route('portal.documents.file', [$document->id, 'supporting', $current->id]) }}">{{ $current->generated_filename }}</a></p>
                    @endif
                    @if ($editable)
                        <form method="POST" action="{{ route('portal.documents.supporting', [$document->id, $req['key']]) }}" enctype="multipart/form-data" data-upload class="inline-form">
                            @csrf
                            <label for="sf-{{ $req['key'] }}" class="sr-only">{{ $req['label'] }}</label>
                            <input id="sf-{{ $req['key'] }}" type="file" name="supporting_file" accept="{{ collect($template->allowed_extensions)->map(fn ($e) => '.'.$e)->implode(',') }}" required>
                            <progress class="upload-progress" max="100" value="0" hidden></progress>
                            <button type="submit" class="button button-small">{{ $current ? 'Replace' : 'Upload' }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if ($editable && ! $document->hasForm())
        <div class="actions"><a class="button" href="{{ route('portal.documents.review', $document->id) }}">Review and submit</a></div>
    @endif
@endsection
