<?php

namespace App\Http\Controllers\Hr;

use App\Enums\InputMode;
use App\Enums\SignatureMode;
use App\Http\Controllers\Controller;
use App\Models\DocumentField;
use App\Models\DocumentTemplate;
use App\Services\Documents\FieldTypeRegistry;
use App\Services\Settings\SettingsService;
use App\Services\Signatures\SignatureProviderManager;
use App\Services\Templates\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function __construct(private readonly TemplateService $templates) {}

    public function index(): View
    {
        return view('hr.templates.index', [
            'templates' => DocumentTemplate::withCount('fields')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create(SettingsService $settings, SignatureProviderManager $esign): View
    {
        return view('hr.templates.form', [
            'template' => null,
            'defaultMaxKb' => $settings->get('default_max_upload_kb'),
            'esignAvailable' => $esign->isAvailable(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = $this->templates->save(null, $this->validated($request, null), $request->user());

        return redirect()->route('hr.templates.edit', $template)->with('status', 'Template created.');
    }

    public function edit(DocumentTemplate $template, FieldTypeRegistry $types, SettingsService $settings, SignatureProviderManager $esign): View
    {
        $template->load(['fields', 'signatureApprovals.recorder']);

        return view('hr.templates.form', [
            'template' => $template,
            'fieldTypes' => FieldTypeRegistry::TYPES,
            'defaultMaxKb' => $settings->get('default_max_upload_kb'),
            'esignAvailable' => $esign->isAvailable(),
        ]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->templates->save($template, $this->validated($request, $template), $request->user());

        return back()->with('status', 'Template saved. Changes apply to documents assigned from now on; existing assignments keep their signature and input mode.');
    }

    public function addField(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'field_key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:200'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(array_keys(FieldTypeRegistry::TYPES))],
            'required' => ['sometimes', 'boolean'],
            'sensitive' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ], ['field_key.regex' => 'Use lower-case letters, numbers and underscores, starting with a letter.']);

        $this->templates->addField($template, $data, $request->only(['max_length', 'min', 'max', 'integer_only', 'not_future', 'not_past', 'options', 'statement']), $request->user());

        return back()->with('status', 'Field added.');
    }

    public function removeField(Request $request, DocumentTemplate $template, DocumentField $field): RedirectResponse
    {
        abort_unless($field->document_template_id === $template->id, 404);
        $this->templates->removeField($field, $request->user());

        return back()->with('status', 'Field removed.');
    }

    public function uploadPrintAsset(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $request->validate(['print_asset' => ['required', 'file']]);
        $this->templates->uploadPrintAsset($template, $request->file('print_asset'), $request->user());

        return back()->with('status', 'Printable form uploaded.');
    }

    public function recordApproval(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'signature_mode' => ['required', Rule::enum(SignatureMode::class)],
            'authorized_by_name' => ['required', 'string', 'max:150'],
            'approval_reference' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'authorized_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $this->templates->recordApproval($template, $data, $request->user());

        return back()->with('status', 'Signature-mode approval recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DocumentTemplate $template): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('document_templates', 'code')->ignore($template?->id)],
            'name' => ['required', 'string', 'max:150'],
            'document_type_label' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'input_mode' => ['required', Rule::enum(InputMode::class)],
            'signature_mode' => ['required', Rule::enum(SignatureMode::class)],
            'required_by_default' => ['sometimes', 'boolean'],
            'auto_assign' => ['sometimes', 'boolean'],
            'allowed_extensions' => ['required', 'array', 'min:1'],
            'allowed_extensions.*' => [Rule::in(config('portal.files.allowed_extensions'))],
            'max_size_kb' => ['required', 'integer', 'min:100', 'max:'.(int) config('portal.files.hard_max_upload_kb')],
            'supporting_requirements' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use upper-case letters, numbers, hyphens and underscores.']);

        $data['required_by_default'] = $request->boolean('required_by_default');
        $data['auto_assign'] = $request->boolean('auto_assign');
        $data['active'] = $request->boolean('active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);
        $data['allowed_extensions'] = array_values(array_unique($data['allowed_extensions']));
        $data['supporting_requirements'] = $this->parseRequirements((string) ($data['supporting_requirements'] ?? ''));

        return $data;
    }

    /**
     * One requirement per line: "Label" or "Label | optional".
     *
     * @return list<array{key: string, label: string, required: bool}>|null
     */
    private function parseRequirements(string $text): ?array
    {
        $requirements = [];
        $keys = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            $label = mb_substr($parts[0], 0, 120);
            if ($label === '') {
                continue;
            }
            $key = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii($label))) ?? '', '_'), 0, 60) ?: 'file';
            $base = $key;
            $i = 2;
            while (isset($keys[$key])) {
                $key = $base.'_'.$i++;
            }
            $keys[$key] = true;

            $requirements[] = [
                'key' => $key,
                'label' => $label,
                'required' => strtolower($parts[1] ?? '') !== 'optional',
            ];
        }

        return $requirements === [] ? null : array_slice($requirements, 0, 20);
    }
}
