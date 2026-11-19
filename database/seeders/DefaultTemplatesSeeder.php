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
                    // Client: "the I-9 is obtained on day 1 of hire".
                    'default_stage' => 'day_one',
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
                    'default_stage' => 'initial',
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

    /**
     * Buffalo Valley pre-hire packet forms (received 2026-11-19), keyed by
     * template code, with the client's file names: "Last Name, First Name <label>".
     *
     * @return array<string, array{template: array<string, mixed>, fields: list<array<string, mixed>>, prefill_map: ?list<array<string, mixed>>, source_pdf: string}>
     */
    public static function packetDefinitions(): array
    {
        $common = [
            'required_by_default' => true,
            'auto_assign' => true,
            'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_size_kb' => (int) config('portal.files.default_max_upload_kb', 10240),
            'active' => true,
            'form_expires_on' => null,
        ];
        $wet = $common + ['input_mode' => 'upload', 'signature_mode' => 'wet_signature_upload'];
        $printSign = 'Download your form (your name is already filled in), print every page and complete it clearly in black ink.';
        $na = 'If a page does not apply to you (for example, you have no college degree or no current employer), write "N/A" on it, sign it and upload it anyway, unless HR tells you otherwise.';

        $nameAt = fn (int $page, float $x, float $y, float $width) => ['page' => $page, 'x' => $x, 'y' => $y, 'source' => 'applicant.full_name', 'size' => 10, 'max_width' => $width];

        // Reference form: three references entered online; options are "circled" with a box.
        $refFields = [];
        $refMap = [
            ['page' => 1, 'x' => 128, 'y' => 100, 'source' => 'applicant.full_name', 'size' => 10, 'max_width' => 215],
            ['page' => 1, 'x' => 398, 'y' => 100, 'source' => 'applicant.email', 'size' => 9, 'max_width' => 190],
        ];
        $rows = [1 => [152, 170, 160.8, 172.3, 62, 440], 2 => [352, 370, 360.8, 372.3, 64, 420], 3 => [535, 553, 543.8, 555.3, 64, 420]];
        $relationships = ['Friend' => [232.4, 41.1], 'Family' => [278.0, 40.1], 'Coworker' => [322.5, 64.1], 'Employer' => [390.8, 59.5]];
        foreach ($rows as $n => [$nameY, $yearsY, $relTop, $contactTop, $nameX, $phoneX]) {
            $refFields[] = ['field_key' => "ref{$n}_name", 'label' => "Reference {$n}: full name", 'type' => 'text', 'config' => ['max_length' => 80], 'required' => true, 'sensitive' => false, 'sort_order' => $n * 10 + 1];
            $refFields[] = ['field_key' => "ref{$n}_phone", 'label' => "Reference {$n}: phone number", 'type' => 'phone', 'config' => null, 'required' => true, 'sensitive' => false, 'sort_order' => $n * 10 + 2];
            $refFields[] = ['field_key' => "ref{$n}_years", 'label' => "Reference {$n}: years known", 'type' => 'number', 'config' => ['min' => 0, 'max' => 80, 'integer_only' => true], 'required' => true, 'sensitive' => false, 'sort_order' => $n * 10 + 3];
            $refFields[] = ['field_key' => "ref{$n}_relationship", 'label' => "Reference {$n}: relationship", 'type' => 'radio', 'config' => ['options' => array_keys($relationships)], 'required' => true, 'sensitive' => false, 'sort_order' => $n * 10 + 4];
            $refFields[] = ['field_key' => "ref{$n}_contact", 'label' => "Reference {$n}: how will they respond?", 'type' => 'radio', 'config' => ['options' => ['HR should call them', 'They will send a reference letter']], 'required' => true, 'sensitive' => false, 'sort_order' => $n * 10 + 5];

            $refMap[] = ['page' => 1, 'x' => $nameX, 'y' => $nameY, 'source' => "field:ref{$n}_name", 'size' => 10, 'max_width' => 230];
            $refMap[] = ['page' => 1, 'x' => $phoneX, 'y' => $nameY, 'source' => "field:ref{$n}_phone", 'size' => 10, 'max_width' => 150];
            $refMap[] = ['page' => 1, 'x' => 110, 'y' => $yearsY, 'source' => "field:ref{$n}_years", 'size' => 10, 'max_width' => 55];
            foreach ($relationships as $option => [$x, $w]) {
                $refMap[] = ['page' => 1, 'x' => $x, 'y' => $relTop - 1.5, 'w' => $w, 'h' => 14, 'source' => "circle:ref{$n}_relationship={$option}"];
            }
            // "Will reference need to be called? Y / N" and "Will reference provide reference letter? Y / N"
            $refMap[] = ['page' => 1, 'x' => 194.7, 'y' => $contactTop - 1.5, 'w' => 10.6, 'h' => 14, 'source' => "circle:ref{$n}_contact=HR should call them"];
            $refMap[] = ['page' => 1, 'x' => 494.8, 'y' => $contactTop - 1.5, 'w' => 11.2, 'h' => 14, 'source' => "circle:ref{$n}_contact=HR should call them"];
            $refMap[] = ['page' => 1, 'x' => 212.3, 'y' => $contactTop - 1.5, 'w' => 11.2, 'h' => 14, 'source' => "circle:ref{$n}_contact=They will send a reference letter"];
            $refMap[] = ['page' => 1, 'x' => 479.8, 'y' => $contactTop - 1.5, 'w' => 10.7, 'h' => 14, 'source' => "circle:ref{$n}_contact=They will send a reference letter"];
        }

        return [
            'EMPDATA' => [
                'source_pdf' => 'Last_Name_First_Name_Emp_Data.pdf',
                'template' => $wet + [
                    'code' => 'EMPDATA', 'name' => 'Biographical Data Sheet (Emp Data)', 'document_type_label' => 'Emp Data',
                    'description' => 'Background-investigation details: residences and employment for the last seven years, education, emergency contacts.',
                    'instructions' => implode("\n\n", [
                        $printSign,
                        'List your residences and employment for the last SEVEN years. If you need more space, use an additional sheet and upload it below.',
                        'Sign and date page 2 by hand in ink, then scan or photograph both pages clearly and upload them.',
                    ]),
                    'supporting_requirements' => [['key' => 'additional_sheet', 'label' => 'Additional sheet (if needed)', 'required' => false]],
                    'default_stage' => 'initial', 'form_edition' => null, 'sort_order' => 30,
                ],
                'fields' => [],
                'prefill_map' => [$nameAt(1, 75, 179, 290)],
            ],
            'EDVER' => [
                'source_pdf' => 'Last_Name_First_Name_Ed_Ver.pdf',
                'template' => $wet + [
                    'code' => 'EDVER', 'name' => 'Education Verification Consent: Degree and High School/GED (Ed Ver)', 'document_type_label' => 'Ed Ver',
                    'description' => 'Two consent forms in one file: degree verification and high school diploma/GED verification.',
                    'instructions' => implode("\n\n", [
                        $printSign,
                        'Page 1 is the Degree Verification consent; page 2 is the High School Diploma or GED consent. Complete and sign BOTH pages by hand in ink.',
                        $na,
                        'Scan or photograph both pages clearly and upload them as one file.',
                    ]),
                    'default_stage' => 'initial', 'form_edition' => null, 'sort_order' => 40,
                ],
                'fields' => [],
                'prefill_map' => [$nameAt(1, 52, 144, 262), $nameAt(2, 40, 196, 268)],
            ],
            'EMPVER' => [
                'source_pdf' => 'Last_Name_First_Name_Emp_Ver.pdf',
                'template' => $wet + [
                    'code' => 'EMPVER', 'name' => 'Employment Verification Consent: Current and Previous Employer (Emp Ver)', 'document_type_label' => 'Emp Ver',
                    'description' => 'Two consent forms in one file: current employer and previous employer.',
                    'instructions' => implode("\n\n", [
                        $printSign,
                        'Page 1 is for your current employer and page 2 for your previous employer. Complete and sign BOTH pages by hand in ink.',
                        $na,
                        'Scan or photograph both pages clearly and upload them as one file.',
                    ]),
                    'default_stage' => 'initial', 'form_edition' => null, 'sort_order' => 50,
                ],
                'fields' => [],
                'prefill_map' => [$nameAt(1, 52, 163, 168), $nameAt(2, 52, 163, 168)],
            ],
            'REFERENCE' => [
                'source_pdf' => 'Last_Name_First_Name_Reference.pdf',
                'template' => $common + [
                    'code' => 'REFERENCE', 'name' => 'Reference Verification and Check (Reference)', 'document_type_label' => 'Reference',
                    'input_mode' => 'online_form', 'signature_mode' => 'none',
                    'description' => 'List three references online. No printing or signature needed.',
                    'instructions' => implode("\n\n", [
                        'List three references. Only ONE family member may be listed, and at least ONE reference must have known you for five (5) years or more.',
                        'Reference letters are accepted. A letter must state the relationship and how many years the person has known you, and must be received within 3 business days. You can upload letters below.',
                        'When you submit, your answers are typed onto the Reference Verification form and sent to HR.',
                    ]),
                    'supporting_requirements' => [['key' => 'reference_letters', 'label' => 'Reference letter(s)', 'required' => false]],
                    'default_stage' => 'initial', 'form_edition' => 'REV 6/18/2025', 'sort_order' => 60,
                ],
                'fields' => $refFields,
                'prefill_map' => $refMap,
            ],
            'USERACCESS' => [
                'source_pdf' => 'Last_Name_First_Name_User.pdf',
                'template' => $wet + [
                    'code' => 'USERACCESS', 'name' => 'User Access Request & Compliance Form (User)', 'document_type_label' => 'User',
                    'description' => 'System access request for your first day.',
                    'instructions' => implode("\n\n", [
                        $printSign,
                        'Complete the User Information section. Leave the access and approval sections for HR or your manager unless told otherwise.',
                        'Sign and date on the "User Signature" line by hand in ink, then scan or photograph the page and upload it.',
                    ]),
                    'default_stage' => 'day_one', 'form_edition' => 'REV 11-11-2026', 'sort_order' => 70,
                ],
                'fields' => [],
                'prefill_map' => [$nameAt(1, 188, 218, 395)],
            ],
        ];
    }

    /**
     * Every template the client has supplied guidance for.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function allDefinitions(): array
    {
        return self::definitions() + self::packetDefinitions();
    }

    public function run(): void
    {
        foreach (self::allDefinitions() as $code => $def) {
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
