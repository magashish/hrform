Hello {{ $firstName }},

{{ $intro }}

@foreach ($extraLines as $line)
{{ $line }}

@endforeach
To continue, open the secure link in your original invitation email, or go to {{ $portalUrl }} and enter your access code. If you no longer have it, contact HR for a new invitation; your progress will be kept.
