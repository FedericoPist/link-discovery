<?php

namespace Tests\Network;

use App\Models\Site;
use App\Services\ScanEngine;
use App\Services\ScanStarter;
use App\Services\WebFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LiveNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_get_headers_and_html_on_public_example_domain(): void
    {
        config(['crawler.delay_ms' => 500]);
        $web = app(WebFetcher::class);
        $robots = $web->fetch('https://example.com/robots.txt', true, null, false);
        $page = $web->fetch('https://example.com/', true);
        $this->assertSame(200, $page['status'], json_encode(['page' => $page, 'robots' => $robots]));
        $this->assertStringContainsString('Example Domain', $page['body']);
        $headers = $web->fetch('https://example.com/', false);
        $this->assertSame(200, $headers['status'], json_encode($headers));
        $this->assertSame('', $headers['body']);
        $this->assertNull($headers['error']);
    }

    public function test_full_scan_of_one_public_page_persists_links_and_source_metadata(): void
    {
        Queue::fake();
        config(['crawler.max_links' => 3]);
        $site = Site::factory()->create(['url' => 'https://www.php.net/', 'host' => 'www.php.net', 'max_pages' => 1, 'max_depth' => 0]);
        $scan = app(ScanStarter::class)->start($site);
        for ($i = 0; $i < 15 && $scan->fresh()->active(); $i++) {
            app(ScanEngine::class)->step($scan->fresh());
            $this->travel(31)->seconds();
        }
        $this->assertSame('completed', $scan->fresh()->status);
        $this->assertSame(1, $scan->pages()->where('kind', 'html')->where('status', 'done')->count());
        $page = $scan->pages()->where('kind', 'html')->firstOrFail();
        $this->assertStringContainsString('PHP', $page->title);
        $this->assertIsArray($page->h1);
        $this->assertGreaterThan(0, $page->occurrences()->count());
        $this->assertSame(0, $scan->checks()->whereIn('classification', ['pending', 'retry'])->count());
        $this->assertGreaterThan(0, $scan->checks()->where('attempts', '>', 0)->count());
        $this->get('/scans/'.$scan->id.'?filter=all')->assertOk()->assertSee('PHP');
    }
}
