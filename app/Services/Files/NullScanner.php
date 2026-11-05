<?php

namespace App\Services\Files;

use App\Enums\ScanStatus;

/**
 * Default when no scanner is configured. Files are recorded as not_scanned;
 * this is a documented deployment risk (docs/security-and-privacy.md).
 */
class NullScanner implements FileScanner
{
    public function scan(string $path): ScanStatus
    {
        return ScanStatus::NotScanned;
    }

    public function name(): string
    {
        return 'none';
    }
}
