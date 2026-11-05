@extends('layouts.app')

@section('title', 'Applicants')

@section('content')
    <div class="page-header">
        <h1>Applicants</h1>
        <a class="button" href="{{ route('hr.applicants.create') }}">Add applicant</a>
    </div>

    <form method="GET" action="{{ route('hr.applicants.index') }}" class="card filters">
        <div class="field">
            <label for="q">Name or email</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Any</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="from">Created from</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="field">
            <label for="to">Created to</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        </div>
        <div class="actions">
            <button type="submit" class="button">Filter</button>
            <a href="{{ route('hr.applicants.index') }}">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Status</th>
                <th scope="col">Invitation</th>
                <th scope="col">Created</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($applicants as $a)
                <tr>
                    <td><a href="{{ route('hr.applicants.show', $a) }}">{{ $a->last_name }}, {{ $a->first_name }}</a></td>
                    <td>{{ $a->email }}</td>
                    <td>@include('partials.status-badge', ['status' => $a->status])</td>
                    <td>{{ $a->activeInvitation ? 'Active until '.$a->activeInvitation->expires_at->timezone(config('app.display_timezone'))->format('M j') : 'None active' }}</td>
                    <td>{{ $a->created_at->timezone(config('app.display_timezone'))->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No applicants match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $applicants->links('pagination.simple') }}
@endsection
