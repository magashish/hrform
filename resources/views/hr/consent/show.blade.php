@extends('layouts.app')

@section('title', $version->noticeLabel().' '.$version->version)

@section('content')
    <p><a href="{{ route('hr.consent.index') }}">&larr; Notices</a></p>
    <section class="card">
        <p class="muted">{{ $version->noticeLabel() }}</p>
        <h1>{{ $version->title }}</h1>
        <p class="muted">Version {{ $version->version }} · {{ $version->active ? 'Active' : 'Inactive' }} · SHA-256 {{ substr($version->body_sha256, 0, 16) }}…</p>
        @if ($version->attachment_path)
            <p><a href="{{ route('hr.consent.attachment', $version) }}" target="_blank" rel="noopener">Open PDF: {{ $version->attachment_name }}</a></p>
        @endif
        <div class="prose">{!! $version->renderFor(null) !!}</div>
    </section>
@endsection
