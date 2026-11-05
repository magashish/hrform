@extends('layouts.portal')

@section('title', 'Disclosure')

@section('content')
    <section class="card">
        @if (! $version)
            <h1>Not quite ready</h1>
            <p>The onboarding portal is not ready for you yet. Please contact HR.</p>
        @else
            <h1>{{ $version->title }}</h1>
            <p class="muted small">Version {{ $version->version }}</p>
            <div class="prose consent-text" tabindex="0">{!! nl2br(e($version->body)) !!}</div>
            <form method="POST" action="{{ route('portal.consent.store') }}" class="mt" novalidate>
                @csrf
                <input type="hidden" name="version_id" value="{{ $version->id }}">
                <label class="check"><input type="checkbox" name="acknowledge" value="1" required> I have read and understand this disclosure, and I consent to the collection and use of my information as described.</label>
                <div class="actions"><button type="submit" class="button">I agree, continue</button></div>
            </form>
        @endif
    </section>
@endsection
