<?php

namespace App\Services\Files;

use App\Enums\ScanStatus;

interface FileScanner
{
    /**
     * Scans a local file. Must not throw for infected files; return Infected.
     */
    public function scan(string $path): ScanStatus;

    public function name(): string;
}
