@extends('layouts.app')

@section('title', 'Document templates')

@section('content')
    <div class="page-header">
        <h1>Document templates</h1>
        <a class="button" href="{{ route('hr.templates.create') }}">New template</a>
    </div>
    <p class="muted">Each template defines one onboarding document: how it is completed, how it is signed, which files are needed and how they are named.</p>

    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Name</th><th scope="col">Code</th><th scope="col">Completed by</th><th scope="col">Signature</th><th scope="col">Default</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @forelse ($templates as $t)
                <tr>
                    <td><a href="{{ route('hr.templates.edit', $t) }}">{{ $t->name }}</a></td>
                    <td><code>{{ $t->code }}</code></td>
                    <td>{{ $t->input_mode->label() }}@if ($t->fields_count) ({{ $t->fields_count }} fields)@endif</td>
                    <td>{{ $t->signature_mode->label() }}</td>
                    <td>{{ $t->required_by_default ? 'Required' : 'Optional' }}{{ $t->auto_assign ? ', auto-assigned' : '' }}</td>
                    <td>{{ $t->active ? 'Active' : 'Inactive' }}{{ $t->print_asset_path ? '' : ($t->signature_mode->value === 'wet_signature_upload' ? ' · no printable form yet' : '') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No templates yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
