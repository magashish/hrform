<?php

namespace App\Services\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Server-side upload validation: extension allowlist (template ∩ global),
 * size, MIME type from content inspection (finfo, not the browser header),
 * and rejection of active content.
 */
class FileValidator
{
    /** Allowed content types for each extension. */
    public const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'],
    ];

    /**
     * @param  list<string>  $allowedExtensions
     * @return array{extension: string, mime: string}
     *
     * @throws ValidationException
     */
    public function validate(UploadedFile $file, array $allowedExtensions, int $maxKb, string $field = 'file'): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([$field => $msg]);

        if (! $file->isValid()) {
            $fail('The upload failed. Please try again.');
        }

        $global = config('portal.files.allowed_extensions', []);
        $allowed = array_values(array_intersect(array_map('strtolower', $allowedExtensions), $global));
        $hardMax = (int) config('portal.files.hard_max_upload_kb', 25600);
        $maxKb = min($maxKb, $hardMax);

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowed, true)) {
            $fail('This file type is not accepted. Allowed: '.strtoupper(implode(', ', $allowed)).'.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            $fail('The file is empty.');
        }
        if ($size > $maxKb * 1024) {
            $fail('The file is too large. Maximum size is '.round($maxKb / 1024, 1).' MB.');
        }

        $path = $file->getRealPath();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;

        if (! is_string($mime) || ! in_array($mime, self::MIME_MAP[$extension] ?? [], true)) {
            $fail('The file content does not match its type. Please upload a genuine '.strtoupper($extension).' file.');
        }

        if ($this->containsActiveContent((string) $path, $extension)) {
            $fail('This file contains active content (scripts) and cannot be accepted. Please save it as a plain PDF or image and try again.');
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true) && @getimagesize((string) $path) === false) {
            $fail('The image could not be read. Please upload a valid image.');
        }

        return ['extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'mime' => $mime];
    }

    private function containsActiveContent(string $path, string $extension): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }

        $overlap = '';
        $markers = $extension === 'pdf'
            ? ['/JavaScript', '/JS ', '/JS(', '/JS<', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA', '<?php', '<script']
            : ['<?php', '<script', '<html', '<svg'];

        while (! feof($handle)) {
            $chunk = $overlap.fread($handle, 65536);
            foreach ($markers as $marker) {
                if (stripos($chunk, $marker) !== false) {
                    fclose($handle);

                    return true;
                }
            }
            $overlap = substr($chunk, -16);
        }

        fclose($handle);

        return false;
    }
}
