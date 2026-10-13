<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class UrlPolicy
{
    public function normalize(string $value, ?string $base = null): ?string
    {
        try {
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($value === '' || str_starts_with($value, '#') || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
                return null;
            }
            $uri = new Uri($value);
            if ($base !== null) {
                $uri = UriResolver::resolve(new Uri($base), $uri);
            }
            if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
                return null;
            }
            $host = strtolower($uri->getHost());
            if (! str_starts_with($host, '[')) {
                $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
            }
            $uri = $uri->withHost(rtrim($host, '.'))->withFragment('')->withPath(UriResolver::removeDotSegments($uri->getPath() ?: '/'));

            return strlen((string) $uri) <= 8192 ? (string) $uri : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function internal(string $url, array $settings): bool
    {
        $host = preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?? ''));
        $root = preg_replace('/^www\./', '', strtolower(parse_url($settings['url'], PHP_URL_HOST) ?? ''));

        return $host === $root || (($settings['include_subdomains'] ?? false) && str_ends_with($host, '.'.$root));
    }

    public function origin(string $url): string
    {
        $uri = new Uri($url);

        return $uri->getScheme().'://'.$uri->getAuthority();
    }

    /** Resolve and pin each request to a validated public address, including redirect hops. */
    public function resolve(string $url): array
    {
        $host = trim(parse_url($url, PHP_URL_HOST) ?? '', '[]');
        $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https:') ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('Sono consentite solo le porte HTTP 80 e HTTPS 443.');
        }
        if (config('crawler.allow_private_networks')) {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            if (! str_contains($host, '.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
                throw new InvalidArgumentException('Gli indirizzi locali non sono consentiti.');
            }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: [])));
        }
        if ($addresses === []) {
            throw new \RuntimeException('DNS: impossibile risolvere il dominio.');
        }
        foreach ($addresses as $address) {
            if (! $this->publicAddress($address)) {
                throw new InvalidArgumentException('La destinazione risolve a un indirizzo privato o riservato.');
            }
        }
        $address = $addresses[0];

        return ["{$host}:{$port}:".(str_contains($address, ':') ? "[{$address}]" : $address)];
    }

    public function publicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        if (str_contains($address, ':')) {
            // Only globally routable IPv6 unicast; exclude mapped/private and transition ranges.
            return (bool) preg_match('/^[23][0-9a-f]{3}:/i', $address)
                && ! preg_match('/^(2001:(0:|db8:)|2002:)/i', $address);
        }
        $long = ip2long($address);

        return ($long & 0xFFC00000) !== ip2long('100.64.0.0') && ($long & 0xF0000000) !== ip2long('224.0.0.0');
    }
}
