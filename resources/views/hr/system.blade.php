@extends('layouts.app')

@section('title', 'System')

@php($tz = config('app.display_timezone'))

@section('content')
    <h1>System</h1>

    <section class="card">
        <h2>Health</h2>
        <div class="table-wrap">
            <table>
                <tbody>
                @foreach ($checks as [$label, $value, $ok])
                    <tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td><td>{!! $ok ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-warn">Check</span>' !!}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Retention</h2>
        <p><strong>{{ $retention['due'] }}</strong> packet(s) are past their deletion date. <strong>{{ $retention['held'] }}</strong> are on legal hold.</p>
        <p class="hint">Run <code>php artisan portal:retention:purge --dry-run</code> on the server for a report. Deleting a packet here does not remove copies already in backups; those expire on the backup schedule.</p>
        @if ($recentRetention->isNotEmpty())
            <div class="table-wrap">
                <table class="small">
                    <thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">Trigger</th><th scope="col">Result</th><th scope="col">Reference</th></tr></thead>
                    <tbody>
                    @foreach ($recentRetention as $r)
                        <tr><td>{{ \Illuminate\Support\Carbon::parse($r->created_at)->timezone($tz)->format('Y-m-d H:i') }}</td><td>{{ $r->action }}</td><td>{{ $r->trigger }}</td><td>{{ $r->result }}</td><td><code>{{ substr((string) $r->applicant_ref, 0, 8) }}</code></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
