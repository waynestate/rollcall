<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source\GitLab;

use DateTimeImmutable;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Source\RepoFile;
use Waynestate\Rollcall\Source\RepositorySource;
use Waynestate\Rollcall\Source\TokenExpiry;

/**
 * Reads repositories through the GitLab REST API, and only reads them.
 *
 * - Methods are limited to GET and HEAD in one place (send()), so no code path
 *   here can open an issue, a merge request or a comment.
 * - Files come from the file endpoints, never a clone. A HEAD request returns
 *   the blob id; the body is fetched only when it differs from the last scan.
 * - Pagination follows the Link header, and only to the configured host: the
 *   token must never ride along to a URL the API handed back for somewhere else.
 * - HTTP 429 waits for Retry-After and tries again, a bounded number of times.
 * - An unauthorised token stops the scan loudly. A silent partial scan would
 *   read as "the fleet got better".
 * - tokenExpiry() asks the instance for the calling token's own record
 *   (personal_access_tokens/self) so a person can rotate it before it dies.
 *   That check is advisory: any failure there is null, never an exception.
 *
 * The token comes from the caller (env var). It is never put in a message.
 */
final class GitLabRepositorySource implements RepositorySource, TokenExpiry
{
    private const MAX_PAGES = 500;

    private const MAX_RETRY_WAIT = 120;

    /** @var array<string, array{id: int, ref: string}>|null */
    private ?array $catalogue = null;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param  list<string>  $groups  group ids or full paths
     * @param  callable(int): void|null  $sleeper  waits that many seconds; replaced in tests
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly array $groups,
        ?callable $sleeper = null,
        private readonly int $maxRetries = 3,
    ) {
        $parts = parse_url($baseUrl);
        if ($token === '') {
            throw new RollcallException('No GitLab token. Set ROLLCALL_GITLAB_TOKEN (a read_api token).');
        }
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new RollcallException('ROLLCALL_GITLAB_URL must be an https URL.');
        }
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function projects(): array
    {
        $ids = array_keys($this->catalogue());
        sort($ids);

        return $ids;
    }

    public function file(string $project, string $path, ?string $knownBlobId = null): RepoFile
    {
        $entry = $this->catalogue()[$project] ?? null;
        if ($entry === null) {
            throw new RollcallException('Unknown project; list projects before reading its files.');
        }
        $url = sprintf(
            '%s/api/v4/projects/%d/repository/files/%s',
            $this->origin(),
            $entry['id'],
            rawurlencode($path),
        );
        $query = '?ref=' . rawurlencode($entry['ref']);

        $head = $this->send('HEAD', $url . $query);
        if ($head->getStatusCode() === 404) {
            return RepoFile::missing();
        }
        $this->expectOk($head);
        $blob = $head->getHeaderLine('X-Gitlab-Blob-Id');
        if ($blob !== '' && $knownBlobId !== null && hash_equals($knownBlobId, $blob)) {
            return RepoFile::unchanged($blob);
        }

        $raw = $this->send('GET', $url . '/raw' . $query);
        if ($raw->getStatusCode() === 404) {
            return RepoFile::missing();
        }
        $this->expectOk($raw);

        return RepoFile::found((string) $raw->getBody(), $blob !== '' ? $blob : null);
    }

    /**
     * The date the calling token expires, or null when it never expires or
     * the answer cannot be read. Advisory by contract: a refused (401, 403,
     * 404), non-2xx, unreachable or unparseable answer is null, never an
     * exception, so this check can never fail a scan. The token value and
     * the response body never appear in any message.
     */
    public function tokenExpiry(): ?DateTimeImmutable
    {
        try {
            $response = $this->send('GET', $this->origin() . '/api/v4/personal_access_tokens/self');
        } catch (RollcallException) {
            return null;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return null;
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || ! isset($data['expires_at']) || ! is_string($data['expires_at'])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['expires_at']);

        return $date === false ? null : $date;
    }

    /** @return array<string, array{id: int, ref: string}> */
    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }
        $catalogue = [];
        foreach ($this->groups as $group) {
            $next = sprintf(
                '%s/api/v4/groups/%s/projects?include_subgroups=true&simple=true&archived=false&per_page=100',
                $this->origin(),
                rawurlencode($group),
            );
            for ($page = 0; $next !== null && $page < self::MAX_PAGES; $page++) {
                $response = $this->send('GET', $next);
                $this->expectOk($response);
                foreach ($this->decodeList((string) $response->getBody()) as $project) {
                    if (is_int($project['id'] ?? null) && is_string($project['path_with_namespace'] ?? null)) {
                        $catalogue[$project['path_with_namespace']] = [
                            'id' => $project['id'],
                            'ref' => is_string($project['default_branch'] ?? null) && $project['default_branch'] !== '' ? $project['default_branch'] : 'HEAD',
                        ];
                    }
                }
                $next = $this->nextLink($response);
            }
        }

        return $this->catalogue = $catalogue;
    }

    /** @return list<array<mixed>> */
    private function decodeList(string $body): array
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RollcallException('GitLab sent a project list that is not JSON.');
        }
        if (! is_array($data)) {
            throw new RollcallException('GitLab sent an unexpected project list.');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function nextLink(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Link') as $header) {
            foreach (explode(',', $header) as $part) {
                if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $part, $m) === 1) {
                    return $this->sameOrigin($m[1]);
                }
            }
        }

        return null;
    }

    private function sameOrigin(string $url): string
    {
        $want = parse_url($this->baseUrl);
        $got = parse_url($url);
        if (
            $got === false
            || ($got['scheme'] ?? '') !== 'https'
            || strtolower($got['host'] ?? '') !== strtolower($want['host'] ?? '')
            || ($got['port'] ?? 443) !== ($want['port'] ?? 443)
        ) {
            throw new RollcallException('GitLab pointed to a page on a different host; refusing to send the token there.');
        }

        return $url;
    }

    private function origin(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    private function send(string $method, string $url): ResponseInterface
    {
        // The single gate for every request this class makes.
        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new RollcallException('ROLLCALL only reads: refusing to send a ' . $method . ' request.');
        }
        $request = $this->requests->createRequest($method, $url)
            ->withHeader('PRIVATE-TOKEN', $this->token)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'rollcall (Wayne State University fleet scanner)');

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->client->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                // The message comes from the transport and carries no header values.
                throw new RollcallException('Could not reach GitLab: ' . $e->getMessage());
            }
            if ($response->getStatusCode() !== 429) {
                return $response;
            }
            if ($attempt >= $this->maxRetries) {
                throw new RollcallException('GitLab is rate-limiting this scan (HTTP 429) and did not relent. Try again later.');
            }
            $wait = (int) $response->getHeaderLine('Retry-After');
            ($this->sleeper)(max(1, min($wait, self::MAX_RETRY_WAIT)));
        }
    }

    private function expectOk(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new RollcallException(sprintf('GitLab refused the token (HTTP %d). It may be expired, revoked or missing the read_api scope.', $status));
        }
        if ($status < 200 || $status >= 300) {
            throw new RollcallException(sprintf('GitLab answered HTTP %d.', $status));
        }
    }
}
