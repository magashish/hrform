@extends('layouts.portal')

@section('title', 'Review '.$template->name)

@section('content')
    <p><a href="{{ route('portal.documents.show', $document->id) }}">&larr; Back to {{ $template->name }}</a></p>
    <h1>Review and submit</h1>

    @if ($document->hasForm())
        <section class="card">
            <h2>Your answers</h2>
            <dl class="answers">
                @foreach ($template->fields as $field)
                    <dt>{{ $field->label }}</dt>
                    <dd>{{ $types->display($field, $values[$field->field_key] ?? null) }}</dd>
                @endforeach
            </dl>
        </section>
    @endif

    <section class="card">
        <h2>Checklist</h2>
        <ul class="plain">
            @if ($document->hasForm())<li>{!! $missing['form'] ? '&#10007; Some required answers are missing' : '&#10003; Form complete' !!}</li>@endif
            @if ($document->isAcknowledgement())<li>{!! $missing['acknowledgement'] ? '&#10007; Not yet acknowledged' : '&#10003; Acknowledged' !!}</li>@endif
            @if ($document->needsMainUpload())<li>{!! $missing['upload'] ? '&#10007; Signed/uploaded document missing' : '&#10003; Document uploaded: '.e($document->currentVersion?->generated_filename) !!}</li>@endif
            @foreach ($template->supportingRequirements() as $req)
                <li>{!! in_array($req['key'], $missing['supporting'], true) ? '&#10007; '.e($req['label']).' missing' : '&#10003; '.e($req['label']).($document->currentSupportingFiles->firstWhere('requirement_key', $req['key']) ? ' uploaded' : ' (optional, not provided)') !!}</li>
            @endforeach
        </ul>
    </section>

    @if (! $editable)
        <div class="alert alert-success" role="status">This document has already been submitted.</div>
    @elseif ($ready)
        <form method="POST" action="{{ route('portal.documents.submit', $document->id) }}" class="card">
            @csrf
            <label class="check"><input type="checkbox" name="confirm" value="1"> I confirm the information and documents I have provided are accurate and complete.</label>
            <div class="actions"><button type="submit" class="button">Submit to HR</button></div>
        </form>
    @else
        <div class="alert alert-warning" role="status">Please complete the missing items above before submitting. <a href="{{ route('portal.documents.show', $document->id) }}">Go back</a></div>
    @endif
@endsection
