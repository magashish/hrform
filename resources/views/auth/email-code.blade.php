@extends('layouts.app')

@section('title', 'Enter your sign-in code')

@section('content')
    <section class="card narrow">
        <h1>Check your email</h1>
        <p>We sent a 6-digit sign-in code to <strong>{{ $email }}</strong>. It expires in {{ config('portal.hr.email_code_ttl_minutes') }} minutes.</p>

        @if (session('code_error'))
            <div class="alert alert-error" role="alert">{{ session('code_error') }}</div>
        @endif

        <form method="POST" action="{{ route('hr.email-code.verify') }}" novalidate>
            @csrf
            <div class="field">
                <label for="code">Sign-in code</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus required>
            </div>
            <div class="actions"><button type="submit" class="button">Verify</button></div>
        </form>

        <form method="POST" action="{{ route('hr.email-code.resend') }}" class="mt">
            @csrf
            <p class="hint">Didn't get it? Check your spam folder, or <button type="submit" class="link-button">send a new code</button>.</p>
        </form>
    </section>
@endsection
