<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\ExportArchive;
use App\Services\Audit\AuditLogger;
use App\Services\Exports\PacketBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BuildApplicantZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $exportId) {}

    public function handle(PacketBuilder $builder, AuditLogger $audit): void
    {
        $export = ExportArchive::with('applicant')->find($this->exportId);
        if (! $export || $export->status !== ExportArchive::PENDING || ! $export->applicant) {
            return;
        }

        $disk = (string) config('portal.storage.exports_disk', 'exports');
        $result = $builder->build($export->applicant, $disk);

        $export->forceFill([
            'status' => ExportArchive::READY,
            'disk' => $disk,
            'storage_path' => $result['path'],
            'download_name' => $result['download_name'],
            'file_count' => $result['file_count'],
            'size' => $result['size'],
            'expires_at' => now()->addMinutes((int) config('portal.exports.ttl_minutes', 60)),
        ])->save();

        $audit->record('export.ready', $export, ['file_count' => $result['file_count'], 'size' => $result['size']],
            applicantRef: $export->applicant->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);
    }

    public function failed(Throwable $e): void
    {
        $export = ExportArchive::with('applicant')->find($this->exportId);
        if (! $export) {
            return;
        }

        $export->forceFill(['status' => ExportArchive::FAILED])->save();

        app(AuditLogger::class)->record('export.failed', $export, ['error' => class_basename($e)],
            applicantRef: $export->applicant?->applicant_ref, actorType: AuditEvent::ACTOR_SYSTEM);
    }
}
