<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Safe to run in production:
 *   php artisan db:seed --class=DefaultTemplatesSeeder --force
 *
 * Creates I-9 and SSA-89 templates defaulted to wet signature (see
 * docs/signature-compliance-decisions.md). No official PDFs are bundled:
 * HR uploads the current official form on each template. Existing templates
 * with the same code are left untouched.
 */
class DefaultTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'code' => 'I9',
                'name' => 'Form I-9, Employment Eligibility Verification (Section 1)',
                'document_type_label' => 'I-9',
                'description' => 'Complete Section 1, sign by hand and upload.',
                'instructions' => "Download the form, complete Section 1 and sign and date it by hand in ink.\n\nScan or photograph every page clearly and upload it below.\n\nDo not complete Section 2: your employer completes it after examining your documents in person.",
                'sort_order' => 10,
            ],
            [
                'code' => 'SSA89',
                'name' => 'Form SSA-89, Authorization for the SSA to Release SSN Verification',
                'document_type_label' => 'SSA-89',
                'description' => 'Complete, sign by hand and upload.',
                'instructions' => "Download the form, complete it and sign it by hand in ink. This form requires a handwritten signature.\n\nScan or photograph it clearly and upload it below.",
                'sort_order' => 20,
            ],
        ];

        foreach ($defaults as $data) {
            if (DocumentTemplate::where('code', $data['code'])->exists()) {
                continue;
            }

            DocumentTemplate::create($data + [
                'input_mode' => 'upload',
                'signature_mode' => 'wet_signature_upload',
                'required_by_default' => true,
                'auto_assign' => true,
                'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
                'max_size_kb' => (int) config('portal.files.default_max_upload_kb', 10240),
                'active' => true,
            ]);
        }
    }
}
