@extends('layouts.app')

@section('title', 'Disclosure & consent')

@section('content')
    <div class="page-header">
        <h1>Disclosure &amp; consent</h1>
        <a class="button" href="{{ route('hr.consent.create') }}">New version</a>
    </div>
    <p class="muted">Applicants must acknowledge the active version before they can see any forms. Text cannot be edited after it is saved; create a new version instead. Applicants are asked to acknowledge each newly activated version.</p>

    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Version</th><th scope="col">Title</th><th scope="col">Acknowledgements</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            @forelse ($versions as $v)
                <tr>
                    <td><a href="{{ route('hr.consent.show', $v) }}">{{ $v->version }}</a></td>
                    <td>{{ $v->title }}</td>
                    <td>{{ $v->acknowledgements_count }}</td>
                    <td>{!! $v->active ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-muted">Inactive</span>' !!}</td>
                    <td class="right">
                        @unless ($v->active)
                            <form method="POST" action="{{ route('hr.consent.activate', $v) }}" class="inline">
                                @csrf <button type="submit" class="link-button">Make active</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No versions yet. Applicants cannot proceed until a version is active.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
