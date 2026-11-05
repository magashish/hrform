<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * HR-editable settings stored in the database, with defaults from config.
 * Only keys defined here can be stored, and every value is validated.
 */
class SettingsService
{
    private const CACHE_KEY = 'portal.settings';

    /**
     * @return array<string, array{label: string, rules: array<int, mixed>, default: mixed, help?: string, group: string}>
     */
    public function definitions(): array
    {
        $maxRetention = config('portal.retention.allow_over_max') ? 3650 : (int) config('portal.retention.max_days', 60);

        return [
            'filename_pattern' => [
                'group' => 'Files', 'label' => 'Filename pattern',
                'help' => 'Tokens: {last_name} {first_name} {document_type} {date} {requirement}. Example: {last_name}_{first_name}_{document_type}_{date}',
                'rules' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9_\-\{\} ]+$/'],
                'default' => config('portal.files.filename_pattern'),
            ],
            'filename_date_format' => [
                'group' => 'Files', 'label' => 'Filename date format',
                'help' => 'PHP date format, e.g. Ymd (20261105) or Y-m-d.',
                'rules' => ['required', 'string', 'in:Ymd,Y-m-d,mdY,m-d-Y,dmY,d-m-Y'],
                'default' => config('portal.files.filename_date_format'),
            ],
            'default_max_upload_kb' => [
                'group' => 'Files', 'label' => 'Default maximum upload size (KB)',
                'rules' => ['required', 'integer', 'min:100', 'max:'.(int) config('portal.files.hard_max_upload_kb')],
                'default' => config('portal.files.default_max_upload_kb'),
            ],
            'reminder_days_before_expiry' => [
                'group' => 'Reminders', 'label' => 'Remind applicants this many days before their invitation expires',
                'help' => 'Comma-separated, e.g. 3,1. Leave empty to disable.',
                'rules' => ['nullable', 'string', 'max:50', 'regex:/^\s*(\d{1,2}\s*(,\s*\d{1,2}\s*)*)?$/'],
                'default' => config('portal.reminders.days_before_expiry'),
            ],
            'reminder_incomplete_every_days' => [
                'group' => 'Reminders', 'label' => 'Remind applicants with incomplete onboarding every N days (0 = off)',
                'rules' => ['required', 'integer', 'min:0', 'max:30'],
                'default' => config('portal.reminders.incomplete_every_days'),
            ],
            'hr_notification_recipients' => [
                'group' => 'Notifications', 'label' => 'HR notification recipients',
                'help' => 'Leave empty to notify all active HR users, or enter comma-separated email addresses (e.g. a shared HR mailbox).',
                'rules' => ['nullable', 'string', 'max:1000'],
                'default' => config('portal.notifications.hr_recipients'),
            ],
            'hr_notification_events' => [
                'group' => 'Notifications', 'label' => 'Notify HR when',
                'rules' => ['array'],
                'default' => config('portal.notifications.hr_events'),
            ],
            'retention_days' => [
                'group' => 'Retention', 'label' => 'Delete packet this many days after HR marks it transferred',
                'help' => 'Maximum '.$maxRetention.' days.',
                'rules' => ['required', 'integer', 'min:1', 'max:'.$maxRetention],
                'default' => config('portal.retention.days'),
            ],
            'mail_invitation_subject' => [
                'group' => 'Email wording', 'label' => 'Invitation email subject',
                'rules' => ['required', 'string', 'max:150'], 'default' => 'Your onboarding invitation',
            ],
            'mail_invitation_intro' => [
                'group' => 'Email wording', 'label' => 'Invitation email opening paragraph',
                'rules' => ['required', 'string', 'max:2000'],
                'default' => 'You have been invited to complete your onboarding paperwork through our secure portal.',
            ],
            'mail_reminder_subject' => [
                'group' => 'Email wording', 'label' => 'Reminder email subject',
                'rules' => ['required', 'string', 'max:150'], 'default' => 'Reminder: please complete your onboarding',
            ],
            'mail_reminder_intro' => [
                'group' => 'Email wording', 'label' => 'Reminder email opening paragraph',
                'rules' => ['required', 'string', 'max:2000'],
                'default' => 'This is a friendly reminder that your onboarding paperwork is not yet complete.',
            ],
            'mail_correction_subject' => [
                'group' => 'Email wording', 'label' => 'Correction-request email subject',
                'rules' => ['required', 'string', 'max:150'], 'default' => 'Action needed on your onboarding documents',
            ],
            'mail_correction_intro' => [
                'group' => 'Email wording', 'label' => 'Correction-request email opening paragraph',
                'rules' => ['required', 'string', 'max:2000'],
                'default' => 'HR has reviewed your onboarding documents and needs you to update one or more items.',
            ],
        ];
    }

    public function get(string $key): mixed
    {
        $definitions = $this->definitions();

        if (! isset($definitions[$key])) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : $definitions[$key]['default'];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];
        foreach (array_keys($this->definitions()) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(array $input, User $actor): void
    {
        $definitions = $this->definitions();
        $input = array_intersect_key($input, $definitions);
        $rules = [];
        foreach ($input as $key => $_) {
            $rules[$key] = $definitions[$key]['rules'];
        }
        $rules['hr_notification_events.*'] = ['string', 'in:'.implode(',', array_keys(config('portal.notifications.available_hr_events')))];

        $validated = Validator::make($input, $rules)->validate();

        if (isset($validated['hr_notification_recipients']) && trim((string) $validated['hr_notification_recipients']) !== '') {
            $emails = array_filter(array_map('trim', explode(',', (string) $validated['hr_notification_recipients'])));
            Validator::make(['emails' => $emails], ['emails.*' => ['email:strict']])->validate();
            $validated['hr_notification_recipients'] = implode(',', $emails);
        }

        $changed = [];
        DB::transaction(function () use ($validated, $actor, &$changed) {
            foreach ($validated as $key => $value) {
                if (str_contains((string) $key, '.')) {
                    continue;
                }
                if ($this->get($key) == $value) {
                    continue;
                }
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value), 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()],
                );
                $changed[] = $key;
            }
        });

        Cache::forget(self::CACHE_KEY);

        if ($changed !== []) {
            app(AuditLogger::class)->record('settings.updated', metadata: ['keys' => implode(',', $changed)], hrActor: $actor);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return DB::table('settings')->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))
                ->all();
        });
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
