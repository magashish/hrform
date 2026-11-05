<?php

use Illuminate\Support\Facades\Schedule;

/*
| Host cron (once a minute):  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('portal:heartbeat')->everyFiveMinutes();
Schedule::command('portal:reminders:send')->hourly()->withoutOverlapping();
Schedule::command('portal:exports:cleanup')->everyFifteenMinutes()->withoutOverlapping();
// Dry-run report unless RETENTION_AUTO_PURGE=true.
Schedule::command('portal:retention:purge')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('portal:audit:prune')->dailyAt('02:45')->withoutOverlapping();
