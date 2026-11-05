<?php

namespace App\Events;

use App\Models\ApplicantDocument;
use Illuminate\Foundation\Events\Dispatchable;

/** An applicant uploaded or replaced a file (signed document or supporting evidence). */
class SupportingFileUploaded
{
    use Dispatchable;

    public function __construct(public ApplicantDocument $document, public bool $replacement) {}
}
