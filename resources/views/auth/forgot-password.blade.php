@extends('layouts.app')

@section('title', 'Reset password')

@section('content')
    <section class="card narrow">
        <h1>Reset password</h1>

        @if (session('status') === 'reset-link-requested')
            {{-- Generic message whether or not the account exists. --}}
            <div class="alert alert-success" role="status">If that address belongs to an HR account, a reset link has been sent.</div>
        @endif

        <p>Enter your HR account email and we will send a password reset link.</p>

        <form method="POST" action="{{ route('password.email') }}" novalidate>
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required autofocus autocomplete="username">
            </div>
            <div class="actions">
                <button type="submit" class="button">Send reset link</button>
                <a href="{{ route('login') }}">Back to sign in</a>
            </div>
        </form>
    </section>
@endsection
