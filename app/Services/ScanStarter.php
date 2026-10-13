<?php

namespace App\Services;

use App\Jobs\ProcessScan;
use App\Models\Scan;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

class ScanStarter
{
    public function start(Site $site, string $trigger = 'manual'): Scan
    {
        $scan = DB::transaction(function () use ($site, $trigger) {
            $site = Site::whereKey($site->id)->lockForUpdate()->firstOrFail();
            if ($active = $site->scans()->whereIn('status', ['queued', 'running'])->first()) {
                return $active;
            }
            $scan = $site->scans()->create([
                'trigger' => $trigger,
                'settings' => $site->only(['url', 'max_pages', 'max_depth', 'include_subdomains', 'include_query', 'continue_on_robots_error']),
            ]);
            $engine = app(ScanEngine::class);
            $engine->enqueue($scan, $site->url, 0);
            $engine->enqueue($scan, app(UrlPolicy::class)->origin($site->url).'/sitemap.xml', 0, 'sitemap');
            ProcessScan::dispatch($scan->id)->afterCommit();

            return $scan;
        });
        DB::afterCommit(fn () => app(LocalWorker::class)->ensureRunning());

        return $scan;
    }
}
