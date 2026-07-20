<?php

declare(strict_types=1);

namespace MeadBotApi\Chat;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Fetches and extracts readable text + same-site links from pages on wiki.meadtools.com, for the
 * chat agent's fetch_meadtools_wiki_page tool. Deliberately restricted to this one host rather
 * than being a general-purpose URL fetcher -- an LLM-directed "fetch any URL" tool is an SSRF
 * risk (internal network addresses, cloud metadata endpoints, etc.), and the whole point of this
 * tool is to ground chat answers in this one wiki, not general web browsing. The host check is
 * applied both to the requested URL and (separately) to wherever the request actually ends up
 * after redirects, so a same-host page redirecting off-host can't be used to bypass it.
 */
final class MeadToolsWikiClient
{
    public const ALLOWED_HOST = 'wiki.meadtools.com';

    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const MAX_TEXT_CHARS = 6000;
    private const MAX_LINKS = 40;
    private const INDEX_PATH = __DIR__ . '/data/meadtools_wiki_index.json';

    /** @var callable(string): array{status: int, body: string, contentType: string, effectiveUrl: string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * index(path) - returns a hand-maintained index of wiki.meadtools.com pages (title, url,
     * keywords) bundled with this app, so the model can pick a specific page to fetch directly
     * by matching the question against titles/keywords, rather than crawling link-by-link from
     * the home page (which was burning through the tool-call budget without reliably finding the
     * relevant page). Static/manually updated, not re-crawled per request -- see this file's
     * sibling data/meadtools_wiki_index.json and its "generated" field for freshness.
     *
     * @return array<string, mixed>
     */
    public static function index(?string $path = null): array
    {
        $path ??= self::INDEX_PATH;

        $json = @file_get_contents($path);
        if ($json === false) {
            return ['error' => true, 'errorMessage' => 'The wiki page index is unavailable.'];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['error' => true, 'errorMessage' => 'The wiki page index is malformed.'];
        }

        return ['error' => false] + $decoded;
    }

    /**
     * fetch(url) - fetches a page from wiki.meadtools.com and returns its title, readable text
     * (truncated to keep tool results token-cheap), and same-host links found on the page (so the
     * model can fetch one of those next). Returns an {error, errorMessage} array -- never throws
     * -- for anything the model can act on: a disallowed host, fetch failure, or non-HTML content.
     *
     * @return array<string, mixed>
     */
    public function fetch(string $url): array
    {
        $normalized = self::normalizeUrl($url);
        if ($normalized === null) {
            return ['error' => true, 'errorMessage' => "Invalid URL: {$url}"];
        }
        if (!self::isAllowedHost($normalized)) {
            return [
                'error' => true,
                'errorMessage' => 'This tool only fetches pages from ' . self::ALLOWED_HOST . ", not {$url}.",
            ];
        }

        try {
            $response = ($this->transport)($normalized);
        } catch (RuntimeException $e) {
            return ['error' => true, 'errorMessage' => $e->getMessage()];
        }

        if (!self::isAllowedHost($response['effectiveUrl'])) {
            return [
                'error' => true,
                'errorMessage' => "{$normalized} redirected off " . self::ALLOWED_HOST . '; refusing to follow.',
            ];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['error' => true, 'errorMessage' => "Fetching {$normalized} failed (HTTP {$response['status']})."];
        }

        if (!str_contains($response['contentType'], 'html')) {
            return ['error' => true, 'errorMessage' => "{$normalized} is not an HTML page ({$response['contentType']})."];
        }

        $body = substr($response['body'], 0, self::MAX_RESPONSE_BYTES);
        return self::extract($response['effectiveUrl'], $body);
    }

    private static function isAllowedHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && strcasecmp($host, self::ALLOWED_HOST) === 0;
    }

    private static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $url = 'https://' . self::ALLOWED_HOST . $url;
        } elseif (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /** @return array<string, mixed> */
    private static function extract(string $url, string $html): array
    {
        $dom = new DOMDocument();
        $previousErrorSetting = libxml_use_internal_errors(true);
        // libxml's HTML parser is lenient about malformed markup; errors are expected and
        // discarded rather than surfaced, since we only need best-effort text/link extraction.
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        $xpath = new DOMXPath($dom);

        $titleNodes = $xpath->query('//title');
        $title = $titleNodes !== false && $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        foreach (['//script', '//style', '//nav', '//footer'] as $tag) {
            $nodes = $xpath->query($tag);
            if ($nodes === false) {
                continue;
            }
            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $rawText = $dom->textContent ?? '';
        $text = trim((string) preg_replace('/\n{3,}/', "\n\n", (string) preg_replace('/[ \t]+/', ' ', $rawText)));
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS) . '... [truncated]';
        }

        $links = [];
        $anchors = $xpath->query('//a[@href]');
        foreach ($anchors !== false ? iterator_to_array($anchors) : [] as $anchor) {
            if (count($links) >= self::MAX_LINKS) {
                break;
            }
            $resolved = self::resolveLink($url, $anchor->getAttribute('href'));
            if ($resolved === null) {
                continue;
            }
            $linkText = trim($anchor->textContent);
            if ($linkText === '') {
                continue;
            }
            $links[] = ['url' => $resolved, 'text' => $linkText];
        }

        return ['error' => false, 'url' => $url, 'title' => $title, 'text' => $text, 'links' => $links];
    }

    // Resolves an <a href> against the page it was found on, returning null for anything not
    // usable (empty, fragment-only, javascript:/mailto:) or not on the allowed host.
    private static function resolveLink(string $baseUrl, string $href): ?string
    {
        $href = trim($href);
        if (
            $href === '' ||
            str_starts_with($href, '#') ||
            str_starts_with($href, 'javascript:') ||
            str_starts_with($href, 'mailto:')
        ) {
            return null;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $resolved = $href;
        } elseif (str_starts_with($href, '/')) {
            $resolved = 'https://' . self::ALLOWED_HOST . $href;
        } else {
            $lastSlash = strrpos($baseUrl, '/');
            $base = $lastSlash === false ? $baseUrl : rtrim(substr($baseUrl, 0, $lastSlash), '/');
            $resolved = $base . '/' . $href;
        }

        return self::isAllowedHost($resolved) ? $resolved : null;
    }

    /** @return array{status: int, body: string, contentType: string, effectiveUrl: string} */
    private static function curlTransport(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'MeadBotAPI/1.0 (+https://github.com/jceddy/MeadBotAPI)',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Failed to fetch {$url}: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body, 'contentType' => $contentType, 'effectiveUrl' => $effectiveUrl ?: $url];
    }
}
