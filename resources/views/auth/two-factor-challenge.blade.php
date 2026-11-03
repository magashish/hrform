@extends('layouts.app')

@section('title', 'Two-factor verification')

@section('content')
    <section class="card narrow">
        <h1>Two-factor verification</h1>
        <p>Enter the 6-digit code from your authenticator app.</p>

        <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
            @csrf
            <div class="field">
                <label for="code">Authentication code</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus>
            </div>
            <div class="actions">
                <button type="submit" class="button">Verify</button>
            </div>
        </form>

        <details class="mt">
            <summary>Use a recovery code instead</summary>
            <form method="POST" action="{{ route('two-factor.login') }}" novalidate>
                @csrf
                <div class="field">
                    <label for="recovery_code">Recovery code</label>
                    <input id="recovery_code" type="text" name="recovery_code" autocomplete="off">
                </div>
                <div class="actions">
                    <button type="submit" class="button button-secondary">Verify recovery code</button>
                </div>
            </form>
        </details>
    </section>
@endsection
