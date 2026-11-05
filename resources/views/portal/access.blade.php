@extends('layouts.portal')

@section('title', 'Start onboarding')

@section('content')
    <section class="card">
        <h1>Welcome</h1>
        <p>Enter the access code from your invitation email. If you opened the link in the email, the code is filled in for you.</p>
        <form method="POST" action="{{ route('portal.access.store') }}" id="access-form" novalidate>
            @csrf
            <div class="field">
                <label for="code">Access code</label>
                <input id="code" type="text" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" required data-fill-from-hash>
            </div>
            <div class="actions"><button type="submit" class="button">Continue</button></div>
        </form>
        <p class="hint mt">Codes expire after {{ config('portal.invitations.expiry_days') }} days. If yours has expired, contact HR for a new invitation. Your progress will be kept.</p>
    </section>
@endsection
