<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAudit extends Command
{
    protected $signature = 'portal:audit:prune';

    protected $description = 'Delete pseudonymous audit events older than AUDIT_RETENTION_DAYS';

    public function handle(AuditLogger $audit): int
    {
        $days = (int) config('portal.audit.retention_days', 365);
        if ($days <= 0) {
            $this->warn('Audit pruning disabled.');

            return self::SUCCESS;
        }

        $count = DB::transaction(function () use ($days) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL app.audit_purge = 'on'");
            }

            return DB::table('audit_events')->where('occurred_at', '<', now()->subDays($days))->delete();
        });

        if ($count > 0) {
            $audit->record('audit.pruned', metadata: ['count' => $count, 'older_than_days' => $days], actorType: AuditEvent::ACTOR_SYSTEM);
        }

        $this->info("Audit events pruned: {$count}");

        return self::SUCCESS;
    }
}
