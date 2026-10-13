<?php

namespace Tests\Feature;

use App\Services\LocalWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkDiscoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_stops_cleanly_when_idle_using_the_registered_queue_worker(): void
    {
        $originalStorage = storage_path();
        $temporary = sys_get_temp_dir().'/discovery-worker-test-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/framework', 0700, true);
        $this->app->useStoragePath($temporary);
        config(['queue.default' => 'database']);
        try {
            $this->artisan('discovery:work', ['--idle-seconds' => 1])->assertSuccessful();
            $this->assertTrue(app('queue.worker')->shouldQuit);
            $this->assertFalse(app(LocalWorker::class)->status()['running']);
        } finally {
            $this->app->useStoragePath($originalStorage);
            @unlink($temporary.'/framework/discovery-worker.lock');
            rmdir($temporary.'/framework');
            rmdir($temporary);
        }
    }
}
