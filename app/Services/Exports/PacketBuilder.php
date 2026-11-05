<?php

namespace App\Services\Exports;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Services\Documents\FieldTypeRegistry;
use App\Services\Documents\FilenameGenerator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds a ZIP of one applicant's current documents.
 *
 * Only files reached through $applicant->documents are included, and every
 * entry name is produced by FilenameGenerator (never from stored paths or
 * user input), so archive paths cannot traverse or collide.
 *
 * Layout:
 *   {Last}_{First}_Packet_{date}/
 *     01_{DocType}/{generated filename}
 *     01_{DocType}/{Last}_{First}_{DocType}_FormAnswers.html   (online forms)
 *     MANIFEST.txt                                             (SHA-256 of every file)
 */
class PacketBuilder
{
    public function __construct(
        private readonly FilenameGenerator $names,
        private readonly FieldTypeRegistry $types,
    ) {}

    /**
     * @return array{path: string, download_name: string, file_count: int, size: int}
     */
    public function build(Applicant $applicant, string $exportsDisk): array
    {
        $root = $this->names->token($applicant->last_name).'_'.$this->names->token($applicant->first_name)
            .'_Packet_'.now()->timezone(config('app.display_timezone'))->format('Ymd');
        $root = trim(preg_replace('/_{2,}/', '_', $root) ?? 'Packet', '_') ?: 'Packet';

        $relative = 'zips/'.bin2hex(random_bytes(16)).'.zip';
        $storage = Storage::disk($exportsDisk);
        $storage->makeDirectory('zips');
        $absolute = $storage->path($relative);

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create archive.');
        }

        $manifest = [];
        $count = 0;
        $documents = $applicant->documents()->with(['template.fields', 'currentVersion', 'currentSupportingFiles'])->get()
            ->sortBy(fn (ApplicantDocument $d) => [$d->template->sort_order, $d->template->name])->values();

        foreach ($documents as $i => $document) {
            $folder = $root.'/'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).'_'.($this->names->token($document->template->document_type_label) ?: 'Document');
            $taken = [];

            $files = [];
            if ($document->currentVersion) {
                $files[] = $document->currentVersion;
            }
            foreach ($document->currentSupportingFiles as $supporting) {
                $files[] = $supporting;
            }

            foreach ($files as $file) {
                $contents = Storage::disk($file->disk)->get($file->storage_path);
                if ($contents === null) {
                    throw new RuntimeException('A stored file is missing.');
                }
                if (! hash_equals($file->sha256, hash('sha256', $contents))) {
                    throw new RuntimeException('A stored file failed its integrity check.');
                }

                $entry = $folder.'/'.$this->names->unique($file->generated_filename, $taken);
                $zip->addFromString($entry, $contents);
                $manifest[] = $file->sha256.'  '.$entry;
                $count++;
            }

            if ($document->hasForm() && $document->form_data) {
                $html = $this->formAnswersHtml($applicant, $document);
                $name = $this->names->forDocument($applicant, $document->template->document_type_label.'-FormAnswers', 'html', $document->submitted_at ?? now());
                $entry = $folder.'/'.$this->names->unique($name, $taken);
                $zip->addFromString($entry, $html);
                $manifest[] = hash('sha256', $html).'  '.$entry;
                $count++;
            }
        }

        $zip->addFromString($root.'/MANIFEST.txt', "SHA-256 checksums\n\n".implode("\n", $manifest)."\n");
        $zip->close();

        return [
            'path' => $relative,
            'download_name' => $root.'.zip',
            'file_count' => $count,
            'size' => (int) filesize($absolute),
        ];
    }

    private function formAnswersHtml(Applicant $applicant, ApplicantDocument $document): string
    {
        $rows = '';
        foreach ($document->template->fields as $field) {
            $value = $this->types->display($field, ($document->form_data ?? [])[$field->field_key] ?? null, reveal: true);
            $rows .= '<tr><th>'.e($field->label).'</th><td>'.e($value).'</td></tr>';
        }

        $submitted = $document->submitted_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i T') ?? 'Not submitted';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'.e($document->template->name).'</title>'
            .'<style>body{font-family:Arial,sans-serif;margin:32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;text-align:left;vertical-align:top}th{width:40%;background:#f5f5f5}</style>'
            .'</head><body><h1>'.e($document->template->name).'</h1>'
            .'<p>Applicant: '.e($applicant->fullName()).'<br>Submitted: '.e($submitted).'</p>'
            .'<p><strong>CONFIDENTIAL</strong>: contains personal information.</p>'
            .'<table>'.$rows.'</table></body></html>';
    }
}
