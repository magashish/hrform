<?php

namespace App\Events;

use App\Models\Applicant;
use Illuminate\Foundation\Events\Dispatchable;

/** All required documents have been submitted. */
class PacketCompleted
{
    use Dispatchable;

    public function __construct(public Applicant $applicant) {}
}
