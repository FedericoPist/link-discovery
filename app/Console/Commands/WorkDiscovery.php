<?php

namespace App\Console\Commands;

use App\Services\LocalWorker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

#[Signature('discovery:work {--idle-seconds=60 : Arresta il motore dopo questo intervallo senza lavori; 0 per tenerlo attivo}')]
#[Description('Elabora le scansioni con un solo motore locale e avvio automatico su richiesta')]
class WorkDiscovery extends Command
{
    public function handle(LocalWorker $worker): int
    {
        if (config('queue.default') !== 'database') {
            $this->error('Il motore locale richiede la coda database.');

            return self::FAILURE;
        }
        $lock = fopen(storage_path('framework/discovery-worker.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            $this->info('Il motore è già attivo.');

            return self::SUCCESS;
        }

        $idleSince = null;
        $idleSeconds = max(0, (int) $this->option('idle-seconds'));
        $listening = true;
        Event::listen(Looping::class, function () use (&$idleSince, &$listening, $idleSeconds): void {
            if (! $listening || $idleSeconds === 0) {
                return;
            }
            $connection = config('queue.connections.database.connection');
            $pending = DB::connection($connection)->table(config('queue.connections.database.table'))->where('queue', 'default')->exists();
            if ($pending) {
                $idleSince = null;
            } else {
                $idleSince ??= time();
                if (time() - $idleSince >= $idleSeconds) {
                    app('queue.worker')->shouldQuit = true;
                }
            }
        });

        try {
            $worker->heartbeat();

            return $this->call('queue:work', [
                'connection' => 'database', '--queue' => 'default', '--sleep' => 1,
                '--tries' => 3, '--timeout' => 300, '--memory' => 256,
            ]);
        } finally {
            $listening = false;
            $worker->stopped();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
