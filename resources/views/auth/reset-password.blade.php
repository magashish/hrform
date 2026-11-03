@extends('layouts.app')

@section('title', 'Choose a new password')

@section('content')
    <section class="card narrow">
        <h1>Choose a new password</h1>
        <p class="muted">At least {{ config('portal.hr.password_min_length') }} characters, with upper- and lower-case letters, a number and a symbol.</p>

        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
            <div class="actions">
                <button type="submit" class="button">Set password</button>
            </div>
        </form>
    </section>
@endsection
