<?php

namespace App\Services;

use Symfony\Component\DomCrawler\Crawler;

class HtmlExtractor
{
    public function __construct(private UrlPolicy $urls) {}

    public function extract(string $html, string $url): array
    {
        $crawler = new Crawler($html, $url);
        $base = $crawler->filter('base[href]')->count() ? $this->urls->normalize($crawler->filter('base[href]')->first()->attr('href'), $url) : null;
        $meta = [];
        $crawler->filter('meta')->each(function (Crawler $node) use (&$meta) {
            $key = $node->attr('name') ?: $node->attr('property');
            if ($key) {
                $meta[strtolower($key)][] = $node->attr('content') ?? '';
            }
        });
        $links = [];
        $crawler->filter('a[href]')->each(function (Crawler $node, int $position) use (&$links, $base, $url) {
            $target = $this->urls->normalize($node->attr('href'), $base ?: $url);
            if (! $target) {
                return;
            }
            $text = $node->text('', true);
            if ($text === '') {
                $text = implode(' ', $node->filter('img[alt]')->each(fn (Crawler $img) => $img->attr('alt')));
            }
            $links[] = ['url' => $target, 'href' => $node->attr('href'), 'anchor_text' => $text, 'rel' => $node->attr('rel') ?? '', 'position' => $position];
        });

        return [
            'title' => $crawler->filter('title')->count() ? $crawler->filter('title')->first()->text('', true) : '',
            'h1' => $crawler->filter('h1')->each(fn (Crawler $node) => $node->text('', true)),
            'meta_description' => $meta['description'][0] ?? '', 'meta' => $meta, 'links' => $links,
        ];
    }
}
