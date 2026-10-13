<?php

namespace Tests\Feature;

use App\Jobs\ProcessScan;
use App\Models\LinkOccurrence;
use App\Models\Scan;
use App\Models\Site;
use App\Services\HtmlExtractor;
use App\Services\ScanEngine;
use App\Services\ScanStarter;
use App\Services\UrlPolicy;
use App\Services\WebFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crawler.allow_private_networks' => true, 'crawler.delay_ms' => 0]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function site(array $attributes = []): Site
    {
        return Site::factory()->create($attributes);
    }

    private function finish(Scan $scan): Scan
    {
        for ($i = 0; $i < 60 && $scan->fresh()->active(); $i++) {
            app(ScanEngine::class)->step($scan->fresh());
            $this->travel(31)->seconds();
        }

        return $scan->fresh();
    }

    public function test_manual_creation_dispatches_one_scan_and_renders_without_javascript(): void
    {
        $this->get('/')->assertOk()->assertSee('Ogni link racconta')->assertDontSee('<script', false);
        $this->get('/sites/create')->assertOk()->assertSee('Solo manuale');
        $this->post('/sites', ['name' => 'Magazine', 'url' => 'source.test', 'max_pages' => 10, 'max_depth' => 2, 'schedule_frequency' => 'manual'])->assertRedirect();
        $site = Site::first();
        $this->assertNull($site->next_scan_at);
        $this->assertSame('manual', $site->schedule_frequency);
        $this->assertSame(1, $site->scans()->count());
        app(ScanStarter::class)->start($site);
        $this->assertSame(1, $site->scans()->count());
        $this->get('/scans/1')->assertOk()->assertSee('Aggiorna')->assertDontSee('<script', false);
        $this->get('/')->assertOk()->assertSee('Magazine');
    }

    public function test_crawl_preserves_occurrences_metadata_and_deduplicates_external_checks(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200),
            'https://source.test/' => Http::response('<title>Home</title><meta name="description" content="Descrizione SEO"><meta property="og:title" content="Social"><h1>Primo H1</h1><h1>Secondo H1</h1><a href="/article">Articolo</a><a href="/private">Privata</a><a href="https://outside.test/missing#one">Prima anchor</a><a href="https://outside.test/missing#two" rel="nofollow">Seconda anchor</a><a href="mailto:test@example.com">Mail</a>', 200, ['Content-Type' => 'text/html']),
            'https://source.test/sitemap.xml' => Http::response('', 404),
            'https://source.test/article' => Http::response('<title>Articolo</title><h1>H1 articolo</h1><a href="https://outside.test/missing"><img alt="Anchor immagine"></a>', 200, ['Content-Type' => 'text/html']),
            'https://outside.test/missing' => Http::response('', 404),
        ]);
        $scan = $this->finish(app(ScanStarter::class)->start($this->site()));
        $this->assertSame('completed', $scan->status);
        $this->assertSame(1, $scan->checks()->count());
        $this->assertSame(3, LinkOccurrence::count());
        $this->assertSame('broken', $scan->checks()->first()->classification);
        $page = $scan->pages()->where('url', 'https://source.test/')->first();
        $this->assertSame(['Primo H1', 'Secondo H1'], $page->h1);
        $this->assertSame('Descrizione SEO', $page->meta_description);
        $this->assertSame(['Social'], $page->meta['og:title']);
        $this->assertSame('skipped', $scan->pages()->where('url', 'https://source.test/private')->first()->status);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://source.test/private');
        $this->get('/scans/'.$scan->id)->assertOk()->assertSee('Prima anchor')->assertSee('Descrizione SEO')->assertSee('Anchor immagine');
        $csv = $this->get('/scans/'.$scan->id.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Prima anchor', $csv);
        $this->assertStringContainsString('Primo H1 | Secondo H1', $csv);
    }

    public function test_redirects_retry_and_http_classification(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://source.test/' => Http::response('<a href="https://outside.test/redirect">Redirect</a><a href="https://outside.test/server">Server</a><a href="https://outside.test/denied">Accesso</a><a href="https://outside.test/rate">Limite</a>'),
            'https://source.test/sitemap.xml' => Http::response('', 404),
            'https://outside.test/redirect' => Http::response('', 301, ['Location' => '/gone']),
            'https://outside.test/gone' => Http::response('', 410),
            'https://outside.test/server' => Http::sequence()->push('', 503)->push('', 200),
            'https://outside.test/denied' => Http::response('', 403),
            'https://outside.test/rate' => Http::response('', 429, ['Retry-After' => '3600']),
        ]);
        $scan = $this->finish(app(ScanStarter::class)->start($this->site()));
        $checks = $scan->checks()->get()->keyBy('url');
        $this->assertSame('https://outside.test/gone', $checks['https://outside.test/redirect']->final_url);
        $this->assertSame(410, $checks['https://outside.test/redirect']->http_status);
        $this->assertCount(1, $checks['https://outside.test/redirect']->redirects);
        $this->assertSame('ok', $checks['https://outside.test/server']->classification);
        $this->assertSame(2, $checks['https://outside.test/server']->attempts);
        $this->assertSame('access_denied', $checks['https://outside.test/denied']->classification);
        $this->assertSame('rate_limited', $checks['https://outside.test/rate']->classification);
        $this->assertSame(1, $checks['https://outside.test/rate']->attempts);
        $this->get('/scans/'.$scan->id.'?filter=ok')->assertOk()->assertSee('outside.test/server')->assertDontSee('outside.test/denied');
    }

    public function test_sitemap_index_and_page_budget(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://source.test/' => Http::response('<h1>Home</h1>'),
            'https://source.test/sitemap.xml' => Http::response('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://source.test/pages.xml</loc></sitemap></sitemapindex>'),
            'https://source.test/pages.xml' => Http::response('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://source.test/one</loc></url><url><loc>https://source.test/two</loc></url></urlset>'),
            'https://source.test/one' => Http::response('<h1>One</h1>'),
        ]);
        $scan = $this->finish(app(ScanStarter::class)->start($this->site(['max_pages' => 2])));
        $this->assertTrue($scan->limit_reached);
        $this->assertSame(2, $scan->pages()->where('kind', 'html')->count());
        $this->assertSame(2, $scan->pages()->where('kind', 'html')->where('status', 'done')->count());
        Http::assertNotSent(fn ($request) => $request->url() === 'https://source.test/two');
    }

    public function test_scheduling_is_opt_in_and_disabling_clears_the_next_run(): void
    {
        $manual = $this->site(['next_scan_at' => now()->subDay()]);
        $scheduled = $this->site(['name' => 'Programmato', 'schedule_frequency' => 'weekly', 'next_scan_at' => now()->subMinute()]);
        $this->artisan('discovery:scheduled')->assertSuccessful();
        $this->assertSame(0, $manual->scans()->count());
        $this->assertSame(1, $scheduled->scans()->count());
        $this->assertSame('scheduled', $scheduled->scans()->first()->trigger);
        $this->assertTrue($scheduled->fresh()->next_scan_at->isFuture());
        $this->artisan('discovery:scheduled')->assertSuccessful();
        $this->assertSame(1, $scheduled->scans()->count());
        $this->put('/sites/'.$scheduled->id, ['name' => 'Programmato', 'url' => $scheduled->url, 'max_pages' => 20, 'max_depth' => 3, 'schedule_frequency' => 'manual'])->assertRedirect();
        $this->assertNull($scheduled->fresh()->next_scan_at);
    }

    public function test_cancel_resume_and_snapshot_settings(): void
    {
        $site = $this->site();
        $scan = app(ScanStarter::class)->start($site);
        $site->update(['max_pages' => 100]);
        $this->assertSame(20, $scan->fresh()->settings['max_pages']);
        $this->post('/scans/'.$scan->id.'/cancel')->assertRedirect();
        (new ProcessScan($scan->id))->handle(app(ScanEngine::class));
        Http::assertNothingSent();
        $this->assertSame('cancelled', $scan->fresh()->status);
        $this->post('/scans/'.$scan->id.'/resume')->assertRedirect();
        $this->assertSame('queued', $scan->fresh()->status);
    }

    public function test_url_normalization_scope_and_private_addresses(): void
    {
        $urls = app(UrlPolicy::class);
        $this->assertSame('https://source.test/story?a=1&b=2', $urls->normalize('../story?a=1&amp;b=2#section', 'https://SOURCE.test/path/page'));
        $this->assertNull($urls->normalize('javascript:alert(1)'));
        $this->assertNull($urls->normalize('https://user:password@example.com/'));
        $this->assertFalse($urls->internal('https://source.test.evil.com/', ['url' => 'https://source.test/']));
        $this->assertTrue($urls->internal('https://www.source.test/', ['url' => 'https://source.test/']));
        $this->assertFalse($urls->internal('https://blog.source.test/', ['url' => 'https://source.test/']));
        foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '::1', '::ffff:127.0.0.1', 'fc00::1', '224.0.0.1'] as $ip) {
            $this->assertFalse($urls->publicAddress($ip), $ip);
        }
        config(['crawler.allow_private_networks' => false]);
        $result = app(WebFetcher::class)->fetch('http://127.0.0.1/');
        $this->assertSame('blocked', $result['classification']);
        Http::assertNothingSent();
    }

    public function test_base_url_empty_anchor_and_html_escaping(): void
    {
        $data = app(HtmlExtractor::class)->extract('<base href="https://external.test/dir/"><title>A &amp; B</title><a href="../missing"> Test <b>anchor</b> </a><a href="https://other.test/"></a>', 'https://source.test/');
        $this->assertSame('https://external.test/missing', $data['links'][0]['url']);
        $this->assertSame('Test anchor', $data['links'][0]['anchor_text']);
        $this->assertSame('', $data['links'][1]['anchor_text']);
        $site = $this->site(['name' => '<script>alert(1)</script>']);
        $this->get('/')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>', false);
    }

    public function test_csv_neutralizes_formulas_and_respects_search(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), 'https://source.test/' => Http::response('<a href="https://outside.test/missing">=1+1</a>'), 'https://source.test/sitemap.xml' => Http::response('', 404), 'https://outside.test/missing' => Http::response('', 404)]);
        $scan = $this->finish(app(ScanStarter::class)->start($this->site()));
        $csv = $this->get('/scans/'.$scan->id.'/export')->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $empty = $this->get('/scans/'.$scan->id.'/export?q=absent')->streamedContent();
        $this->assertStringNotContainsString('outside.test', $empty);
        $this->get('/scans/'.$scan->id.'?filter=made-up')->assertRedirect();
    }

    public function test_failed_connections_and_persistent_server_errors_are_distinct(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://source.test/' => Http::response('<a href="https://outside.test/server">Server</a><a href="https://outside.test/offline">Offline</a>'),
            'https://source.test/sitemap.xml' => Http::response('', 404),
            'https://outside.test/server' => Http::response('', 502),
            'https://outside.test/offline' => Http::failedConnection('Connection timed out'),
        ]);
        $scan = $this->finish(app(ScanStarter::class)->start($this->site()));
        $server = $scan->checks()->where('classification', 'server_error')->firstOrFail();
        $network = $scan->checks()->where('classification', 'network_error')->firstOrFail();
        $this->assertSame(502, $server->http_status);
        $this->assertSame(2, $server->attempts);
        $this->assertNull($network->http_status);
        $this->assertSame(2, $network->attempts);
        $this->get('/scans/'.$scan->id.'?filter=errors')->assertOk()->assertSee('outside.test/server')->assertDontSee('outside.test/offline');
    }

    public function test_redirect_cannot_escape_crawl_scope_and_loops_are_reported(): void
    {
        Http::fake([
            '*/robots.txt' => Http::response('', 404),
            'https://source.test/' => Http::response('', 302, ['Location' => 'https://outside.test/']),
            'https://source.test/loop' => Http::response('', 302, ['Location' => '/loop']),
        ]);
        $result = app(WebFetcher::class)->fetch('https://source.test/', true, ['url' => 'https://source.test/']);
        $this->assertSame('blocked', $result['classification']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'outside.test'));
        $loop = app(WebFetcher::class)->fetch('https://source.test/loop');
        $this->assertSame('redirect_error', $loop['classification']);
    }

    public function test_url_query_and_depth_settings_limit_discovery(): void
    {
        $scan = app(ScanStarter::class)->start($this->site(['max_depth' => 1]));
        $engine = app(ScanEngine::class);
        $engine->enqueue($scan, 'https://source.test/page?q=1', 1);
        $engine->enqueue($scan, 'https://source.test/too-deep', 2);
        $engine->enqueue($scan, 'https://source.test/allowed', 1);
        $this->assertSame(2, $scan->pages()->where('kind', 'html')->count());
        $this->assertTrue($scan->pages()->where('url', 'https://source.test/allowed')->exists());
    }

    public function test_report_explains_when_no_html_pages_could_be_read(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $scan = $this->finish(app(ScanStarter::class)->start($this->site()));
        $this->get('/scans/'.$scan->id)->assertOk()->assertSee('Non è stato possibile leggere alcuna pagina HTML');
        $this->assertSame(0, $scan->checks()->count());
    }

    public function test_workspace_rejects_non_local_requests(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/')->assertForbidden();
    }
}
