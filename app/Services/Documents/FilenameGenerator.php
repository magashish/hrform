<?php

namespace App\Services\Documents;

use App\Models\Applicant;
use App\Services\Settings\SettingsService;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Builds client-facing filenames from the approved naming pattern, e.g.
 * {last_name}_{first_name}_{document_type}_{date} -> Garcia_Maria_I-9_20261105.pdf
 *
 * Every token is normalized (Unicode -> ASCII), stripped of path separators
 * and unsafe characters, and length-limited. User-supplied filenames are
 * never used.
 */
class FilenameGenerator
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  string|null  $pattern  per-template pattern; falls back to the global setting
     */
    public function forDocument(Applicant $applicant, string $documentType, string $extension, DateTimeInterface $date, ?string $requirement = null, int $version = 1, ?string $pattern = null): string
    {
        $pattern = trim((string) ($pattern ?: $this->settings->get('filename_pattern')));
        $dateFormat = (string) $this->settings->get('filename_date_format');

        // Patterns written with spaces (e.g. "{last_name}, {first_name} {document_type}")
        // keep spaces inside names; others join words with hyphens.
        $spaced = str_contains($pattern, ' ');
        $sep = $spaced ? ' ' : '_';

        $tokens = [
            '{last_name}' => $this->token($applicant->last_name, $spaced),
            '{first_name}' => $this->token($applicant->first_name, $spaced),
            '{document_type}' => $this->token($documentType, $spaced),
            '{date}' => $this->token(Carbon::instance($date)->timezone(config('app.display_timezone'))->format($dateFormat), $spaced),
            '{requirement}' => $this->token((string) $requirement, $spaced),
        ];

        $name = strtr($pattern, $tokens);

        // Supporting files always carry their requirement, even if the pattern omits it.
        if ($requirement !== null && ! str_contains($pattern, '{requirement}')) {
            $name .= ($spaced ? ' - ' : '_').$tokens['{requirement}'];
        }

        if ($version > 1) {
            $name .= $sep.'v'.$version;
        }

        return $this->finalize($name, $extension, $spaced);
    }

    /**
     * Makes $name unique within $taken by appending -2, -3, ... before the extension.
     *
     * @param  array<string, bool>  $taken  lower-cased names already used
     */
    public function unique(string $name, array &$taken): string
    {
        $candidate = $name;
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $i = 2;

        while (isset($taken[strtolower($candidate)])) {
            $candidate = $base.'-'.$i.($ext !== '' ? '.'.$ext : '');
            $i++;
        }

        $taken[strtolower($candidate)] = true;

        return $candidate;
    }

    public function token(string $value, bool $keepSpaces = false): string
    {
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        // Transliterate non-Latin scripts where intl is available (e.g. 世界 -> shi jie).
        if (function_exists('transliterator_transliterate')) {
            $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;
        }

        $value = Str::ascii($value);

        if ($keepSpaces) {
            $value = preg_replace('/[^A-Za-z0-9\-\s]/', '', $value) ?? '';
            $value = preg_replace('/\s+/', ' ', $value) ?? '';

            return mb_substr(trim($value, ' -'), 0, 40);
        }

        $value = preg_replace('/\s+/', '-', trim($value)) ?? '';
        $value = preg_replace('/[^A-Za-z0-9\-]/', '', $value) ?? '';
        $value = preg_replace('/-{2,}/', '-', $value) ?? '';

        return mb_substr(trim($value, '-'), 0, 40);
    }

    private function finalize(string $name, string $extension, bool $spaced = false): string
    {
        if ($spaced) {
            // Letters, digits, spaces, commas, hyphens and underscores only: no
            // path separators, dots or control characters.
            $name = preg_replace('/[^A-Za-z0-9 ,_\-]/', '', $name) ?? '';
            $name = preg_replace('/\s*,\s*/', ', ', $name) ?? '';
            $name = preg_replace('/,(\s*,)+/', ',', $name) ?? '';
            $name = preg_replace('/ {2,}/', ' ', $name) ?? '';
            $name = trim($name, ' ,_-');
        } else {
            $name = preg_replace('/[^A-Za-z0-9_\-]/', '', $name) ?? '';
            $name = preg_replace('/_{2,}/', '_', $name) ?? '';
            $name = trim($name, '_-');
        }
        $name = rtrim(substr($name, 0, 150), ' ,');

        if ($name === '') {
            $name = 'document';
        }

        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');

        return $extension !== '' ? $name.'.'.$extension : $name;
    }
}
