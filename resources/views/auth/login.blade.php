@extends('layouts.app')

@section('title', 'HR sign in')

@section('content')
    <section class="card narrow">
        <h1>HR sign in</h1>

        <form method="POST" action="{{ route('login') }}" novalidate>
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="actions">
                <button type="submit" class="button">Sign in</button>
                <a href="{{ route('password.request') }}">Forgot password?</a>
            </div>
        </form>
    </section>
@endsection
