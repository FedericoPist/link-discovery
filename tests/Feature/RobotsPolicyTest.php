<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\ScanEngine;
use App\Services\ScanStarter;
use App\Services\WebFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RobotsPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crawler.allow_private_networks' => true, 'crawler.delay_ms' => 0]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public static function missingStatuses(): array
    {
        return ['not found' => [404], 'gone' => [410]];
    }

    #[DataProvider('missingStatuses')]
    public function test_missing_robots_does_not_block_crawling(int $status): void
    {
        Http::fake([
            'https://source.test/robots.txt' => Http::response('User-agent: *\nDisallow: /', $status),
            'https://source.test/' => Http::response('<h1>Pagina raggiungibile</h1>'),
        ]);
        $result = app(WebFetcher::class)->fetch('https://source.test/');
        $this->assertSame(200, $result['status']);
        $this->assertNull($result['error']);
        $this->assertNull($result['warning']);
    }

    public function test_server_error_is_reported_with_its_actual_status_and_is_not_a_disallow(): void
    {
        Http::fake(['https://source.test/robots.txt' => Http::response('Errore WordPress', 500)]);
        $result = app(WebFetcher::class)->fetch('https://source.test/');
        $this->assertSame('robots_unavailable', $result['classification']);
        $this->assertStringContainsString('HTTP 500', $result['error']);
        $this->assertStringContainsString('https://source.test/robots.txt', $result['error']);
        $this->assertNull($result['status']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://source.test/');
    }

    public function test_opt_in_continues_on_5xx_and_persists_warning_in_report_and_csv(): void
    {
        Http::fake([
            'https://source.test/robots.txt' => Http::response('', 500),
            'https://source.test/' => Http::response('<title>Sito</title><h1>Test</h1><a href="https://outside.test/missing">Esterno</a>'),
            'https://source.test/sitemap.xml' => Http::response('', 404),
            'https://outside.test/robots.txt' => Http::response('', 404),
            'https://outside.test/missing' => Http::response('', 404),
        ]);
        $site = Site::factory()->create(['continue_on_robots_error' => true]);
        $scan = app(ScanStarter::class)->start($site);
        for ($step = 0; $step < 8 && $scan->fresh()->active(); $step++) {
            app(ScanEngine::class)->step($scan->fresh());
        }
        $this->assertSame('completed', $scan->fresh()->status);
        $page = $scan->pages()->where('kind', 'html')->firstOrFail();
        $this->assertSame('done', $page->status);
        $this->assertStringContainsString('HTTP 500', $page->warning);
        $this->assertSame(404, $scan->checks()->firstOrFail()->http_status);
        $this->get('/scans/'.$scan->id)->assertOk()->assertSee('La scansione è proseguita nonostante errori 5xx');
        $csv = $this->get('/scans/'.$scan->id.'/export')->streamedContent();
        $this->assertStringContainsString('Avviso pagina sorgente', $csv);
        $this->assertStringContainsString('HTTP 500', $csv);
    }

    public function test_opt_in_does_not_override_an_explicit_disallow(): void
    {
        Http::fake(['https://source.test/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200)]);
        $result = app(WebFetcher::class)->fetch('https://source.test/', true, ['url' => 'https://source.test/', 'continue_on_robots_error' => true]);
        $this->assertSame('robots', $result['classification']);
        $this->assertStringContainsString('esclude esplicitamente', $result['error']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://source.test/');
    }

    public function test_temporary_policy_expires_and_recovered_rules_are_respected(): void
    {
        Http::fake([
            'https://source.test/robots.txt' => Http::sequence()->push('', 500)->push("User-agent: *\nDisallow: /", 200),
            'https://source.test/' => Http::response('<h1>Test</h1>'),
        ]);
        $scope = ['url' => 'https://source.test/', 'continue_on_robots_error' => true];
        $first = app(WebFetcher::class)->fetch('https://source.test/', true, $scope);
        $this->assertSame(200, $first['status']);
        $this->travel(61)->seconds();
        $second = app(WebFetcher::class)->fetch('https://source.test/', true, $scope);
        $this->assertSame('robots', $second['classification']);
    }

    public function test_connection_errors_and_rate_limits_are_not_treated_as_missing_files(): void
    {
        Http::fake([
            'https://offline.test/robots.txt' => Http::failedConnection('Timeout'),
            'https://limited.test/robots.txt' => Http::response('', 429, ['Retry-After' => '120']),
        ]);
        foreach (['offline.test', 'limited.test'] as $host) {
            $url = 'https://'.$host.'/';
            $result = app(WebFetcher::class)->fetch($url, true, ['url' => $url, 'continue_on_robots_error' => true]);
            $this->assertSame('robots_unavailable', $result['classification']);
            $this->assertNull($result['status']);
        }
        $this->travel(61)->seconds();
        app(WebFetcher::class)->fetch('https://limited.test/');
        Http::assertSentCount(2);
    }

    public function test_scan_without_any_readable_page_is_failed_not_completed(): void
    {
        Http::fake(['https://source.test/robots.txt' => Http::response('', 500)]);
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        for ($i = 0; $i < 4 && $scan->fresh()->active(); $i++) {
            app(ScanEngine::class)->step($scan->fresh());
        }
        $this->assertSame('failed', $scan->fresh()->status);
        $this->assertStringContainsString('HTTP 500', $scan->fresh()->error);
    }

    public function test_site_option_is_saved_and_copied_into_new_scan_settings(): void
    {
        $site = Site::factory()->create();
        $this->get('/sites/'.$site->id.'/edit')->assertOk()->assertSee('Prosegui se robots.txt');
        $this->put('/sites/'.$site->id, [
            'name' => $site->name, 'url' => $site->url, 'max_pages' => 20, 'max_depth' => 3,
            'schedule_frequency' => 'manual', 'continue_on_robots_error' => '1',
        ])->assertRedirect();
        $this->assertTrue($site->fresh()->continue_on_robots_error);
        $scan = app(ScanStarter::class)->start($site->fresh());
        $this->assertTrue($scan->settings['continue_on_robots_error']);
        $this->assertSame('manual', $site->fresh()->schedule_frequency);
    }

    public function test_old_negative_cache_is_not_used_by_the_new_policy(): void
    {
        Cache::put('robots:'.hash('sha256', 'https://source.test'), ['allowed' => false, 'body' => ''], 1800);
        Http::fake([
            'https://source.test/robots.txt' => Http::response('', 404),
            'https://source.test/' => Http::response('<h1>Test</h1>'),
        ]);
        $this->assertSame(200, app(WebFetcher::class)->fetch('https://source.test/')['status']);
    }
}
