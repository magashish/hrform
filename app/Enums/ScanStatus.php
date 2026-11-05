<?php

namespace App\Enums;

enum ScanStatus: string
{
    case NotScanned = 'not_scanned';
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';
}
