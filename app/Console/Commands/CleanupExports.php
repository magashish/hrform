<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\ExportArchive;
use App\Services\Audit\AuditLogger;
use App\Services\Files\PrivateFileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupExports extends Command
{
    protected $signature = 'portal:exports:cleanup';

    protected $description = 'Delete expired ZIP exports and stale verification codes';

    public function handle(PrivateFileStore $files, AuditLogger $audit): int
    {
        $count = 0;

        ExportArchive::where(fn ($q) => $q->where('expires_at', '<=', now())
            ->orWhere(fn ($p) => $p->where('status', ExportArchive::PENDING)->where('created_at', '<=', now()->subDay())))
            ->whereIn('status', [ExportArchive::READY, ExportArchive::PENDING, ExportArchive::FAILED])
            ->each(function (ExportArchive $export) use ($files, &$count) {
                if ($export->storage_path && ! $files->delete((string) $export->disk, $export->storage_path)) {
                    return;
                }
                $export->forceFill(['status' => ExportArchive::EXPIRED, 'storage_path' => null])->save();
                $count++;
            });

        // Verification codes are only useful for minutes.
        DB::table('applicant_otps')->where('created_at', '<', now()->subDay())->delete();

        if ($count > 0) {
            $audit->record('exports.cleaned', metadata: ['count' => $count], actorType: AuditEvent::ACTOR_SYSTEM);
        }

        $this->info("Expired exports removed: {$count}");

        return self::SUCCESS;
    }
}
