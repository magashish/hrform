@extends('layouts.portal')

@section('title', $version?->title ?? 'Before you begin')

@section('content')
    <section class="card">
        @if (! $version)
            <h1>Not quite ready</h1>
            <p>The onboarding portal is not ready for you yet. Please contact HR.</p>
        @else
            @if ($steps > 1)
                <p class="muted small">Before you begin: step {{ $step }} of {{ $steps }}</p>
            @endif
            <h1>{{ $version->title }}</h1>
            <p class="muted small">{{ $version->noticeLabel() }} · version {{ $version->version }}</p>
            <div class="prose consent-text" tabindex="0">{!! $version->renderFor($applicant) !!}</div>
            @if ($version->attachment_path)
                <p class="mt"><a href="{{ route('portal.notices.attachment', $version->id) }}" target="_blank" rel="noopener">Open the full document (PDF)</a></p>
            @endif
            <form method="POST" action="{{ route('portal.consent.store') }}" class="mt" novalidate>
                @csrf
                <input type="hidden" name="version_id" value="{{ $version->id }}">
                <label class="check"><input type="checkbox" name="acknowledge" value="1" required> {{ $version->acknowledgementText() }}</label>
                <div class="actions"><button type="submit" class="button">{{ $step < $steps ? 'Continue' : 'Continue to my documents' }}</button></div>
            </form>
        @endif
    </section>
@endsection
