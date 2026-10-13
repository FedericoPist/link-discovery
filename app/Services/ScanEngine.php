<?php

namespace App\Services;

use App\Models\LinkCheck;
use App\Models\Page;
use App\Models\Scan;
use Illuminate\Support\Facades\DB;

class ScanEngine
{
    public function __construct(private WebFetcher $web, private UrlPolicy $urls, private HtmlExtractor $html) {}

    /** One persisted unit of work per job for cancellation and crash recovery. */
    public function step(Scan $scan): ?int
    {
        if (! $scan->active()) {
            return null;
        }
        $claimed = Scan::whereKey($scan->id)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'running', 'started_at' => $scan->started_at ?? now(), 'heartbeat_at' => now(),
        ]);
        if (! $claimed) {
            return null;
        }
        $scan = $scan->fresh();
        if (! $scan?->active()) {
            return null;
        }
        if ($page = $scan->pages()->where('status', 'pending')->orderBy('id')->first()) {
            $this->crawl($scan, $page);

            return 1;
        }
        $check = $scan->checks()->whereIn('classification', ['pending', 'retry'])
            ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->first();
        if ($check) {
            $this->check($check);

            return 1;
        }
        if ($scan->checks()->whereIn('classification', ['pending', 'retry'])->exists()) {
            return 15;
        }
        $readable = $scan->pages()->where('kind', 'html')->where('status', 'done')->exists();
        $reason = $scan->pages()->where('kind', 'html')->whereNotNull('error')->value('error');
        Scan::whereKey($scan->id)->where('status', 'running')->update([
            'status' => $readable ? 'completed' : 'failed', 'finished_at' => now(),
            'error' => $readable ? null : 'Nessuna pagina HTML analizzata. '.($reason ?: 'Controlla l’indirizzo e gli esiti delle pagine.'),
        ]);

        return null;
    }

    public function enqueue(Scan $scan, string $url, int $depth, string $kind = 'html'): void
    {
        $url = $this->urls->normalize($url);
        if (! $url || ! $this->urls->internal($url, $scan->settings)) {
            return;
        }
        if ($kind === 'html') {
            if ($depth > $scan->settings['max_depth']) {
                return;
            }
            if (! $scan->settings['include_query'] && parse_url($url, PHP_URL_QUERY) !== null && $url !== $scan->settings['url']) {
                return;
            }
            if (preg_match('/\.(?:pdf|jpe?g|png|gif|webp|svg|zip|gz|mp4|mp3|css|js|ico|woff2?|ttf)$/i', parse_url($url, PHP_URL_PATH))) {
                return;
            }
        }
        $hash = hash('sha256', $url);
        if ($scan->pages()->where('url_hash', $hash)->exists()) {
            return;
        }
        $limit = $kind === 'html' ? $scan->settings['max_pages'] : 10;
        if ($scan->pages()->where('kind', $kind)->count() >= $limit) {
            $scan->update(['limit_reached' => true]);

            return;
        }
        $scan->pages()->create(['url' => $url, 'url_hash' => $hash, 'depth' => $depth, 'kind' => $kind]);
    }

    private function crawl(Scan $scan, Page $page): void
    {
        $result = $this->web->fetch($page->url, true, $scan->settings);
        if (! $scan->fresh()?->active()) {
            return;
        }
        $sitemapUrls = [];
        if ($page->kind === 'html' && $page->depth === 0 && ! $result['error'] && $result['status'] >= 200 && $result['status'] < 300) {
            $robots = $this->web->robots($page->url);
            preg_match_all('/^Sitemap:\s*(\S+)/im', $robots['body'], $matches);
            $sitemapUrls = $matches[1];
        }
        DB::transaction(function () use ($scan, $page, $result, $sitemapUrls): void {
            $scan = Scan::whereKey($scan->id)->lockForUpdate()->first();
            if (! $scan?->active()) {
                return;
            }
            $fields = ['final_url' => $result['url'], 'http_status' => $result['status'], 'checked_at' => now(), 'warning' => $result['warning'] ?? null];
            if ($result['error'] || $result['status'] < 200 || $result['status'] >= 300) {
                $page->update($fields + ['status' => $result['classification'] === 'robots' ? 'skipped' : 'error', 'error' => $result['error'] ?: 'HTTP '.$result['status']]);

                return;
            }
            if ($page->kind === 'sitemap') {
                $this->sitemap($scan, $page, $result, $fields);

                return;
            }
            if ($scan->pages()->where('kind', 'html')->where('status', 'done')->where('final_url', $result['url'])->where('id', '!=', $page->id)->exists()) {
                $page->update($fields + ['status' => 'skipped', 'error' => 'Destinazione del redirect già analizzata.']);

                return;
            }
            $type = strtolower($result['headers']['content-type'] ?? '');
            if ($type !== '' && ! str_contains($type, 'html')) {
                $page->update($fields + ['status' => 'skipped', 'error' => 'Contenuto non HTML.']);

                return;
            }
            $data = $this->html->extract($result['body'], $result['url']);
            DB::transaction(function () use ($scan, $page, $fields, $data) {
                $page->occurrences()->delete();
                foreach ($data['links'] as $link) {
                    if ($this->urls->internal($link['url'], $scan->settings)) {
                        $this->enqueue($scan, $link['url'], $page->depth + 1);

                        continue;
                    }
                    $hash = hash('sha256', $link['url']);
                    $check = $scan->checks()->where('url_hash', $hash)->first();
                    if (! $check && $scan->checks()->count() >= config('crawler.max_links')) {
                        $scan->update(['limit_reached' => true]);

                        continue;
                    }
                    $check ??= $scan->checks()->create(['url' => $link['url'], 'url_hash' => $hash, 'host' => parse_url($link['url'], PHP_URL_HOST)]);
                    $page->occurrences()->create(['link_check_id' => $check->id, 'position' => $link['position'], 'href' => $link['href'], 'anchor_text' => $link['anchor_text'], 'rel' => $link['rel']]);
                }
                unset($data['links']);
                $page->update($fields + $data + ['status' => 'done', 'error' => null]);
            });
            foreach ($sitemapUrls as $sitemapUrl) {
                $this->enqueue($scan, $sitemapUrl, 0, 'sitemap');
            }
        });
    }

    private function sitemap(Scan $scan, Page $page, array $result, array $fields): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($result['body'], \SimpleXMLElement::class, LIBXML_NONET);
            if (! $xml || ! in_array($xml->getName(), ['urlset', 'sitemapindex'], true)) {
                $page->update($fields + ['status' => 'skipped', 'error' => 'Sitemap XML non valida.']);

                return;
            }
            $kind = $xml->getName() === 'sitemapindex' ? 'sitemap' : 'html';
            foreach ($xml->xpath('//*[local-name()="loc"]') ?: [] as $location) {
                $this->enqueue($scan, (string) $location, 0, $kind);
            }
            $page->update($fields + ['status' => 'done']);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function check(LinkCheck $check): void
    {
        $result = $this->web->fetch($check->url, false);
        $classification = $result['classification'] ?: LinkCheck::classify($result['status']);
        $attempts = $check->attempts + 1;
        $retry = in_array($classification, ['server_error', 'rate_limited', 'network_error'], true) && $attempts < 2;
        $wait = 30;
        if ($header = $result['headers']['retry-after'] ?? null) {
            $wait = ctype_digit($header) ? (int) $header : max(0, (strtotime($header) ?: time()) - time());
            if ($wait > 300) {
                $retry = false;
            }
        }
        $check->update([
            'classification' => $retry ? 'retry' : $classification, 'http_status' => $result['status'],
            'final_url' => $result['url'], 'redirects' => $result['redirects'], 'error' => $result['error'],
            'attempts' => $attempts, 'retry_at' => $retry ? now()->addSeconds(max(30, $wait)) : null, 'checked_at' => now(),
        ]);
    }
}
