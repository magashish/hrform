@extends('mail.layout')

@section('body')
<p>Hello {{ $firstName }},</p>
<p>{!! nl2br(e($intro)) !!}</p>
@foreach ($extraLines as $line)
<p>{{ $line }}</p>
@endforeach
<p>To continue, open the secure link in your original invitation email, or go to <a href="{{ $portalUrl }}">{{ $portalUrl }}</a> and enter your access code. If you no longer have it, contact HR for a new invitation; your progress will be kept.</p>
@endsection
