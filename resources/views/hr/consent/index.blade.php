@extends('layouts.app')

@section('title', 'Notices')

@section('content')
    <h1>Notices</h1>
    <p class="muted">Applicants read and acknowledge each active notice, in this order, before they can see any forms. Text cannot be edited after it is saved; create a new version instead. Applicants are asked to acknowledge each newly activated version. A notice type with no active version is skipped.</p>

    @foreach ($notices as $key => $notice)
        @php($list = $versions->get($key, collect()))
        <section class="card">
            <div class="page-header">
                <h2>{{ $loop->iteration }}. {{ $notice['label'] }}</h2>
                <a class="button button-small" href="{{ route('hr.consent.create', ['notice' => $key]) }}">New version</a>
            </div>
            @if ($list->isEmpty())
                <p class="muted">No versions yet. This notice is not shown to applicants.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th scope="col">Version</th><th scope="col">Title</th><th scope="col">PDF</th><th scope="col">Acknowledgements</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                        @foreach ($list as $v)
                            <tr>
                                <td><a href="{{ route('hr.consent.show', $v) }}">{{ $v->version }}</a></td>
                                <td>{{ $v->title }}</td>
                                <td>{{ $v->attachment_path ? 'Yes' : '—' }}</td>
                                <td>{{ $v->acknowledgements_count }}</td>
                                <td>@if ($v->active)<span class="badge badge-ok">Active</span>@else<span class="badge badge-muted">Inactive</span>@endif</td>
                                <td class="right">
                                    @unless ($v->active)
                                        <form method="POST" action="{{ route('hr.consent.activate', $v) }}" class="inline">
                                            @csrf <button type="submit" class="link-button">Make active</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach
@endsection
