@extends('layouts.app')

@section('title', 'Add HR user')

@section('content')
    <section class="card narrow">
        <h1>Add HR user</h1>
        <p class="muted">The new user will be emailed a link to set their own password, @if (config('portal.hr.two_factor_method') === 'email') and will receive a one-time code by email each time they sign in. @else and must set up an authenticator app at first sign-in. @endif</p>

        <form method="POST" action="{{ route('hr.users.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="off">
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="off">
            </div>
            <div class="actions">
                <button type="submit" class="button">Create user</button>
                <a href="{{ route('hr.users.index') }}">Cancel</a>
            </div>
        </form>
    </section>
@endsection
