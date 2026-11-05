@php
    $tone = match ($status->value) {
        'approved', 'complete', 'ready_for_download', 'transferred' => 'badge-ok',
        'correction_required' => 'badge-warn',
        'submitted', 'under_review' => 'badge-info',
        default => 'badge-muted',
    };
@endphp
<span class="badge {{ $tone }}">{{ $status->label() }}</span>
