@extends('layouts.portal')

@section('title', 'All done')

@section('content')
    <section class="card">
        <h1>Thank you, {{ $applicant->first_name }}!</h1>
        <p>You have submitted all of your required onboarding documents. HR will review them and contact you if anything needs to be corrected.</p>
        <p><a href="{{ route('portal.home') }}">View my documents</a></p>
    </section>
@endsection
