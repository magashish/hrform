@extends('layouts.portal')

@section('title', 'Verify your identity')

@section('content')
    <section class="card">
        <h1>Confirm it's you</h1>
        <p>Enter the email address your invitation was sent to. We'll email you a 6-digit code.</p>
        <form method="POST" action="{{ route('portal.verify.email') }}" novalidate>
            @csrf
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" autocomplete="email" maxlength="254" required @if (! $otpRequested) autofocus @endif>
            </div>
            <div class="actions"><button type="submit" class="button {{ $otpRequested ? 'button-secondary' : '' }}">{{ $otpRequested ? 'Send a new code' : 'Send code' }}</button></div>
        </form>

        @if ($otpRequested)
            <form method="POST" action="{{ route('portal.verify.code') }}" class="mt" novalidate>
                @csrf
                <div class="field">
                    <label for="otp">6-digit code</label>
                    <input id="otp" type="text" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus required>
                </div>
                <div class="actions"><button type="submit" class="button">Verify and continue</button></div>
            </form>
        @endif
    </section>
@endsection
