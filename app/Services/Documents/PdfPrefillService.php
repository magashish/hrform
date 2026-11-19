<?php

namespace App\Services\Documents;

use App\Models\ApplicantDocument;
use App\Models\DocumentTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * Types an applicant's answers onto a template's printable PDF (e.g. the
 * client's SSA-89 with company details already completed), so the applicant
 * only has to print, sign by hand and upload it.
 *
 * - Works on flat PDFs by writing text at configured positions (points from
 *   the top-left of the page). Encrypted PDFs (e.g. the official USCIS I-9)
 *   cannot be pre-filled and are rejected when the map is saved.
 * - Never writes anything into a signature area: signatures stay handwritten.
 * - The generated PDF is streamed to the applicant and never stored.
 *
 * Map entry: {"page":1,"x":22,"y":111,"source":"field:date_of_birth","format":"m/d/Y","size":10}
 * Sources: applicant.full_name | applicant.first_name | applicant.last_name | applicant.email |
 *          field:<field_key> | text:<fixed text> | check:<field_key>=<value> (draws an X) |
 *          circle:<field_key>=<value> (draws a box of w x h around a printed option, e.g. "FRIEND" or "Y")
 */
class PdfPrefillService
{
    private const KEYS = ['page', 'x', 'y', 'source', 'format', 'size', 'max_width', 'w', 'h'];

    private const APPLICANT_SOURCES = ['applicant.full_name', 'applicant.first_name', 'applicant.last_name', 'applicant.email'];

    private const DATE_FORMATS = ['m/d/Y', 'Y-m-d', 'd/m/Y', 'mdY'];

    public function __construct(private readonly FieldTypeRegistry $types) {}

    /**
     * Validates and normalizes a JSON map entered by HR.
     *
     * @return list<array<string, mixed>>|null
     *
     * @throws ValidationException
     */
    public function normalizeMap(?string $json, DocumentTemplate $template): ?array
    {
        $json = trim((string) $json);
        if ($json === '') {
            return null;
        }

        $fail = fn (string $m) => throw ValidationException::withMessages(['prefill_map' => $m]);
        $data = json_decode($json, true);

        if (! is_array($data) || ! array_is_list($data) || $data === [] || count($data) > 60) {
            $fail('Enter a JSON list of 1–60 entries.');
        }

        $fieldKeys = $template->exists ? $template->fields()->pluck('field_key')->all() : [];
        $clean = [];

        foreach ($data as $i => $entry) {
            $n = $i + 1;
            if (! is_array($entry) || array_diff(array_keys($entry), self::KEYS) !== []) {
                $fail("Entry {$n}: only these keys are allowed: ".implode(', ', self::KEYS).'.');
            }
            foreach (['page', 'x', 'y'] as $k) {
                if (! isset($entry[$k]) || ! is_numeric($entry[$k])) {
                    $fail("Entry {$n}: \"{$k}\" must be a number.");
                }
            }
            if ((int) $entry['page'] < 1 || (int) $entry['page'] > 20 || $entry['x'] < 0 || $entry['x'] > 2000 || $entry['y'] < 0 || $entry['y'] > 2000) {
                $fail("Entry {$n}: page/x/y out of range.");
            }

            $source = (string) ($entry['source'] ?? '');
            $valid = in_array($source, self::APPLICANT_SOURCES, true)
                || (preg_match('/^field:([a-z][a-z0-9_]*)$/', $source, $m) && in_array($m[1], $fieldKeys, true))
                || (preg_match('/^(check|circle):([a-z][a-z0-9_]*)=(.{1,200})$/', $source, $c) && in_array($c[2], $fieldKeys, true))
                || (preg_match('/^text:(.{1,200})$/su', $source) === 1);
            if (! $valid) {
                $fail("Entry {$n}: unknown source \"{$source}\". Use applicant.full_name, applicant.email, field:<key of a field on this template>, check:<key>=<value>, circle:<key>=<value> or text:<fixed text>.");
            }
            if (str_starts_with($source, 'circle:') && (! isset($entry['w'], $entry['h']) || ! is_numeric($entry['w']) || ! is_numeric($entry['h']))) {
                $fail("Entry {$n}: circle entries need a width \"w\" and height \"h\".");
            }

            if (isset($entry['format']) && ! in_array($entry['format'], self::DATE_FORMATS, true)) {
                $fail("Entry {$n}: format must be one of ".implode(', ', self::DATE_FORMATS).'.');
            }

            $clean[] = array_filter([
                'page' => (int) $entry['page'],
                'x' => round((float) $entry['x'], 2),
                'y' => round((float) $entry['y'], 2),
                'source' => $source,
                'format' => $entry['format'] ?? null,
                'size' => isset($entry['size']) ? max(6, min(16, (float) $entry['size'])) : null,
                'max_width' => isset($entry['max_width']) ? max(10, min(600, (float) $entry['max_width'])) : null,
                'w' => isset($entry['w']) ? max(2, min(300, (float) $entry['w'])) : null,
                'h' => isset($entry['h']) ? max(2, min(100, (float) $entry['h'])) : null,
            ], fn ($v) => $v !== null);
        }

        return $clean;
    }

    /**
     * Throws if the template's printable PDF cannot be pre-filled (e.g. encrypted).
     *
     * @throws ValidationException
     */
    public function assertPrefillable(DocumentTemplate $template): void
    {
        try {
            $this->open($template);
        } catch (Throwable) {
            throw ValidationException::withMessages(['prefill_map' => 'This printable PDF cannot be pre-filled (it is encrypted or unsupported). '
                .'Remove the pre-fill map, or upload an unencrypted copy of the form.']);
        }
    }

    /**
     * Builds the pre-filled PDF for one applicant document.
     */
    public function render(ApplicantDocument $document): string
    {
        $template = $document->template;
        $applicant = $document->applicant;
        $answers = $document->form_data ?? [];
        $fields = $template->fields->keyBy('field_key');

        return $this->build($template, function (string $source, ?string $format) use ($applicant, $answers, $fields): string {
            return match (true) {
                $source === 'applicant.full_name' => $applicant->fullName(),
                $source === 'applicant.first_name' => $applicant->first_name,
                $source === 'applicant.last_name' => $applicant->last_name,
                $source === 'applicant.email' => $applicant->email,
                str_starts_with($source, 'text:') => substr($source, 5),
                str_starts_with($source, 'check:'), str_starts_with($source, 'circle:') => $this->checkValue($source, $answers),
                str_starts_with($source, 'field:') => $this->fieldValue($fields->get(substr($source, 6)), $answers[substr($source, 6)] ?? null, $format),
                default => '',
            };
        });
    }

    /**
     * HR preview with clearly fake sample values, to check alignment.
     */
    public function renderSample(DocumentTemplate $template): string
    {
        $samples = ['date' => '1990-01-15', 'ssn' => '000-00-0000', 'email' => 'sample@example.com', 'phone' => '555-0100', 'number' => '123'];
        $fields = $template->fields->keyBy('field_key');

        return $this->build($template, function (string $source, ?string $format) use ($fields, $samples): string {
            if (str_starts_with($source, 'applicant.')) {
                return ['applicant.full_name' => 'SAMPLE Applicant-Name', 'applicant.first_name' => 'SAMPLE',
                    'applicant.last_name' => 'Applicant-Name', 'applicant.email' => 'sample@example.com'][$source];
            }
            if (str_starts_with($source, 'text:')) {
                return substr($source, 5);
            }
            if (str_starts_with($source, 'check:') || str_starts_with($source, 'circle:')) {
                return 'X';
            }
            $field = $fields->get(substr($source, 6));

            return $this->fieldValue($field, $samples[$field?->type] ?? 'SAMPLE '.mb_substr((string) $field?->label, 0, 30), $format);
        });
    }

    /**
     * @param  callable(string, ?string): string  $resolve
     */
    private function build(DocumentTemplate $template, callable $resolve): string
    {
        [$pdf, $pageCount] = $this->open($template);
        $byPage = collect($template->prefill_map ?? [])->groupBy('page');

        for ($p = 1; $p <= $pageCount; $p++) {
            $tpl = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            foreach ($byPage->get($p, []) as $entry) {
                $text = $this->latin1(trim($resolve($entry['source'], $entry['format'] ?? null)));
                if ($text === '') {
                    continue;
                }
                // "Circle one" options: draw a box around the printed word.
                if (str_starts_with($entry['source'], 'circle:')) {
                    $pdf->SetDrawColor(0, 0, 0);
                    $pdf->SetLineWidth(0.9);
                    $pdf->Rect((float) $entry['x'], (float) $entry['y'], (float) $entry['w'], (float) $entry['h']);

                    continue;
                }
                $fontSize = (float) ($entry['size'] ?? 10);
                $pdf->SetFont('Helvetica', '', $fontSize);
                $pdf->SetTextColor(0, 0, 0);
                // Shrink long values to fit the box instead of overflowing it.
                if (isset($entry['max_width'])) {
                    while ($fontSize > 6 && $pdf->GetStringWidth($text) > $entry['max_width']) {
                        $fontSize -= 0.5;
                        $pdf->SetFontSize($fontSize);
                    }
                }
                $pdf->Text((float) $entry['x'], (float) $entry['y'], $text);
            }
        }

        $pdf->SetCreator('Onboarding Portal');
        $pdf->SetTitle($template->name);

        return $pdf->Output('S');
    }

    /**
     * @return array{0: Fpdi, 1: int}
     */
    private function open(DocumentTemplate $template): array
    {
        $bytes = Storage::disk((string) config('portal.storage.documents_disk'))->get((string) $template->print_asset_path);
        if ($bytes === null || $bytes === '') {
            throw new \RuntimeException('Printable form missing.');
        }

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $count = $pdf->setSourceFile(StreamReader::createByString($bytes));

        return [$pdf, $count];
    }

    private function fieldValue(mixed $field, mixed $value, ?string $format): string
    {
        if ($value === null || $value === '' || $field === null) {
            return '';
        }

        if ($field->type === 'date' && $format !== null) {
            try {
                return Carbon::createFromFormat('Y-m-d', (string) $value)->format($format);
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if (in_array($field->type, ['checkbox', 'attestation'], true)) {
            return $value ? 'X' : '';
        }

        return (string) $this->types->normalizeValue($field, $value);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function checkValue(string $source, array $answers): string
    {
        [$key, $expected] = explode('=', substr($source, strpos($source, ':') + 1), 2);

        return (string) ($answers[$key] ?? '') === $expected ? 'X' : '';
    }

    /**
     * FPDF core fonts are Latin-1; transliterate anything else.
     */
    private function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);

        return $converted === false ? (preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '') : $converted;
    }
}
