@extends('layouts.portal')

@section('title', 'My documents')

@section('content')
    <h1>Hello, {{ $applicant->first_name }}</h1>
    @if ($notices->isNotEmpty())
        <p class="small">
            @foreach ($notices as $n)
                <a href="{{ route('portal.notices.show', $n->id) }}">{{ $n->title }}</a>@unless ($loop->last) · @endunless
            @endforeach
        </p>
    @endif
    <section class="card">
        <div class="progress" role="progressbar" aria-valuenow="{{ $summary['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress">
            <div class="progress-bar progress-{{ (int) (round($summary['percent'] / 10) * 10) }}"></div>
        </div>
        <p>{{ $summary['done'] }} of {{ $summary['total'] }} required documents submitted.</p>
        @if ($moreToCome)
            <p class="hint">HR will send you more documents later in the hiring process. We'll email you when they're ready.</p>
        @endif
        <p class="hint">Your work is saved as you go. You can sign out and come back any time before {{ $invitation->expires_at->timezone(config('app.display_timezone'))->format('F j, Y g:i A T') }}.</p>
    </section>

    @if ($documents->isEmpty())
        <section class="card"><p>HR hasn't added any documents for you yet. Please check back later.</p></section>
    @else
        <ul class="checklist">
            @foreach ($documents as $doc)
                <li class="card checklist-item">
                    <div>
                        <a class="checklist-title" href="{{ route('portal.documents.show', $doc->id) }}">{{ $doc->template->name }}</a>
                        @unless ($doc->required)<span class="muted small">(optional)</span>@endunless
                        @if ($doc->template->description)<p class="muted small">{{ $doc->template->description }}</p>@endif
                        @if ($doc->openCorrections->isNotEmpty())<p class="alert-inline">HR has asked for a correction.</p>@endif
                    </div>
                    @include('partials.status-badge', ['status' => $doc->status])
                </li>
            @endforeach
        </ul>
    @endif
@endsection
