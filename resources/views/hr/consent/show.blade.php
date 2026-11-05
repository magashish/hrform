@extends('layouts.app')

@section('title', 'Disclosure '.$version->version)

@section('content')
    <p><a href="{{ route('hr.consent.index') }}">&larr; Disclosure versions</a></p>
    <section class="card">
        <h1>{{ $version->title }}</h1>
        <p class="muted">Version {{ $version->version }} · {{ $version->active ? 'Active' : 'Inactive' }} · SHA-256 {{ substr($version->body_sha256, 0, 16) }}…</p>
        <div class="prose">{!! nl2br(e($version->body)) !!}</div>
    </section>
@endsection
