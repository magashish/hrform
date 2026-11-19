<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Templates\TemplateService;
use Database\Seeders\DefaultTemplatesSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Applies the client's confirmed settings for Form I-9 and Form SSA-89
 * (client/HR-consultant guidance received 2026-11) to existing templates:
 * wet signature for both, I-9 edition/expiry and instructions, and SSA-89
 * online questions + pre-fill positions. Printable PDFs are not touched:
 * HR uploads them on each template.
 */
class ApplyClientFormSettings extends Command
{
    protected $signature = 'portal:templates:apply-client-forms
        {--dry-run : Show what would change}
        {--pdfs= : Folder holding the client\'s blank PDFs (e.g. Last_Name_First_Name_Emp_Data.pdf) to install as printable forms}';

    protected $description = 'Configure the client\'s onboarding templates (I-9, SSA-89 and the pre-hire packet forms)';

    public function handle(AuditLogger $audit, TemplateService $templates): int
    {
        $definitions = DefaultTemplatesSeeder::allDefinitions();
        $pdfDir = $this->option('pdfs') ? rtrim((string) $this->option('pdfs'), '/') : null;
        if ($pdfDir !== null && ! is_dir($pdfDir)) {
            $this->error("Folder not found: {$pdfDir}");

            return self::FAILURE;
        }

        foreach ($definitions as $code => $def) {
            $template = DocumentTemplate::firstOrNew(['code' => $code]);
            $verb = $template->exists ? 'Updating' : 'Creating';
            $this->info("{$verb} {$code}: {$def['template']['name']}");

            if ($this->option('dry-run')) {
                continue;
            }

            DB::transaction(function () use ($template, $def, $audit) {
                // Keep HR's printable PDF and ordering; set everything the guidance defines.
                $template->fill(collect($def['template'])->except(['sort_order', 'auto_assign', 'max_size_kb'])->all());
                if (! $template->exists) {
                    $template->fill(collect($def['template'])->only(['sort_order', 'auto_assign', 'max_size_kb'])->all());
                }
                $template->prefill_map = null; // re-applied after fields exist
                $template->save();

                foreach ($def['fields'] as $field) {
                    $template->fields()->updateOrCreate(['field_key' => $field['field_key']], $field);
                }

                // Pre-fill only applies once a (non-encrypted) printable PDF is uploaded.
                $template->forceFill(['prefill_map' => $def['prefill_map']])->save();

                $audit->record('template.updated', $template, [
                    'template' => $template->code,
                    'fields' => 'client_form_settings',
                ], actorType: AuditEvent::ACTOR_SYSTEM);
            });

            // Install the client's blank form, if supplied (validated like any upload).
            $source = $pdfDir !== null && isset($def['source_pdf']) ? $pdfDir.'/'.$def['source_pdf'] : null;
            if ($source !== null && is_file($source)) {
                $operator = User::query()->orderBy('id')->first();
                if (! $operator) {
                    $this->warn('  No HR user exists yet: create one, then re-run to install PDFs.');

                    continue;
                }
                $templates->uploadPrintAsset($template->fresh(), new UploadedFile($source, basename($source), 'application/pdf', null, true), $operator);
                $this->line('  Installed printable form '.basename($source));
            } elseif ($source !== null) {
                $this->warn('  Not found: '.basename($source));
            }
        }

        $this->newLine();
        $this->line('Next: in HR → Documents, check each template has its printable PDF (I-9, SSA-89 and any not installed with --pdfs),');
        $this->line('then use "Preview pre-filled PDF" on each template to check the alignment.');
        $this->warn('Documents already assigned to applicants keep their previous settings; new assignments use the new ones.');

        return self::SUCCESS;
    }
}
