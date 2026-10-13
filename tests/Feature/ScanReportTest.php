<?php

namespace Tests\Feature;

use App\Models\LinkCheck;
use App\Models\Scan;
use App\Models\Site;
use App\Services\ScanStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_default_report_and_export_include_every_issue_but_exclude_healthy_and_unchecked_links(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $outcomes = [
            'broken' => 404, 'server_error' => 503, 'access_denied' => 403,
            'rate_limited' => 429, 'client_error' => 400, 'network_error' => null,
            'redirect_error' => null, 'robots' => null, 'robots_unavailable' => null,
            'blocked' => null, 'retry' => 502,
        ];
        foreach ($outcomes as $classification => $status) {
            $this->addLink($scan, $classification, $classification, $status);
        }
        $this->addLink($scan, 'healthy', 'ok', 200);
        $this->addLink($scan, 'unchecked', 'pending');
        $other = app(ScanStarter::class)->start(Site::factory()->create());
        $this->addLink($other, 'other-scan', 'broken', 404);

        $response = $this->get('/scans/'.$scan->id)->assertOk();
        $csv = $this->get('/scans/'.$scan->id.'/export')->assertOk()->streamedContent();

        $this->assertSame(11, $response->viewData('results')->total());
        $this->assertSame(11, $response->viewData('counts')['issues']);
        $this->assertSame(6, $response->viewData('counts')['errors']);
        $this->assertSame(2, $response->viewData('counts')['network']);
        $this->assertSame(3, $response->viewData('counts')['unverifiable']);
        foreach (array_keys($outcomes) as $classification) {
            $response->assertSee('https://outside.test/'.$classification);
            $this->assertStringContainsString('https://outside.test/'.$classification, $csv);
        }
        foreach (['healthy', 'unchecked', 'other-scan'] as $excluded) {
            $response->assertDontSee('https://outside.test/'.$excluded);
            $this->assertStringNotContainsString('https://outside.test/'.$excluded, $csv);
        }
    }

    public function test_group_filters_search_and_csv_return_the_same_occurrences(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $this->addLink($scan, 'http', 'broken', 404);
        $this->addLink($scan, 'dns', 'network_error');
        $this->addLink($scan, 'redirect', 'redirect_error');
        $this->addLink($scan, 'robots', 'robots');
        $this->addLink($scan, 'healthy', 'ok', 200);

        foreach (['errors' => ['http'], 'network' => ['dns', 'redirect'], 'unverifiable' => ['robots'], 'ok' => ['healthy'], 'issues&q=dns' => ['dns']] as $filter => $expected) {
            $response = $this->get('/scans/'.$scan->id.'?filter='.$filter)->assertOk();
            $csv = $this->get('/scans/'.$scan->id.'/export?filter='.$filter)->assertOk()->streamedContent();
            $actual = $response->viewData('results')->getCollection()->map(fn (LinkCheck $check): string => basename($check->url))->sort()->values()->all();
            $this->assertSame($expected, $actual);
            foreach (['http', 'dns', 'redirect', 'robots', 'healthy'] as $slug) {
                $this->assertSame(in_array($slug, $expected, true), str_contains($csv, 'https://outside.test/'.$slug));
            }
        }
    }

    public function test_repeated_footer_links_appear_once_in_report_and_csv_with_all_sources_preserved(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $check = $this->addLink($scan, 'missing', 'broken', 404);
        $page = $scan->pages()->where('kind', 'html')->firstOrFail();
        for ($position = 2; $position <= 27; $position++) {
            $page->occurrences()->create(['link_check_id' => $check->id, 'position' => $position, 'href' => $check->url, 'anchor_text' => 'Occurrence '.$position]);
        }
        $otherUrl = 'https://source.test/article';
        $otherPage = $scan->pages()->create(['url' => $otherUrl, 'url_hash' => hash('sha256', $otherUrl), 'title' => 'Articolo']);
        $otherPage->occurrences()->create(['link_check_id' => $check->id, 'position' => 1, 'href' => $check->url, 'anchor_text' => 'Footer articolo']);

        $response = $this->get('/scans/'.$scan->id)->assertOk();
        $csv = $this->get('/scans/'.$scan->id.'/export')->assertOk()->streamedContent();

        $this->assertSame(1, $response->viewData('counts')['issues']);
        $this->assertSame(1, $response->viewData('results')->total());
        $this->assertCount(1, $response->viewData('results'));
        $response->assertSee('2 pagine sorgenti · 28 presenze')->assertSee('Occurrence 27')->assertSee('Footer articolo');
        $this->assertSame(1, substr_count($csv, 'https://outside.test/missing'));
        $this->assertStringContainsString('Footer articolo', $csv);
        $this->assertStringContainsString('Occurrence 27', $csv);
        $this->get('/scans/'.$scan->id.'?q=Footer')->assertOk()->assertViewHas('results', fn ($results): bool => $results->total() === 1);
        $this->get('/scans/'.$scan->id.'?q=source.test%2Farticle')->assertOk()->assertViewHas('results', fn ($results): bool => $results->total() === 1);
        $filteredCsv = $this->get('/scans/'.$scan->id.'/export?q=Footer')->assertOk()->streamedContent();
        $this->assertSame(1, substr_count($filteredCsv, 'https://outside.test/missing'));
    }

    public function test_unique_links_are_paginated_and_csv_includes_links_beyond_the_first_page(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        for ($index = 1; $index <= 27; $index++) {
            $this->addLink($scan, 'missing-'.$index, 'broken', 404);
        }

        $first = $this->get('/scans/'.$scan->id)->assertOk();
        $second = $this->get('/scans/'.$scan->id.'?page=2')->assertOk();
        $csv = $this->get('/scans/'.$scan->id.'/export')->assertOk()->streamedContent();

        $this->assertSame(27, $first->viewData('results')->total());
        $this->assertCount(25, $first->viewData('results'));
        $this->assertCount(2, $second->viewData('results'));
        $this->assertSame([], array_intersect($first->viewData('results')->pluck('id')->all(), $second->viewData('results')->pluck('id')->all()));
        $this->assertSame(27, substr_count($csv, 'https://outside.test/missing-'));
    }

    public function test_report_escapes_visible_error_messages(): void
    {
        $scan = app(ScanStarter::class)->start(Site::factory()->create());
        $check = $this->addLink($scan, 'offline', 'network_error');
        $check->update(['error' => '<script>alert(1)</script>']);

        $this->get('/scans/'.$scan->id)->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>', false);
    }

    private function addLink(Scan $scan, string $slug, string $classification, ?int $status = null): LinkCheck
    {
        $url = 'https://outside.test/'.$slug;
        $check = $scan->checks()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'host' => 'outside.test', 'classification' => $classification, 'http_status' => $status]);
        $page = $scan->pages()->where('kind', 'html')->firstOrFail();
        $page->occurrences()->create(['link_check_id' => $check->id, 'position' => $page->occurrences()->count() + 1, 'href' => $url, 'anchor_text' => $slug]);

        return $check;
    }
}
