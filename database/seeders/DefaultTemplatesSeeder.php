<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Safe to run in production:
 *   php artisan db:seed --class=DefaultTemplatesSeeder --force
 *
 * Creates the Form I-9 and Form SSA-89 templates per the client's written
 * guidance (2026-11):
 *  - I-9: the USCIS fillable PDF does not meet DHS standards for electronic
 *    signature, and no compliant e-signature system is in place, so it is
 *    printed and signed by hand. The official PDF is encrypted and cannot be
 *    pre-filled; applicants may type into it before printing.
 *  - SSA-89: SSA requires a handwritten signature. The applicant's name, date
 *    of birth and SSN are typed onto the client's copy (company details already
 *    completed), which the applicant prints, signs by hand and uploads.
 *
 * No PDFs are bundled: HR uploads the current form on each template. Existing
 * templates with the same code are left untouched (use
 * `php artisan portal:templates:apply-client-forms` to update them).
 */
class DefaultTemplatesSeeder extends Seeder
{
    /**
     * @return array<string, array{template: array<string, mixed>, fields: list<array<string, mixed>>, prefill_map: ?list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        $common = [
            'signature_mode' => 'wet_signature_upload',
            'required_by_default' => true,
            'auto_assign' => true,
            'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size_kb' => (int) config('portal.files.default_max_upload_kb', 10240),
            'active' => true,
        ];

        return [
            'I9' => [
                'template' => $common + [
                    'code' => 'I9',
                    'name' => 'Form I-9, Employment Eligibility Verification (Section 1)',
                    'document_type_label' => 'I-9',
                    'form_edition' => '01/20/25',
                    'form_expires_on' => '2027-05-31',
                    'input_mode' => 'upload',
                    'description' => 'Complete Section 1, print pages 1–2, sign by hand and upload.',
                    'instructions' => implode("\n\n", [
                        'Download the official Form I-9 below. You may type your answers into Section 1 on your computer, or print it and complete Section 1 by hand in ink.',
                        'Do NOT type your name or anything else in the "Signature of Employee" box. Federal rules require this form to be printed and signed by hand: typed or electronic signatures are not accepted.',
                        'Print pages 1 and 2, sign and date Section 1 by hand in ink, then scan or photograph both pages clearly and upload them below.',
                        'Do not complete Section 2: your employer completes it after examining your identity and employment authorization documents in person. Do not upload copies of those documents here unless HR asks you to.',
                        'If someone helped you complete Section 1 (a preparer or translator), they must also complete and sign Supplement A (page 3); upload that page too.',
                        'Section 1 must be completed no later than your first day of employment, and not before you have accepted the job offer.',
                    ]),
                    'sort_order' => 10,
                ],
                'fields' => [],
                'prefill_map' => null,
            ],
            'SSA89' => [
                'template' => $common + [
                    'code' => 'SSA89',
                    'name' => 'Form SSA-89, Authorization for the SSA to Release SSN Verification',
                    'document_type_label' => 'SSA-89',
                    'form_edition' => '05-2025',
                    'form_expires_on' => null,
                    'input_mode' => 'online_form',
                    'description' => 'Enter your details, then print the pre-filled form, sign by hand and upload.',
                    'instructions' => implode("\n\n", [
                        'Enter your name exactly as it appears on your Social Security card, your date of birth and your Social Security number, then save.',
                        'Download your pre-filled form. The company details are already completed.',
                        'Print it, tick the reason for consent, and sign and date it by hand in ink. Social Security requires a handwritten signature: typed or electronic signatures are not accepted.',
                        'Scan or photograph the signed form clearly and upload it below.',
                    ]),
                    'sort_order' => 20,
                ],
                'fields' => [
                    ['field_key' => 'printed_name', 'label' => 'Full name (as shown on your Social Security card)', 'type' => 'text',
                        'config' => ['max_length' => 100, 'default_from' => 'applicant.full_name'], 'required' => true, 'sensitive' => false, 'sort_order' => 10],
                    ['field_key' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date',
                        'config' => ['not_future' => true], 'required' => true, 'sensitive' => false, 'sort_order' => 20],
                    ['field_key' => 'ssn', 'label' => 'Social Security number', 'type' => 'ssn',
                        'config' => null, 'required' => true, 'sensitive' => true, 'sort_order' => 30],
                ],
                // Positions on Form SSA-89 (05-2025), US Letter, points from the top-left.
                'prefill_map' => [
                    ['page' => 1, 'x' => 22, 'y' => 111, 'source' => 'field:printed_name', 'size' => 10, 'max_width' => 250],
                    ['page' => 1, 'x' => 292, 'y' => 111, 'source' => 'field:date_of_birth', 'format' => 'm/d/Y', 'size' => 10, 'max_width' => 115],
                    ['page' => 1, 'x' => 418, 'y' => 111, 'source' => 'field:ssn', 'size' => 10, 'max_width' => 170],
                ],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $code => $def) {
            if (DocumentTemplate::where('code', $code)->exists()) {
                continue;
            }

            $template = DocumentTemplate::create($def['template']);
            foreach ($def['fields'] as $field) {
                $template->fields()->create($field);
            }
            $template->forceFill(['prefill_map' => $def['prefill_map']])->save();
        }
    }
}
