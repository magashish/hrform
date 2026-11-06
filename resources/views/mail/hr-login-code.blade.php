@extends('mail.layout')

@section('body')
<p>Your sign-in code for the HR area is:</p>
<p style="font-family:monospace;font-size:28px;letter-spacing:4px;font-weight:bold;">{{ $code }}</p>
<p>It expires in {{ $minutes }} minutes. If you did not just try to sign in, do not use this code: change your password and tell your administrator.</p>
@endsection
