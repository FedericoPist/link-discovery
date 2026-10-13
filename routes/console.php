<?php

use App\Models\Site;
use App\Services\ScanStarter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('discovery:scheduled', function () {
    $count = 0;
    Cache::lock('discovery:scheduler', 55)->get(function () use (&$count) {
        Site::where('schedule_frequency', '!=', 'manual')->where('next_scan_at', '<=', now())->each(function (Site $site) use (&$count) {
            if ($site->scans()->whereIn('status', ['queued', 'running'])->exists()) {
                return;
            }
            app(ScanStarter::class)->start($site, 'scheduled');
            $site->update(['next_scan_at' => $site->nextRun()]);
            $count++;
        });
    });
    $this->info("Scansioni pianificate avviate: {$count}");
})->purpose('Avvia solo le scansioni periodiche abilitate dall’utente.');

Schedule::command('discovery:scheduled')->everyMinute()->withoutOverlapping();
