<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a private file with safe headers. Inline previews are only
 * allowed for PDF and images and are sandboxed by CSP.
 */
class FileResponse
{
    public static function make(string $disk, string $path, string $downloadName, string $mime, bool $inline = false): StreamedResponse
    {
        $inline = $inline && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $fallback = preg_replace('/[^A-Za-z0-9._\-]/', '_', $downloadName) ?: 'download';

        $response = new StreamedResponse(function () use ($disk, $path) {
            $stream = Storage::disk($disk)->readStream($path);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        });

        $response->headers->set('Content-Type', $inline ? $mime : 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $downloadName,
            $fallback,
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf; sandbox");
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
