@extends('layouts.app')

@section('title', $applicant ? 'Edit applicant' : 'Add applicant')

@section('content')
    <section class="card narrow">
        <h1>{{ $applicant ? 'Edit applicant' : 'Add applicant' }}</h1>

        <form method="POST" action="{{ $applicant ? route('hr.applicants.update', $applicant) : route('hr.applicants.store') }}" novalidate>
            @csrf
            @if ($applicant) @method('PUT') @endif

            <div class="field">
                <label for="first_name">First name</label>
                <input id="first_name" type="text" name="first_name" value="{{ old('first_name', $applicant?->first_name) }}" required maxlength="100" autocomplete="off">
            </div>
            <div class="field">
                <label for="last_name">Last name</label>
                <input id="last_name" type="text" name="last_name" value="{{ old('last_name', $applicant?->last_name) }}" required maxlength="100" autocomplete="off">
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email', $applicant?->email) }}" required maxlength="254" autocomplete="off">
                <p class="hint">Double-check this address: the invitation and sign-in codes are sent here.@if ($applicant) Changing it revokes any active invitation.@endif</p>
            </div>

            @unless ($applicant)
                <fieldset class="field">
                    <legend>Documents to complete</legend>
                    @forelse ($templates as $t)
                        <label class="check">
                            <input type="checkbox" name="templates[]" value="{{ $t->id }}" @checked(in_array($t->id, old('templates', $templates->where('auto_assign', true)->pluck('id')->all())))>
                            {{ $t->name }} <span class="muted">({{ $t->required_by_default ? 'required' : 'optional' }})</span>
                        </label>
                    @empty
                        <p class="muted">No document templates yet. <a href="{{ route('hr.templates.create') }}">Create one</a>, or add documents to this applicant later.</p>
                    @endforelse
                </fieldset>

                <label class="check">
                    <input type="hidden" name="send_invitation" value="0">
                    <input type="checkbox" name="send_invitation" value="1" @checked(old('send_invitation', '1') === '1')>
                    Email the invitation now ({{ config('portal.invitations.expiry_days') }}-day access code)
                </label>
            @endunless

            <div class="actions">
                <button type="submit" class="button">{{ $applicant ? 'Save changes' : 'Create applicant' }}</button>
                <a href="{{ $applicant ? route('hr.applicants.show', $applicant) : route('hr.applicants.index') }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
