@extends('layouts.portal')

@section('title', $version->title)

@section('content')
    <p><a href="{{ route('portal.home') }}">&larr; My documents</a></p>
    <section class="card">
        <p class="muted small">{{ $version->noticeLabel() }} · version {{ $version->version }}</p>
        <h1>{{ $version->title }}</h1>
        <div class="prose">{!! $version->renderFor($applicant) !!}</div>
        @if ($version->attachment_path && $version->active)
            <p class="mt"><a href="{{ route('portal.notices.attachment', $version->id) }}" target="_blank" rel="noopener">Open the full document (PDF)</a></p>
        @endif
    </section>
@endsection
