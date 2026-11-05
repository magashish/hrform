Hello {{ $firstName }},

{{ $intro }}

Start here: {{ $link }}

If the link does not work, go to {{ route('portal.access') }} and enter this access code:

{{ $code }}

You will also be asked to confirm your email address and enter a one-time code we send you.

This invitation expires on {{ $expiresAt->format('F j, Y g:i A T') }}. You can save your progress and return any time before then. If you need more time, contact HR for a new invitation; your progress will be kept.

This message contains no personal documents. If you were not expecting it, please ignore it or contact HR.
