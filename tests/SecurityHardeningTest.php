<?php

declare(strict_types=1);

namespace HElturkey\WebTrawler\Tests;

use HElturkey\WebTrawler\Adaptive\AdaptiveMatcher;
use HElturkey\WebTrawler\Adaptive\ElementSnapshot;
use HElturkey\WebTrawler\Browser\BrowserRequestGuard;
use HElturkey\WebTrawler\Browser\BrowserSessionState;
use HElturkey\WebTrawler\Browser\NetworkMetadata;
use HElturkey\WebTrawler\Exception\UnsafeUrlException;
use HElturkey\WebTrawler\Export\CsvExporter;
use HElturkey\WebTrawler\Http\UrlGuard;
use HElturkey\WebTrawler\Parsing\Page;
use HElturkey\WebTrawler\Storage\InMemorySnapshotStore;
use HElturkey\WebTrawler\Support\UrlSanitizer;
use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase
{
    public function test_secrets_are_redacted_in_query_fragment_userinfo_and_encoded_names(): void
    {
        $cases = [
            'https://example.test/#access_token=SECRET' => 'https://example.test/#access_token=[REDACTED]',
            'https://example.test/?%61ccess_token=SECRET' => 'https://example.test/?%61ccess_token=[REDACTED]',
            'https://example.test/?page=2&token=SECRET&x=1' => 'https://example.test/?page=2&token=[REDACTED]&x=1',
            'https://example.test/?a=1;api_key=SECRET' => 'https://example.test/?a=1;api_key=[REDACTED]',
            'https://example.test/#/route?session_id=SECRET&tab=2' => 'https://example.test/#/route?session_id=[REDACTED]&tab=2',
            'https://user:pw@example.test/path' => 'https://example.test/path',
            '//user:pw@example.test/path?token=SECRET' => '//example.test/path?token=[REDACTED]',
            'https://example.test/?X-Amz-Signature=SECRET' => 'https://example.test/?X-Amz-Signature=[REDACTED]',
            'https://example.test/?my_clientToken=SECRET' => 'https://example.test/?my_clientToken=[REDACTED]',
        ];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, UrlSanitizer::sanitize($input), $input);
            self::assertSame($expected, NetworkMetadata::sanitizeUrl($input));
        }
        self::assertSame('https://example.test/?q=books&page=2#top', UrlSanitizer::sanitize('https://example.test/?q=books&page=2#top'));
        self::assertSame('request //example.test/path?token=[REDACTED]', UrlSanitizer::sanitizeText('request //user:pw@example.test/path?token=SECRET'));
    }

    public function test_snapshots_never_store_url_secrets(): void
    {
        $url = 'https://example.test/list?token=SECRET&page=1';
        $page = Page::fromHtml('<main><a id="x" href="/b">Book</a></main>', $url, new InMemorySnapshotStore(), new AdaptiveMatcher());
        $snapshot = ElementSnapshot::capture($page->css('a')->first() ?? self::fail('fixture'), $url);

        self::assertStringNotContainsString('SECRET', (string) json_encode($snapshot->toArray()));
    }

    public function test_network_truncation_always_yields_valid_utf8_and_binary_bodies_are_base64(): void
    {
        foreach (["\xFFabc", "ab\xC3", "\xE2\x82", "ééé\x80"] as $input) {
            for ($limit = 0; $limit <= 6; $limit++) {
                self::assertSame(1, preg_match('//u', NetworkMetadata::truncate($input, $limit)), bin2hex($input) . " @ $limit");
            }
        }

        $binary = NetworkMetadata::encodeBody("\x00\xFF\xFE binary", 100);
        self::assertSame('base64', $binary['encoding']);
        self::assertSame("\x00\xFF\xFE binary", base64_decode($binary['body'], true));

        $text = NetworkMetadata::encodeBody('héllo wörld', 5);
        self::assertSame('utf-8', $text['encoding']);
        self::assertTrue($text['truncated']);
        self::assertSame(1, preg_match('//u', $text['body']));
    }

    public function test_csv_formula_neutralization_covers_hidden_prefixes(): void
    {
        $dangerous = ['=1+1', ' =1+1', "\n=1+1", "\t=cmd", "\x00@SUM(A1)", "\u{FEFF}=1+1", "\u{200B}+1", '  -2+3', "\u{00A0}@x"];
        $safe = ['plain', 'a=b', 'user@example.test', '12-34', '', ' just spaces '];

        $path = sys_get_temp_dir() . '/scraper-csv-' . bin2hex(random_bytes(4)) . '.csv';
        $rows = [];
        foreach ([...$dangerous, ...$safe] as $i => $value) {
            $rows[] = ['v' => $value, 'i' => $i];
        }
        (new CsvExporter())->export($rows, $path, neutralizeFormulas: true);
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        fgetcsv($handle, null, ',', '"', '');
        $read = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $read[(int) $row[1]] = $row[0];
        }
        fclose($handle);
        unlink($path);

        foreach ($dangerous as $i => $value) {
            self::assertSame("'" . $value, $read[$i], 'not neutralized: ' . json_encode($value));
        }
        foreach ($safe as $j => $value) {
            self::assertSame($value, $read[count($dangerous) + $j], 'wrongly altered: ' . json_encode($value));
        }
    }

    public function test_the_guard_fails_closed_for_names_that_do_not_resolve(): void
    {
        $this->expectException(UnsafeUrlException::class);
        $this->expectExceptionMessageMatches('/did not resolve/');
        UrlGuard::assertPublic('https://this-host-does-not-exist.invalid/');
    }

    public function test_the_browser_guard_filters_every_kind_of_request(): void
    {
        $guard = new BrowserRequestGuard();

        foreach (['data:text/html,hi', 'blob:https://x/abc', 'about:blank', 'https://8.8.8.8/x', 'wss://[2001:4860:4860::8888]/socket'] as $url) {
            self::assertTrue($guard->allows($url), $url);
        }
        foreach (['http://127.0.0.1:9222/json', 'http://169.254.169.254/latest?token=SECRET', 'http://10.0.0.5/', 'file:///etc/passwd', 'ftp://8.8.8.8/x', 'http://localhost/'] as $url) {
            self::assertFalse($guard->allows($url), $url);
        }

        $blocked = implode(' ', $guard->blockedUrls());
        self::assertStringContainsString('169.254.169.254', $blocked);
        self::assertStringNotContainsString('SECRET', $blocked, 'Blocked URLs are recorded without secrets.');
    }

    public function test_custom_session_directories_must_be_private_unless_opted_out(): void
    {
        if (!function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX permissions are required.');
        }

        $directory = sys_get_temp_dir() . '/scraper-session-shared-' . bin2hex(random_bytes(4));
        mkdir($directory, 0755);
        chmod($directory, 0755);
        $write = static function (string $path): void {
            file_put_contents($path, '{}');
        };

        try {
            $strict = BrowserSessionState::fromOptions(['session_id' => 's1', 'session_directory' => $directory]);
            self::assertNotNull($strict);
            try {
                $strict->persist($write);
                self::fail('A group/world-readable session directory must be refused.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('0700', $exception->getMessage());
            }

            $relaxed = BrowserSessionState::fromOptions(['session_id' => 's1', 'session_directory' => $directory, 'allow_shared_session_directory' => true]);
            self::assertNotNull($relaxed);
            $relaxed->persist($write);
            $files = glob($directory . '/*.json') ?: [];
            self::assertCount(1, $files);
            self::assertSame(0600, fileperms($files[0]) & 0777);
            array_map('unlink', $files);
        } finally {
            rmdir($directory);
        }
    }

    public function test_snapshots_sanitize_every_url_attribute_and_still_match_when_only_the_secret_changes(): void
    {
        $leaks = [
            'https://user:password@example.test/page',
            '/page#access_token=secret',
            '/page?credential=secret',
            '/page?sid=secret',
            '/page?my_clientToken=secret',
        ];
        $url = 'https://example.test/list';
        $store = new InMemorySnapshotStore();
        $matcher = new AdaptiveMatcher();

        foreach ($leaks as $href) {
            $page = Page::fromHtml('<main><a class="go" href="' . $href . '" data-url="https://u:p@example.test/x?token=secret">Open</a></main>', $url, $store, $matcher);
            $snapshot = ElementSnapshot::capture($page->css('a')->first() ?? self::fail('fixture'), $url);
            $stored = (string) json_encode($snapshot->toArray());

            self::assertStringNotContainsString('secret', $stored, $href);
            self::assertStringNotContainsString('password', $stored, $href);
            self::assertArrayHasKey('href', $snapshot->attributes, 'The sanitized link is kept as a matching signal.');
        }

        // Same link, different per-session token: the sanitized snapshot must still recognise it.
        $before = Page::fromHtml('<main><a class="go" href="/page?item=7&sid=OLDTOKEN">Open</a></main>', $url, $store, $matcher);
        $snapshot = ElementSnapshot::capture($before->css('a')->first() ?? self::fail('fixture'), $url);
        $after = Page::fromHtml('<main><a class="go" href="/page?item=7&sid=NEWTOKEN">Open</a></main>', $url, $store, $matcher);
        $result = $matcher->match($after, $snapshot);

        self::assertTrue($result->accepted);
        self::assertSame(1.0, $result->bestMatch?->signals['href'] ?? null);
    }
}
