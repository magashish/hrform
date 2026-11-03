@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>Dashboard</h1>
    <section class="card">
        <p>Signed in as <strong>{{ auth()->user()->name }}</strong>.</p>
        <p class="muted">Applicant tracking, invitations and document review will appear here as later phases are delivered.</p>
    </section>
@endsection
