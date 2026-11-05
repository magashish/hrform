<?php

namespace App\Services\Files;

use App\Enums\ScanStatus;

final class StoredFile
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $extension,
        public readonly string $mime,
        public readonly int $size,
        public readonly string $sha256,
        public readonly ScanStatus $scanStatus,
        public readonly string $originalName,
    ) {}
}
