<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Heartbeat extends Command
{
    protected $signature = 'portal:heartbeat';

    protected $description = 'Record that the scheduler is running (shown on the HR System page)';

    public function handle(): int
    {
        Cache::forever('portal.scheduler_heartbeat', now()->getTimestamp());

        return self::SUCCESS;
    }
}
