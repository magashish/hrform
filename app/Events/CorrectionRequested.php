<?php

namespace App\Events;

use App\Models\ApplicantDocument;
use Illuminate\Foundation\Events\Dispatchable;

class CorrectionRequested
{
    use Dispatchable;

    public function __construct(public ApplicantDocument $document) {}
}
