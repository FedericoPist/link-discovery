<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\ScanEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(public int $scanId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('scan:'.$this->scanId))->releaseAfter(10)->expireAfter(330)];
    }

    public function handle(ScanEngine $engine): void
    {
        $scan = Scan::find($this->scanId);
        if (! $scan || ! $scan->active()) {
            return;
        }
        $delay = $engine->step($scan);
        if ($delay !== null && $scan->fresh()?->active()) {
            self::dispatch($scan->id)->delay(now()->addSeconds($delay));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Scan::whereKey($this->scanId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception?->getMessage() ?? 'Lavoro interrotto.', 0, 1000),
        ]);
    }
}
