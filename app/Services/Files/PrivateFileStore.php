<?php

namespace App\Services\Files;

use App\Enums\ScanStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores validated uploads on the private documents disk under random
 * names (no user-controlled path components), after hashing and scanning.
 */
class PrivateFileStore
{
    public function __construct(
        private readonly FileValidator $validator,
        private readonly FileScanner $scanner,
    ) {}

    /**
     * @param  list<string>  $allowedExtensions
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $directory, array $allowedExtensions, int $maxKb, string $field = 'file'): StoredFile
    {
        $checked = $this->validator->validate($file, $allowedExtensions, $maxKb, $field);
        $localPath = (string) $file->getRealPath();

        $scan = $this->scanner->scan($localPath);
        if ($scan === ScanStatus::Infected) {
            throw ValidationException::withMessages([$field => 'This file was flagged by our security scan and cannot be accepted.']);
        }
        if ($scan === ScanStatus::Error && config('portal.scanner.fail_closed')) {
            throw ValidationException::withMessages([$field => 'We could not check this file right now. Please try again later.']);
        }

        $disk = (string) config('portal.storage.documents_disk', 'documents');
        $directory = preg_replace('/[^A-Za-z0-9\-\/]/', '', $directory) ?? 'misc';
        $name = Str::uuid()->toString().'.bin';
        $sha = hash_file('sha256', $localPath) ?: '';

        $stored = Storage::disk($disk)->putFileAs($directory, $file, $name, ['visibility' => 'private']);
        if ($stored === false) {
            throw ValidationException::withMessages([$field => 'The file could not be saved. Please try again.']);
        }

        return new StoredFile(
            disk: $disk,
            path: $stored,
            extension: $checked['extension'],
            mime: $checked['mime'],
            size: (int) $file->getSize(),
            sha256: $sha,
            scanStatus: $scan,
            originalName: mb_substr(basename($file->getClientOriginalName()), 0, 255),
        );
    }

    public function delete(string $disk, string $path): bool
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return true;
        }

        return $storage->delete($path) && ! $storage->exists($path);
    }
}
