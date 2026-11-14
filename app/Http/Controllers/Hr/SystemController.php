<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\Files\FileScanner;
use App\Services\Retention\RetentionService;
use App\Services\Signatures\SignatureProviderManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Operational health. Shows configuration state only, never secrets.
 */
class SystemController extends Controller
{
    public function __invoke(FileScanner $scanner, RetentionService $retention, SignatureProviderManager $esign): View
    {
        $heartbeat = Cache::get('portal.scheduler_heartbeat');

        return view('hr.system', [
            'checks' => [
                ['Scheduler last ran', $heartbeat ? now()->setTimestamp((int) $heartbeat)->diffForHumans() : 'Never', $heartbeat && now()->getTimestamp() - (int) $heartbeat < 900],
                ['Queued jobs waiting', Schema::hasTable('jobs') ? (string) DB::table('jobs')->count() : 'n/a', true],
                ['Failed jobs', Schema::hasTable('failed_jobs') ? (string) DB::table('failed_jobs')->count() : 'n/a', ! Schema::hasTable('failed_jobs') || DB::table('failed_jobs')->count() === 0],
                ['Notification failures (7 days)', (string) DB::table('notification_deliveries')->where('status', 'failed')->where('created_at', '>', now()->subDays(7))->count(), true],
                ['Malware scanner', $scanner->name() === 'none' ? 'Not configured (uploads not scanned)' : $scanner->name(), $scanner->name() !== 'none'],
                ['E-signature provider', $esign->provider()->name(), true],
                ['Two-factor required for HR', config('portal.hr.two_factor_required') ? 'Yes' : 'No', (bool) config('portal.hr.two_factor_required')],
                ['Debug mode', config('app.debug') ? 'ON' : 'Off', ! config('app.debug')],
                ['HTTPS-only session cookie', config('session.secure') ? 'Yes' : 'No', (bool) config('session.secure') || ! app()->environment('production')],
                ['Mail transport', (string) config('mail.default'), config('mail.default') !== 'log' || ! app()->environment('production')],
                ['Official forms expired or expiring within '.config('portal.files.form_expiry_warning_days').' days', (string) DocumentTemplate::query()->expiringSoon()->count(), DocumentTemplate::query()->expiringSoon()->count() === 0],
                ['Automatic retention purge', config('portal.retention.auto_purge') ? 'Enabled' : 'Disabled (dry-run reports only)', true],
            ],
            'retention' => $retention->report(),
            'recentRetention' => DB::table('retention_events')->latest('id')->limit(20)->get(),
        ]);
    }
}
