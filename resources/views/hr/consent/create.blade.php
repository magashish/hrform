@extends('layouts.app')

@section('title', 'New disclosure version')

@section('content')
    <section class="card">
        <h1>New disclosure version</h1>
        <p class="muted">Paste the final wording approved by your HR consultant. Plain text; blank lines become paragraphs.</p>
        <form method="POST" action="{{ route('hr.consent.store') }}" novalidate>
            @csrf
            <div class="grid-2">
                <div class="field"><label for="version">Version label</label><input id="version" type="text" name="version" value="{{ old('version') }}" maxlength="40" placeholder="e.g. 2026-11-v1" required></div>
                <div class="field"><label for="title">Title</label><input id="title" type="text" name="title" value="{{ old('title', $latest?->title ?? 'Disclosure and consent') }}" maxlength="200" required></div>
            </div>
            <div class="field"><label for="body">Text</label><textarea id="body" name="body" rows="16" maxlength="50000" required>{{ old('body', $latest?->body) }}</textarea></div>
            <label class="check"><input type="hidden" name="activate" value="0"><input type="checkbox" name="activate" value="1" checked> Make this the active version now</label>
            <div class="actions"><button type="submit" class="button">Save version</button><a href="{{ route('hr.consent.index') }}">Cancel</a></div>
        </form>
    </section>
@endsection
