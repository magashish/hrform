@extends('mail.layout')

@section('body')
<p>Hello {{ $firstName }},</p>
<p>{!! nl2br(e($intro)) !!}</p>
<p style="margin:24px 0;"><a href="{{ $link }}" style="background:#1f4fd1;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold;">Start onboarding</a></p>
<p>If the button does not work, go to <a href="{{ route('portal.access') }}">{{ route('portal.access') }}</a> and enter this access code:</p>
<p style="font-family:monospace;font-size:18px;letter-spacing:1px;">{{ $code }}</p>
<p>For your security you will also be asked to confirm your email address and enter a one-time code we send you.</p>
<p>This invitation expires on <strong>{{ $expiresAt->format('F j, Y g:i A T') }}</strong>. You can save your progress and return any time before then. If you need more time, contact HR and they can send a new invitation; your progress will be kept.</p>
@endsection
