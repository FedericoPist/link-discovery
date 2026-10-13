<?php

namespace Tests\Feature;

use App\Jobs\ProcessScan;
use App\Models\Site;
use App\Services\ScanEngine;
use App\Services\ScanStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['crawler.allow_private_networks' => true, 'crawler.delay_ms' => 0]);
    }

    /** @return array<string, array{string}> */
    public static function scanStatuses(): array
    {
        return ['queued' => ['queued'], 'running' => ['running'], 'completed' => ['completed'], 'failed' => ['failed'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('scanStatuses')]
    public function test_deleting_a_scan_removes_its_results_and_preserves_the_site_and_other_scans(string $status): void
    {
        $site = Site::factory()->create();
        $kept = app(ScanStarter::class)->start($site);
        $kept->update(['status' => 'completed']);
        $target = app(ScanStarter::class)->start($site);
        $target->update(['status' => $status]);
        $page = $target->pages()->where('kind', 'html')->firstOrFail();
        $url = 'https://outside.test/missing';
        $check = $target->checks()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'host' => 'outside.test', 'classification' => 'broken', 'http_status' => 404]);
        $occurrence = $page->occurrences()->create(['link_check_id' => $check->id, 'position' => 1, 'href' => $url, 'anchor_text' => 'Missing']);

        $this->delete(route('scans.destroy', $target), ['confirm' => '1'])
            ->assertRedirectToRoute('dashboard')->assertSessionHas('success');

        $this->assertModelMissing($target);
        $this->assertDatabaseMissing('pages', ['scan_id' => $target->id]);
        $this->assertModelMissing($check);
        $this->assertModelMissing($occurrence);
        $this->assertModelExists($site);
        $this->assertModelExists($kept);
        $this->assertSame(2, $kept->pages()->count());
        $this->get(route('scans.show', $target))->assertNotFound();
        $this->get(route('scans.export', $target))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('scans.show', $target));
    }

    public function test_confirmation_is_read_only_and_delete_requires_explicit_confirmation(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());

        $this->get(route('scans.delete', $scan))->assertOk()->assertSee('Elimina definitivamente')->assertSee('La scansione è ancora attiva');
        $this->assertModelExists($scan);
        $this->delete(route('scans.destroy', $scan))->assertSessionHasErrors('confirm');
        $this->assertModelExists($scan);
        $this->assertSame(2, $scan->pages()->count());
    }

    public function test_non_local_requests_cannot_delete_a_scan(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->delete(route('scans.destroy', $scan), ['confirm' => '1'])->assertForbidden();

        $this->assertModelExists($scan);
    }

    public function test_queued_jobs_for_deleted_scans_do_not_fetch_or_dispatch_more_work(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $this->delete(route('scans.destroy', $scan), ['confirm' => '1'])->assertRedirectToRoute('dashboard');
        Queue::fake();

        (new ProcessScan($scan->id))->handle(app(ScanEngine::class));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('scans', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function pendingRequests(): array
    {
        return [
            'html page' => ['https://source.test/', '<a href="/article">Internal</a><a href="https://outside.test/link">External</a>'],
            'sitemap' => ['https://source.test/sitemap.xml', '<urlset><url><loc>https://source.test/article</loc></url></urlset>'],
            'external link' => ['https://outside.test/link', ''],
        ];
    }

    #[DataProvider('pendingRequests')]
    public function test_deletion_during_a_request_does_not_recreate_results_or_schedule_more_work(string $url, string $body): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        if ($url !== 'https://source.test/') {
            $scan->pages()->where('kind', 'html')->update(['status' => 'done']);
        }
        if ($url === 'https://outside.test/link') {
            $scan->pages()->update(['status' => 'done']);
            $scan->checks()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'host' => 'outside.test']);
        }
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            $url => function () use ($scan, $body) {
                $scan->delete();

                return Http::response($body, 200);
            },
        ]);
        Queue::fake();

        (new ProcessScan($scan->id))->handle(app(ScanEngine::class));

        $this->assertDatabaseCount('scans', 0);
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('link_checks', 0);
        $this->assertDatabaseCount('link_occurrences', 0);
        Queue::assertNothingPushed();
        Http::assertSentCount(2);
    }
}
