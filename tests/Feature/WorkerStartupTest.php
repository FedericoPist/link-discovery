<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\LocalWorker;
use App\Services\ScanStarter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class WorkerStartupTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'crawler.auto_start_worker' => true, 'queue.default' => 'database']);
        Process::fake();
    }

    public function test_starting_a_scan_starts_the_worker_after_the_job_is_persisted(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $this->assertDatabaseCount('jobs', 1);
        Process::assertRan(fn ($process) => str_contains($process->command, 'discovery:work --idle-seconds=60'));
        $this->assertTrue(app(LocalWorker::class)->status()['starting']);
        $this->get('/scans/'.$scan->id)->assertOk()->assertSee('Avvio del motore di scansione');
    }

    public function test_repeated_starts_do_not_duplicate_workers_or_scans(): void
    {
        $site = Site::factory()->create();
        $first = app(ScanStarter::class)->start($site);
        $second = app(ScanStarter::class)->start($site);
        $this->assertSame($first->id, $second->id);
        Process::assertRanTimes(fn ($process) => str_contains($process->command, 'discovery:work'), 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_existing_queued_scan_can_restart_a_missing_worker(): void
    {
        $site = Site::factory()->create();
        $scan = app(ScanStarter::class)->start($site);
        Cache::forget('discovery:worker-starting');
        Process::fake();
        $this->post('/sites/'.$site->id.'/scan')->assertRedirect('/scans/'.$scan->id);
        Process::assertRan(fn ($process) => str_contains($process->command, 'discovery:work'));
        $this->assertDatabaseCount('scans', 1);
    }

    public function test_running_worker_is_not_started_again(): void
    {
        app(LocalWorker::class)->heartbeat();
        app(ScanStarter::class)->start(Site::factory()->create());
        Process::assertNothingRan();
        $this->assertTrue(app(LocalWorker::class)->status()['running']);
    }

    public function test_launch_failure_is_visible_and_can_be_retried(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Launch failed', exitCode: 1));
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $this->assertFalse(app(LocalWorker::class)->status()['starting']);
        $this->get('/scans/'.$scan->id)->assertOk()->assertSee('Il motore di scansione non è attivo')->assertSee('Avvia elaborazione')->assertDontSee('La scansione continua in background');
    }

    public function test_worker_button_starts_only_the_consumer_without_creating_scans(): void
    {
        $this->post('/worker/start')->assertRedirect();
        Process::assertRan(fn ($process) => str_contains($process->command, 'discovery:work'));
        $this->assertDatabaseCount('scans', 0);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_automatic_start_is_disabled_outside_local_environment(): void
    {
        config(['app.env' => 'production']);
        app(ScanStarter::class)->start(Site::factory()->create());
        Process::assertNothingRan();
        $this->assertFalse(app(LocalWorker::class)->status()['enabled']);
    }

    public function test_stopping_the_worker_clears_the_heartbeat(): void
    {
        $worker = app(LocalWorker::class);
        $worker->heartbeat();
        $worker->stopped();
        $this->assertFalse($worker->status()['running']);
    }
}
