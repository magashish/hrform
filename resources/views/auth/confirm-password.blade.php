@extends('layouts.app')

@section('title', 'Confirm password')

@section('content')
    <section class="card narrow">
        <h1>Confirm your password</h1>
        <p>This is a sensitive action. Please confirm your password to continue.</p>

        <form method="POST" action="{{ route('password.confirm') }}" novalidate>
            @csrf
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
            </div>
            <div class="actions">
                <button type="submit" class="button">Confirm</button>
            </div>
        </form>
    </section>
@endsection
