<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class LocalWorker
{
    public function enabled(): bool
    {
        return config('app.env') === 'local'
            && config('crawler.auto_start_worker')
            && config('queue.default') === 'database';
    }

    /** @return array{running: bool, starting: bool, enabled: bool} */
    public function status(): array
    {
        $heartbeat = Cache::get('discovery:worker-heartbeat');
        $running = $heartbeat && $heartbeat['at'] >= time() - 360;

        return [
            'running' => (bool) $running,
            'starting' => ! $running && Cache::has('discovery:worker-starting'),
            'enabled' => $this->enabled(),
        ];
    }

    public function heartbeat(): void
    {
        Cache::put('discovery:worker-heartbeat', ['at' => time(), 'pid' => getmypid()], 360);
        Cache::forget('discovery:worker-starting');
    }

    public function stopped(): void
    {
        if ((Cache::get('discovery:worker-heartbeat')['pid'] ?? null) === getmypid()) {
            Cache::forget('discovery:worker-heartbeat');
        }
    }

    /** Start only the queue consumer: this never creates scans or enables recurring scans. */
    public function ensureRunning(): void
    {
        if (! $this->enabled() || $this->status()['running']) {
            return;
        }
        if (! Cache::add('discovery:worker-starting', true, 15)) {
            return;
        }
        try {
            $command = 'nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan'))
                .' discovery:work --idle-seconds=60 --no-interaction >> '
                .escapeshellarg(storage_path('logs/worker.log')).' 2>&1 < /dev/null &';
            $result = Process::path(base_path())->timeout(5)->run($command);
            if (! $result->successful()) {
                throw new \RuntimeException($result->errorOutput() ?: 'Avvio del motore non riuscito.');
            }
        } catch (\Throwable $exception) {
            Cache::forget('discovery:worker-starting');
            report($exception);
        }
    }
}
