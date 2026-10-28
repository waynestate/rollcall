<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Source\GitLab\GitLabRepositorySource;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * Recorded-fixture tests: a MockHttpClient plays GitLab back from files. No
 * network, no live host, and the secret in these tests is a made-up string.
 */
final class GitLabRepositorySourceTest extends TestCase
{
    private const BASE = 'https://gitlab.example.test';

    private const TOKEN = 'glpat-not-a-real-token';

    /** @var list<array{method: string, url: string, token: ?string}> */
    private array $requests = [];

    /** @var list<int> */
    private array $slept = [];

    private function fixture(string $name): string
    {
        return (string) file_get_contents(Fleet::FIXTURES . '/gitlab/' . $name);
    }

    /**
     * @param  callable(string, string): MockResponse  $respond  method and url to response
     */
    private function source(callable $respond, int $retries = 3): GitLabRepositorySource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($respond): MockResponse {
            $token = null;
            foreach ($options['headers'] as $header) {
                if (str_starts_with(strtolower($header), 'private-token:')) {
                    $token = trim(substr($header, 14));
                }
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'token' => $token];

            return $respond($method, $url);
        });
        $psr17 = new Psr17Factory();

        return new GitLabRepositorySource(
            new Psr18Client($http, $psr17, $psr17),
            $psr17,
            self::BASE,
            self::TOKEN,
            ['demo'],
            function (int $seconds): void {
                $this->slept[] = $seconds;
            },
            $retries,
        );
    }

    private function listing(string $method, string $url): ?MockResponse
    {
        if (str_contains($url, '/groups/demo/projects') && ! str_contains($url, 'page=2')) {
            return new MockResponse($this->fixture('projects-page1.json'), ['response_headers' => ['Link: <' . self::BASE . '/api/v4/groups/demo/projects?page=2&per_page=100>; rel="next"']]);
        }
        if (str_contains($url, 'page=2')) {
            return new MockResponse($this->fixture('projects-page2.json'));
        }

        return null;
    }

    public function test_it_lists_projects_with_subgroups_and_follows_the_link_header(): void
    {
        $source = $this->source(fn(string $m, string $u): MockResponse => $this->listing($m, $u) ?? new MockResponse('', ['http_code' => 500]));

        $this->assertSame(['demo/ledger', 'demo/relic', 'demo/sub/atlas'], $source->projects());
        $this->assertStringContainsString('include_subgroups=true', $this->requests[0]['url']);
        $this->assertStringContainsString('simple=true', $this->requests[0]['url']);
        $this->assertCount(2, $this->requests, 'one request per page');
    }

    public function test_it_reads_a_file_by_head_then_raw_at_the_default_branch(): void
    {
        $source = $this->source(function (string $method, string $url): MockResponse {
            if ($listing = $this->listing($method, $url)) {
                return $listing;
            }
            if ($method === 'HEAD') {
                return new MockResponse('', ['response_headers' => ['X-Gitlab-Blob-Id: abc123']]);
            }

            return new MockResponse($this->fixture('composer.json'));
        });

        $file = $source->file('demo/ledger', 'composer.json');

        $this->assertTrue($file->isFound());
        $this->assertSame('abc123', $file->blobId);
        $this->assertStringContainsString('"demo/ledger"', (string) $file->content);
        $files = array_values(array_filter($this->requests, static fn(array $r): bool => str_contains($r['url'], '/repository/files/')));
        $this->assertSame(['HEAD', 'GET'], array_column($files, 'method'));
        $this->assertStringContainsString('/projects/101/repository/files/composer.json/raw?ref=main', $files[1]['url']);
    }

    public function test_an_unchanged_blob_skips_the_body(): void
    {
        $source = $this->source(function (string $method, string $url): MockResponse {
            return $this->listing($method, $url) ?? new MockResponse('', ['response_headers' => ['X-Gitlab-Blob-Id: abc123']]);
        });

        $file = $source->file('demo/ledger', 'composer.lock', 'abc123');

        $this->assertTrue($file->isUnchanged());
        $files = array_filter($this->requests, static fn(array $r): bool => str_contains($r['url'], '/repository/files/'));
        $this->assertCount(1, $files);
        $this->assertSame('HEAD', array_values($files)[0]['method']);
    }

    public function test_a_missing_file_is_missing_not_an_error(): void
    {
        $source = $this->source(fn(string $m, string $u): MockResponse => $this->listing($m, $u) ?? new MockResponse('', ['http_code' => 404]));
        $this->assertSame('missing', $source->file('demo/relic', 'composer.lock')->status);
        $this->assertStringContainsString('ref=HEAD', $this->requests[array_key_last($this->requests)]['url'], 'a project without a default branch falls back to HEAD');
    }

    public function test_a_429_waits_for_retry_after_and_tries_again(): void
    {
        $calls = 0;
        $source = $this->source(function (string $method, string $url) use (&$calls): MockResponse {
            if ($listing = $this->listing($method, $url)) {
                return $listing;
            }
            $calls++;

            return $calls < 3
                ? new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 7']])
                : new MockResponse('', ['response_headers' => ['X-Gitlab-Blob-Id: zzz']]);
        });

        $file = $source->file('demo/ledger', 'composer.json', 'zzz');

        $this->assertTrue($file->isUnchanged());
        $this->assertSame([7, 7], $this->slept);
    }

    public function test_a_huge_retry_after_is_capped(): void
    {
        $source = $this->source(function (string $method, string $url): MockResponse {
            return $this->listing($method, $url) ?? new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 99999']]);
        }, 1);

        try {
            $source->file('demo/ledger', 'composer.json');
            $this->fail('expected a rate-limit error');
        } catch (RollcallException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
        }
        $this->assertSame([120], $this->slept);
    }

    public function test_an_unauthorised_token_stops_the_scan_loudly(): void
    {
        $source = $this->source(fn(): MockResponse => new MockResponse('', ['http_code' => 401]));

        try {
            $source->projects();
            $this->fail('expected an auth error');
        } catch (RollcallException $e) {
            $this->assertStringContainsString('refused the token', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_it_only_ever_sends_get_and_head_and_always_the_token_header(): void
    {
        $source = $this->source(function (string $method, string $url): MockResponse {
            return $this->listing($method, $url) ?? ($method === 'HEAD'
                ? new MockResponse('', ['response_headers' => ['X-Gitlab-Blob-Id: q']])
                : new MockResponse($this->fixture('composer.lock')));
        });

        $source->projects();
        $source->file('demo/ledger', 'composer.lock');

        foreach ($this->requests as $request) {
            $this->assertContains($request['method'], ['GET', 'HEAD']);
            $this->assertSame(self::TOKEN, $request['token']);
            $this->assertStringStartsWith(self::BASE . '/', $request['url']);
        }
    }

    public function test_a_next_link_to_another_host_is_refused_so_the_token_stays_home(): void
    {
        $source = $this->source(fn(): MockResponse => new MockResponse('[]', ['response_headers' => ['Link: <https://evil.example.test/api/v4/groups/demo/projects?page=2>; rel="next"']]));

        $this->expectExceptionMessage('different host');
        $source->projects();
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $source = $this->source(fn(string $m, string $u): MockResponse => $this->listing($m, $u) ?? new MockResponse('', ['http_code' => 500]));
        $this->expectException(RollcallException::class);
        $source->file('not/listed', 'composer.json');
    }

    public function test_the_url_must_be_https_and_the_token_must_exist(): void
    {
        $psr17 = new Psr17Factory();
        $client = new Psr18Client(new MockHttpClient(), $psr17, $psr17);

        try {
            new GitLabRepositorySource($client, $psr17, 'http://gitlab.example.test', self::TOKEN, ['demo']);
            $this->fail('http must be refused');
        } catch (RollcallException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
        }
        $this->expectExceptionMessage('ROLLCALL_GITLAB_TOKEN');
        new GitLabRepositorySource($client, $psr17, self::BASE, '', ['demo']);
    }
}
