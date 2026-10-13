<?php

namespace Tests\Feature;

use App\Services\WebFetcher;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['crawler.allow_private_networks' => true, 'crawler.delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    /** @return array<string, array{int}> */
    public static function responseStatuses(): array
    {
        return ['success' => [200], 'missing' => [404], 'gone' => [410], 'unauthorized' => [401], 'forbidden' => [403], 'rate limited' => [429], 'server error' => [503]];
    }

    #[DataProvider('responseStatuses')]
    public function test_header_only_checks_preserve_http_responses_when_the_transport_wraps_the_interruption(int $status): void
    {
        Http::fake(['https://outside.test/link' => fn (Request $request, array $options): PromiseInterface => $this->headerResponse($request, $options, new Response($status, ['Retry-After' => '120']))]);

        $result = app(WebFetcher::class)->fetch('https://outside.test/link', false, null, false);

        $this->assertSame($status, $result['status']);
        $this->assertNull($result['error']);
        $this->assertSame('120', $result['headers']['retry-after']);
        $this->assertSame('', $result['body']);
        Http::assertSentCount(1);
    }

    public function test_header_only_checks_follow_redirects_and_preserve_the_final_error(): void
    {
        Http::fake([
            'https://outside.test/old' => fn (Request $request, array $options): PromiseInterface => $this->headerResponse($request, $options, new Response(301, ['Location' => '/missing'])),
            'https://outside.test/missing' => fn (Request $request, array $options): PromiseInterface => $this->headerResponse($request, $options, new Response(404)),
        ]);

        $result = app(WebFetcher::class)->fetch('https://outside.test/old', false, null, false);

        $this->assertSame(404, $result['status']);
        $this->assertSame('https://outside.test/missing', $result['url']);
        $this->assertSame([['url' => 'https://outside.test/old', 'status' => 301]], $result['redirects']);
        Http::assertSentCount(2);
    }

    public function test_header_only_connection_failures_have_no_http_status(): void
    {
        Http::fake(['https://outside.test/offline' => Http::failedConnection('Connection timed out')]);

        $result = app(WebFetcher::class)->fetch('https://outside.test/offline', false, null, false);

        $this->assertNull($result['status']);
        $this->assertSame('network_error', $result['classification']);
        $this->assertStringContainsString('Connection timed out', $result['error']);
    }

    /**
     * Exercise the on_headers callback and Guzzle 8 exception wrapping, which Http::response alone bypasses.
     *
     * @param  array{on_headers: callable}  $options
     */
    private function headerResponse(Request $request, array $options, Response $response): PromiseInterface
    {
        try {
            $options['on_headers']($response);
        } catch (\Throwable $exception) {
            return Create::rejectionFor(new ResponseException('An error was encountered during the on_headers event', $request->toPsrRequest(), $response, $exception));
        }

        return Create::promiseFor($response);
    }
}
