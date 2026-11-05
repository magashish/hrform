@extends('layouts.app')

@section('title', $applicant->fullName())

@php($tz = config('app.display_timezone'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ $applicant->fullName() }}</h1>
            <p class="muted">{{ $applicant->email }} · @include('partials.status-badge', ['status' => $applicant->status])
                @if ($applicant->legal_hold) <span class="badge badge-warn">Legal hold</span> @endif
            </p>
        </div>
        <a class="button button-secondary" href="{{ route('hr.applicants.edit', $applicant) }}">Edit details</a>
    </div>

    <div class="grid-2">
        {{-- Progress and documents --}}
        <section class="card">
            <h2>Documents</h2>
            <div class="progress" role="progressbar" aria-valuenow="{{ $summary['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Required documents submitted">
                <div class="progress-bar progress-{{ (int) (round($summary['percent'] / 10) * 10) }}"></div>
            </div>
            <p class="muted">{{ $summary['done'] }} of {{ $summary['total'] }} required documents submitted.</p>

            @if ($documents->isEmpty())
                <p class="muted">No documents assigned yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th scope="col">Document</th><th scope="col">Status</th><th scope="col">Files</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                        @foreach ($documents as $doc)
                            <tr>
                                <td>
                                    <a href="{{ route('hr.documents.show', [$applicant, $doc]) }}">{{ $doc->template->name }}</a>
                                    <br><span class="muted small">{{ $doc->required ? 'Required' : 'Optional' }} · {{ $doc->signature_mode_snapshot->label() }}</span>
                                </td>
                                <td>@include('partials.status-badge', ['status' => $doc->status])</td>
                                <td>{{ ($doc->currentVersion ? 1 : 0) + $doc->currentSupportingFiles->count() }}</td>
                                <td class="right">
                                    @if ($doc->status->value === 'not_started')
                                        <form method="POST" action="{{ route('hr.documents.unassign', [$applicant, $doc]) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="link-button">Remove</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($assignable->isNotEmpty() && ! $applicant->transferred_at)
                <form method="POST" action="{{ route('hr.documents.assign', $applicant) }}" class="inline-form mt">
                    @csrf
                    <label for="template_id" class="sr-only">Add a document</label>
                    <select id="template_id" name="template_id">
                        @foreach ($assignable as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    <label class="check"><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" checked> Required</label>
                    <button type="submit" class="button button-small">Add document</button>
                </form>
            @endif
        </section>

        {{-- Invitation --}}
        <section class="card">
            <h2>Invitation &amp; access</h2>
            @php($active = $applicant->invitations->first(fn ($i) => $i->isUsable()))
            @if ($active)
                <p><span class="badge badge-ok">Active</span> Expires {{ $active->expires_at->timezone($tz)->format('M j, Y g:i A') }}
                    · Email: {{ ucfirst($active->delivery_status) }}
                    @if ($active->first_used_at) · First used {{ $active->first_used_at->timezone($tz)->format('M j') }} @endif
                </p>
            @else
                <p class="muted">No active invitation. The applicant cannot sign in.</p>
            @endif
            <p class="hint">Access codes are never shown again after they are emailed. Issuing a new invitation cancels the old code and keeps all of the applicant's progress.</p>

            @can('invite', $applicant)
                <form method="POST" action="{{ route('hr.invitations.store', $applicant) }}" class="inline">
                    @csrf
                    <button type="submit" class="button button-small">{{ $applicant->invitations->isEmpty() ? 'Send invitation' : 'Send new invitation' }}</button>
                </form>
            @endcan
            @if ($active)
                <form method="POST" action="{{ route('hr.invitations.destroy', [$applicant, $active]) }}" class="inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="button button-small button-secondary">Revoke access</button>
                </form>
            @endif

            @if ($applicant->invitations->isNotEmpty())
                <details class="mt">
                    <summary>Invitation history ({{ $applicant->invitations->count() }})</summary>
                    <ul class="plain small">
                        @foreach ($applicant->invitations as $inv)
                            <li>{{ $inv->issued_at->timezone($tz)->format('Y-m-d H:i') }} by {{ $inv->creator?->name ?? 'system' }}: {{ $inv->stateLabel() }}{{ $inv->revoke_reason ? ' ('.str_replace('_', ' ', $inv->revoke_reason).')' : '' }}, email {{ $inv->delivery_status }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            <h3 class="mt">Disclosure</h3>
            @forelse ($applicant->consents as $c)
                <p class="small">Acknowledged version <strong>{{ $c->version->version }}</strong> on {{ $c->acknowledged_at->timezone($tz)->format('Y-m-d H:i T') }}</p>
            @empty
                <p class="muted small">Not yet acknowledged.</p>
            @endforelse
        </section>
    </div>

    <div class="grid-2">
        {{-- Export and transfer --}}
        <section class="card">
            <h2>Download &amp; transfer</h2>
            <form method="POST" action="{{ route('hr.exports.store', $applicant) }}">
                @csrf
                <button type="submit" class="button">Prepare ZIP of all documents</button>
            </form>
            @if ($exports->isNotEmpty())
                <ul class="plain small mt">
                    @foreach ($exports as $e)
                        <li>
                            Requested {{ $e->created_at->timezone($tz)->format('M j g:i A') }}:
                            @if ($e->isDownloadable())
                                <a href="{{ route('hr.exports.download', [$applicant, $e]) }}">Download ZIP</a> ({{ $e->file_count }} files, expires {{ $e->expires_at->timezone($tz)->format('g:i A') }})
                            @elseif ($e->status === 'pending')
                                preparing… refresh shortly
                            @else
                                {{ $e->status }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <h3 class="mt">Transfer to HR system</h3>
            @if ($applicant->transferred_at)
                <p>Transferred {{ $applicant->transferred_at->timezone($tz)->format('M j, Y') }} by {{ $applicant->transferrer?->name }}.
                   Scheduled for deletion on <strong>{{ $applicant->deletion_due_at?->timezone($tz)->format('M j, Y') }}</strong>.</p>
            @else
                <form method="POST" action="{{ route('hr.applicants.transfer', $applicant) }}">
                    @csrf
                    <label class="check">
                        <input type="checkbox" name="confirm_transfer" value="1">
                        I have downloaded and checked the files and uploaded them to the HR system.
                    </label>
                    <button type="submit" class="button button-secondary">Mark as transferred</button>
                </form>
                <p class="hint">This starts the retention period ({{ app(\App\Services\Settings\SettingsService::class)->get('retention_days') }} days), after which the packet is deleted from this portal.</p>
            @endif
        </section>

        {{-- Retention controls --}}
        <section class="card">
            <h2>Retention</h2>
            <form method="POST" action="{{ route('hr.applicants.legal-hold', $applicant) }}">
                @csrf
                @if ($applicant->legal_hold)
                    <p>Legal hold is <strong>on</strong>{{ $applicant->legal_hold_reason ? ': '.$applicant->legal_hold_reason : '' }}. Nothing will be deleted.</p>
                    <input type="hidden" name="hold" value="0">
                    <button type="submit" class="button button-small button-secondary">Remove legal hold</button>
                @else
                    <input type="hidden" name="hold" value="1">
                    <div class="field">
                        <label for="reason">Legal hold reason</label>
                        <input id="reason" type="text" name="reason" maxlength="500">
                    </div>
                    <button type="submit" class="button button-small button-secondary">Place legal hold</button>
                @endif
            </form>

            @can('delete', $applicant)
                <details class="mt">
                    <summary>Delete this applicant permanently</summary>
                    <form method="POST" action="{{ route('hr.applicants.destroy', $applicant) }}" class="mt">
                        @csrf @method('DELETE')
                        <p class="small">This permanently deletes all documents, uploaded files, form answers and personal details for this applicant. It cannot be undone. Copies may remain in backups until they expire.</p>
                        <div class="field">
                            <label for="confirmation">Type DELETE to confirm</label>
                            <input id="confirmation" type="text" name="confirmation" autocomplete="off">
                        </div>
                        <button type="submit" class="button button-danger">Delete permanently</button>
                    </form>
                </details>
            @endcan
        </section>
    </div>

    {{-- Activity --}}
    <section class="card">
        <h2>Activity</h2>
        @if ($activity->isEmpty())
            <p class="muted">No activity yet.</p>
        @else
            <div class="table-wrap">
                <table class="small">
                    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                    @foreach ($activity as $event)
                        <tr>
                            <td>{{ $event->occurred_at->timezone($tz)->format('Y-m-d H:i') }}</td>
                            <td>{{ match ($event->actor_type) { 'hr' => $actorNames[$event->actor_id] ?? 'HR user', 'applicant' => 'Applicant', default => 'System' } }}</td>
                            <td>{{ str_replace(['.', '_'], [': ', ' '], $event->event) }}</td>
                            <td>
                                @foreach ($event->metadata ?? [] as $k => $v)
                                    <span class="meta">{{ str_replace('_', ' ', $k) }}: {{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
