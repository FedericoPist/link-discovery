<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Spatie\Robots\RobotsTxt;

class WebFetcher
{
    public function __construct(private UrlPolicy $urls) {}

    public function fetch(string $url, bool $body = true, ?array $scope = null, bool $robots = true): array
    {
        $redirects = [];
        $warnings = [];
        $current = $url;
        try {
            for ($hop = 0; $hop <= config('crawler.max_redirects'); $hop++) {
                $pins = $this->urls->resolve($current);
                if ($scope && ! $this->urls->internal($current, $scope)) {
                    return $this->error('blocked', 'Redirect fuori dal sito: pagina non scansionata.', $current, $redirects);
                }
                if ($robots) {
                    $policy = $this->robots($current);
                    if (! $policy['allowed']) {
                        $continue = ($scope['continue_on_robots_error'] ?? false)
                            && $policy['http_status'] >= 500 && $policy['http_status'] <= 599;
                        if (! $continue) {
                            return $this->error('robots_unavailable', $policy['error'], $current, $redirects);
                        }
                        $warnings[] = 'robots.txt restituisce HTTP '.$policy['http_status'].': scansione proseguita per scelta nelle impostazioni del sito.';
                    }
                    if (! (new RobotsTxt($policy['body']))->allows($current, 'LinkBuildingDiscover')) {
                        return $this->error('robots', 'Il file robots.txt esclude esplicitamente questo percorso per il crawler.', $current, $redirects);
                    }
                }
                $response = $this->request($current, $pins, $body);
                $status = $response['status'];
                if (in_array($status, [301, 302, 303, 307, 308], true) && ($location = $response['headers']['location'] ?? null)) {
                    $redirects[] = ['url' => $current, 'status' => $status];
                    $next = $this->urls->normalize($location, $current);
                    if (! $next || in_array($next, array_column($redirects, 'url'), true)) {
                        return $this->error('redirect_error', 'Redirect circolare o destinazione non valida.', $current, $redirects);
                    }
                    $current = $next;

                    continue;
                }

                return $response + ['url' => $current, 'redirects' => $redirects, 'error' => null, 'classification' => null, 'warning' => $warnings ? implode(' ', array_unique($warnings)) : null];
            }

            return $this->error('redirect_error', 'Superato il limite di redirect.', $current, $redirects);
        } catch (\InvalidArgumentException $e) {
            return $this->error('blocked', $e->getMessage(), $current, $redirects);
        } catch (\Throwable $e) {
            return $this->error('network_error', mb_substr($e->getMessage(), 0, 500), $current, $redirects);
        }
    }

    public function robots(string $url): array
    {
        $origin = $this->urls->origin($url);

        $key = 'robots:v2:'.hash('sha256', $origin);
        if ($policy = Cache::get($key)) {
            return $policy;
        }
        $result = $this->fetch($origin.'/robots.txt', true, null, false);
        $status = $result['status'];
        $allowed = $status !== null && $status < 500 && ! in_array($status, [401, 403, 429], true);
        $reason = $status !== null ? 'HTTP '.$status : ($result['error'] ?: 'errore di rete');
        $policy = [
            'allowed' => $allowed, 'http_status' => $status,
            'body' => $status >= 200 && $status < 300 ? $result['body'] : '',
            'error' => $allowed ? null : 'Impossibile verificare '.$origin.'/robots.txt ('.$reason.'). Non è un divieto Disallow; la pagina non è stata richiesta.',
        ];
        // Recheck temporary failures sooner than successfully retrieved rules.
        $ttl = $allowed ? 1800 : 60;
        if ($status === 429 && ($retryAfter = $result['headers']['retry-after'] ?? null)) {
            $ttl = max($ttl, ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time()));
        }
        Cache::put($key, $policy, $ttl);

        return $policy;
    }

    private function request(string $url, array $pins, bool $body): array
    {
        $origin = $this->urls->origin($url);
        $delay = config('crawler.delay_ms') / 1000;
        $policy = Cache::get('robots:v2:'.hash('sha256', $origin));
        if ($policy) {
            $delay = max($delay, (float) (new RobotsTxt($policy['body']))->crawlDelay('LinkBuildingDiscover'));
        }
        if ($delay > 20) {
            throw new \RuntimeException('Crawl-delay superiore a 20 secondi: verifica sospesa per rispettare il sito.');
        }
        $key = 'request-at:'.hash('sha256', $origin);
        Cache::lock($key.':lock', 30)->block(25, function () use ($key, $delay) {
            $remaining = $delay - (microtime(true) - (float) Cache::get($key, 0));
            if ($remaining > 0) {
                usleep((int) ($remaining * 1000000));
            }
            Cache::put($key, microtime(true), 60);
        });

        $headersResponse = null;
        try {
            $response = Http::withUserAgent(config('crawler.user_agent'))
                ->connectTimeout(5)->timeout(config('crawler.timeout'))
                ->withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'curl' => [CURLOPT_RESOLVE => $pins],
                    'on_headers' => function (ResponseInterface $response) use ($body, &$headersResponse) {
                        if (! $body) {
                            // A real GET, stopped after headers: avoids HEAD-only false positives and large downloads.
                            $headersResponse = $response;
                            throw new HeadersReceived($response);
                        }
                        if ((int) $response->getHeaderLine('Content-Length') > config('crawler.max_bytes')) {
                            throw new \RuntimeException('Documento troppo grande (limite 3 MB).');
                        }
                    },
                    'progress' => function ($total, $downloaded) {
                        if ($downloaded > config('crawler.max_bytes')) {
                            throw new \RuntimeException('Documento troppo grande (limite 3 MB).');
                        }
                    },
                ])->get($url);
            if (strlen($response->body()) > config('crawler.max_bytes')) {
                throw new \RuntimeException('Documento troppo grande (limite 3 MB).');
            }

            return ['status' => $response->status(), 'body' => $body ? $response->body() : '', 'headers' => $this->headers($response->headers())];
        } catch (\Throwable $e) {
            if ($headersResponse !== null) {
                return ['status' => $headersResponse->getStatusCode(), 'body' => '', 'headers' => $this->headers($headersResponse->getHeaders())];
            }
            for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof HeadersReceived) {
                    return ['status' => $cause->response->getStatusCode(), 'body' => '', 'headers' => $this->headers($cause->response->getHeaders())];
                }
            }
            throw $e;
        }
    }

    private function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            $result[strtolower($name)] = implode(', ', $values);
        }

        return $result;
    }

    private function error(string $classification, string $message, string $url, array $redirects): array
    {
        return ['status' => null, 'body' => '', 'headers' => [], 'url' => $url, 'redirects' => $redirects, 'classification' => $classification, 'error' => $message];
    }
}
