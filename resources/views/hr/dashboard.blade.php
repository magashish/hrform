@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="page-header">
        <h1>Dashboard</h1>
        <a class="button" href="{{ route('hr.applicants.create') }}">Add applicant</a>
    </div>

    @foreach ($expiringForms as $form)
        <div class="alert alert-warning" role="status">
            <strong>{{ $form->document_type_label }}</strong> (edition {{ $form->form_edition ?? 'unknown' }})
            {{ $form->expiryState() === 'expired' ? 'expired on' : 'expires on' }} {{ $form->form_expires_on->format('F j, Y') }}.
            Upload the new official edition when it is published. <a href="{{ route('hr.templates.edit', $form) }}">Update template</a>
        </div>
    @endforeach

    <div class="stats">
        @foreach ($statuses as $status)
            <a class="stat" href="{{ route('hr.applicants.index', ['status' => $status->value]) }}">
                <span class="stat-value">{{ $counts[$status->value] ?? 0 }}</span>
                <span class="stat-label">{{ $status->label() }}</span>
            </a>
        @endforeach
    </div>

    <div class="grid-2">
        <section class="card">
            <h2>Needs attention</h2>
            <ul class="plain">
                <li><strong>{{ $awaitingReview }}</strong> document(s) awaiting review</li>
                <li><strong>{{ $dueForDeletion }}</strong> packet(s) due for deletion within 7 days</li>
            </ul>
        </section>
        <section class="card">
            <h2>Recently updated</h2>
            @if ($recent->isEmpty())
                <p class="muted">No applicants yet. <a href="{{ route('hr.applicants.create') }}">Add the first one.</a></p>
            @else
                <ul class="plain">
                    @foreach ($recent as $a)
                        <li><a href="{{ route('hr.applicants.show', $a) }}">{{ $a->fullName() }}</a> @include('partials.status-badge', ['status' => $a->status])</li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
