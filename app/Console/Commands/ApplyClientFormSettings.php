<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\DocumentTemplate;
use App\Services\Audit\AuditLogger;
use Database\Seeders\DefaultTemplatesSeeder;
use Illuminate\Console\Command;
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
    protected $signature = 'portal:templates:apply-client-forms {--dry-run : Show what would change}';

    protected $description = 'Configure the I-9 and SSA-89 templates per the client guidance (wet signature, SSA-89 pre-fill)';

    public function handle(AuditLogger $audit): int
    {
        $definitions = DefaultTemplatesSeeder::definitions();

        foreach (['I9', 'SSA89'] as $code) {
            $def = $definitions[$code];
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
        }

        $this->newLine();
        $this->line('Next: in HR → Documents, upload the client\'s SSA-89 (with company details) and the current official I-9 PDF on each template,');
        $this->line('then use "Preview pre-filled PDF" on the SSA-89 template to check the alignment.');
        $this->warn('Documents already assigned to applicants keep their previous settings; new assignments use the new ones.');

        return self::SUCCESS;
    }
}
