<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use MeadBotApi\Chat\MeadToolsWikiClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MeadToolsWikiClientTest extends TestCase
{
    /** @param array{status?: int, body?: string, contentType?: string, effectiveUrl?: string} $overrides */
    private static function response(array $overrides = []): array
    {
        return array_merge(
            ['status' => 200, 'body' => '<html><head><title>t</title></head><body></body></html>', 'contentType' => 'text/html; charset=utf-8', 'effectiveUrl' => 'https://wiki.meadtools.com/en/home'],
            $overrides
        );
    }

    public function testFetchesAPageAndExtractsTitleTextAndSameHostLinksOnly(): void
    {
        $html = <<<HTML
            <html>
              <head><title>Mead Making 101</title></head>
              <body>
                <script>trackStuff();</script>
                <style>.foo { color: red; }</style>
                <nav>Nav links here</nav>
                <p>Honey is mostly fructose and glucose.</p>
                <a href="/en/nutrients">Nutrients</a>
                <a href="https://wiki.meadtools.com/en/yeast">Yeast</a>
                <a href="siblings">Siblings</a>
                <a href="https://example.com/off-host">Off host</a>
                <a href="#section">Fragment only</a>
                <a href="javascript:void(0)">JS link</a>
                <a href="mailto:a@b.com">Email</a>
                <a href="/en/empty-text"></a>
                <footer>Footer junk</footer>
              </body>
            </html>
            HTML;

        $client = new MeadToolsWikiClient(fn () => self::response(['body' => $html, 'effectiveUrl' => 'https://wiki.meadtools.com/en/home']));
        $result = $client->fetch('https://wiki.meadtools.com/en/home');

        self::assertFalse($result['error']);
        self::assertSame('Mead Making 101', $result['title']);
        self::assertStringContainsString('Honey is mostly fructose and glucose.', $result['text']);
        self::assertStringNotContainsString('trackStuff', $result['text']);
        self::assertStringNotContainsString('color: red', $result['text']);
        self::assertStringNotContainsString('Nav links here', $result['text']);
        self::assertStringNotContainsString('Footer junk', $result['text']);

        $linkUrls = array_column($result['links'], 'url');
        self::assertContains('https://wiki.meadtools.com/en/nutrients', $linkUrls);
        self::assertContains('https://wiki.meadtools.com/en/yeast', $linkUrls);
        self::assertContains('https://wiki.meadtools.com/en/siblings', $linkUrls);
        self::assertNotContains('https://example.com/off-host', $linkUrls);
        self::assertCount(3, $result['links'], 'fragment/js/mailto/off-host/empty-text links must be filtered out');
    }

    public function testRejectsUrlsNotOnTheAllowedHostWithoutCallingTheTransport(): void
    {
        $called = false;
        $client = new MeadToolsWikiClient(function () use (&$called) {
            $called = true;
            return self::response();
        });

        $result = $client->fetch('https://example.com/en/home');

        self::assertTrue($result['error']);
        self::assertStringContainsString('wiki.meadtools.com', $result['errorMessage']);
        self::assertFalse($called, 'the transport must not be invoked for a disallowed host');
    }

    public function testAcceptsABarePathAndNormalizesItToTheWikiHost(): void
    {
        $capturedUrl = null;
        $client = new MeadToolsWikiClient(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return self::response(['effectiveUrl' => $url]);
        });

        $client->fetch('/en/mead-making-101');

        self::assertSame('https://wiki.meadtools.com/en/mead-making-101', $capturedUrl);
    }

    public function testRejectsWhenTheEffectiveUrlAfterRedirectsIsOffHost(): void
    {
        $client = new MeadToolsWikiClient(fn () => self::response(['effectiveUrl' => 'https://attacker.example.com/']));

        $result = $client->fetch('https://wiki.meadtools.com/en/home');

        self::assertTrue($result['error']);
        self::assertStringContainsString('redirected off', $result['errorMessage']);
    }

    public function testReturnsAnErrorForANonHtmlContentType(): void
    {
        $client = new MeadToolsWikiClient(fn () => self::response(['contentType' => 'application/pdf']));

        $result = $client->fetch('https://wiki.meadtools.com/en/some-file.pdf');

        self::assertTrue($result['error']);
        self::assertStringContainsString('not an HTML page', $result['errorMessage']);
    }

    public function testReturnsAnErrorForANonSuccessStatus(): void
    {
        $client = new MeadToolsWikiClient(fn () => self::response(['status' => 404, 'effectiveUrl' => 'https://wiki.meadtools.com/en/missing']));

        $result = $client->fetch('https://wiki.meadtools.com/en/missing');

        self::assertTrue($result['error']);
        self::assertStringContainsString('HTTP 404', $result['errorMessage']);
    }

    public function testSurfacesATransportFailureAsAnErrorRatherThanThrowing(): void
    {
        $client = new MeadToolsWikiClient(function () {
            throw new RuntimeException('Failed to fetch: connection timed out');
        });

        $result = $client->fetch('https://wiki.meadtools.com/en/home');

        self::assertTrue($result['error']);
        self::assertStringContainsString('connection timed out', $result['errorMessage']);
    }

    public function testReturnsAnErrorForAnUnparseableUrl(): void
    {
        $client = new MeadToolsWikiClient(fn () => self::response());

        $result = $client->fetch('not a url with spaces');

        self::assertTrue($result['error']);
        self::assertStringContainsString('Invalid URL', $result['errorMessage']);
    }

    public function testTruncatesVeryLongText(): void
    {
        $longParagraph = '<p>' . str_repeat('mead ', 3000) . '</p>'; // well over the 6000-char cap
        $client = new MeadToolsWikiClient(fn () => self::response(['body' => "<html><body>{$longParagraph}</body></html>"]));

        $result = $client->fetch('https://wiki.meadtools.com/en/home');

        self::assertLessThanOrEqual(6000 + mb_strlen('... [truncated]'), mb_strlen($result['text']));
        self::assertStringEndsWith('... [truncated]', $result['text']);
    }

    public function testCapsTheNumberOfLinksReturned(): void
    {
        $links = '';
        for ($i = 0; $i < 100; $i++) {
            $links .= "<a href=\"/en/page-{$i}\">Page {$i}</a>";
        }
        $client = new MeadToolsWikiClient(fn () => self::response(['body' => "<html><body>{$links}</body></html>"]));

        $result = $client->fetch('https://wiki.meadtools.com/en/home');

        self::assertCount(40, $result['links']);
    }
}
