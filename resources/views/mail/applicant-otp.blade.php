@extends('mail.layout')

@section('body')
<p>Your verification code is:</p>
<p style="font-family:monospace;font-size:28px;letter-spacing:4px;font-weight:bold;">{{ $code }}</p>
<p>It expires in {{ $minutes }} minutes. Never share this code. HR will never ask you for it.</p>
@endsection
