<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Notifications\HrAlert;
use App\Services\Notifications\HrNotifier;
use Illuminate\Console\Command;

/**
 * Weekly reminder to HR when an official form edition (e.g. Form I-9
 * "Expires 05/31/2027") is expired or about to expire, so the template's
 * printable PDF is replaced as soon as the agency publishes a new revision.
 */
class CheckFormExpiry extends Command
{
    protected $signature = 'portal:templates:check-expiry';

    protected $description = 'Warn HR about official form editions that are expired or expiring soon';

    public function handle(HrNotifier $notifier): int
    {
        $templates = DocumentTemplate::query()->expiringSoon()->orderBy('form_expires_on')->get();

        foreach ($templates as $t) {
            $this->line("{$t->name}: edition ".($t->form_edition ?? 'n/a').', expires '.$t->form_expires_on->toDateString());
        }

        if ($templates->isNotEmpty()) {
            $names = $templates->map(fn ($t) => $t->document_type_label.' ('.($t->expiryState() === 'expired' ? 'expired' : 'expires').' '.$t->form_expires_on->format('M j, Y').')')->implode(', ');
            $notifier->notify('form_expiring', new HrAlert(
                'Official form edition expiring',
                'These onboarding forms need a new official edition: '.$names.'. Upload the current form on the template and update its edition and expiry date.',
                route('hr.templates.index'),
            ));
        }

        $this->info('Expiring or expired forms: '.$templates->count());

        return self::SUCCESS;
    }
}
