@extends('layouts.app')

@section('title', 'New notice version')

@section('content')
    <section class="card">
        <h1>New notice version</h1>
        <p class="muted">Paste the final approved wording. Plain text; blank lines become paragraphs. You can use <code>{first_name}</code>, <code>{last_name}</code>, <code>{full_name}</code> and <code>{position_title}</code>; they are filled in for each applicant.</p>
        <form method="POST" action="{{ route('hr.consent.store') }}" enctype="multipart/form-data" novalidate>
            @csrf
            <div class="grid-2">
                <div class="field">
                    <label for="notice_key">Notice</label>
                    <select id="notice_key" name="notice_key">
                        @foreach ($notices as $key => $notice)
                            <option value="{{ $key }}" @selected(old('notice_key', $noticeKey) === $key)>{{ $notice['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label for="version">Version label</label><input id="version" type="text" name="version" value="{{ old('version') }}" maxlength="40" placeholder="e.g. 2026-11-v1" required></div>
            </div>
            <div class="field"><label for="title">Title</label><input id="title" type="text" name="title" value="{{ old('title', $latest?->title ?? ($notices[$noticeKey]['label'] ?? '')) }}" maxlength="200" required></div>
            <div class="field"><label for="body">Text</label><textarea id="body" name="body" rows="16" maxlength="50000" required>{{ old('body', $latest?->body) }}</textarea></div>
            <div class="field">
                <label for="attachment">PDF copy (optional)</label>
                <input id="attachment" type="file" name="attachment" accept="application/pdf,.pdf">
                <p class="hint">For example, the official CFPB "A Summary of Your Rights Under the Fair Credit Reporting Act". Applicants can open it while reading the notice.</p>
            </div>
            <label class="check"><input type="hidden" name="activate" value="0"><input type="checkbox" name="activate" value="1" checked> Make this the active version now</label>
            <div class="actions"><button type="submit" class="button">Save version</button><a href="{{ route('hr.consent.index') }}">Cancel</a></div>
        </form>
    </section>
@endsection
