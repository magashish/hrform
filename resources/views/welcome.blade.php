@extends('layouts.app')

@section('title', 'Welcome')

@section('content')
    <section class="card narrow">
        <h1>Onboarding Portal</h1>
        <p>If you are a new hire or applicant, please use the secure link in your invitation email to begin.</p>
        <p class="muted">HR staff: <a href="{{ route('login') }}">sign in</a>.</p>
    </section>
@endsection
