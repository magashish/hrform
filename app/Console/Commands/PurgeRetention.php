<?php

namespace App\Console\Commands;

use App\Services\Retention\RetentionService;
use Illuminate\Console\Command;

class PurgeRetention extends Command
{
    protected $signature = 'portal:retention:purge
        {--dry-run : Report what would be deleted without deleting anything}
        {--force : Purge even when RETENTION_AUTO_PURGE is false (manual run)}';

    protected $description = 'Report on, or permanently delete, applicant packets whose retention period has ended';

    public function handle(RetentionService $retention): int
    {
        $auto = (bool) config('portal.retention.auto_purge');
        $dryRun = $this->option('dry-run') || (! $auto && ! $this->option('force'));

        $report = $retention->report();
        $this->line("Due for deletion: {$report['due']}  |  Held (legal hold): {$report['held']}");

        if ($dryRun) {
            $retention->run(true, $this->option('force') ? 'manual' : 'scheduled');
            $this->warn('Dry run: nothing was deleted.'.($auto ? '' : ' Automatic purge is disabled (RETENTION_AUTO_PURGE=false).'));

            return self::SUCCESS;
        }

        $result = $retention->run(false, $this->option('force') ? 'manual' : 'scheduled');
        $this->info("Purged: {$result['purged']}  Failed: {$result['failed']}");
        $this->line('Reminder: deleted data remains in existing backups until those backups expire.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
