<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The token page promises "every call Rollcall makes". This keeps the promise:
 * each GitLab API path the adapter builds must be in that table, and the page
 * must name the one scope and the scopes the token must not have.
 */
final class TokenScopeDocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return list<string> the API paths in the GitLab adapter, placeholders written GitLab's way */
    private function apiPaths(): array
    {
        $code = (string) file_get_contents(self::ROOT . '/src/Source/GitLab/GitLabRepositorySource.php');
        preg_match_all('#/api/v4/[A-Za-z0-9_/%]+#', $code, $m);
        $paths = [];
        foreach ($m[0] as $path) {
            $path = preg_replace('#(files/)%[sd]#', '$1:file_path', $path) ?? $path;
            $paths[] = preg_replace('#%[sd]#', ':id', $path) ?? $path;
        }

        return array_values(array_unique($paths));
    }

    public function test_every_api_path_the_adapter_calls_is_in_the_token_table(): void
    {
        $docs = (string) file_get_contents(self::ROOT . '/docs/security.md');
        $paths = $this->apiPaths();

        $this->assertGreaterThanOrEqual(4, count($paths), 'the adapter should build its paths from literals');
        foreach ($paths as $path) {
            $this->assertStringContainsString($path, $docs, $path . ' is called but not in docs/security.md');
        }
        // The raw read is the files path plus /raw, appended at the call site.
        $this->assertStringContainsString('/api/v4/projects/:id/repository/files/:file_path/raw', $docs);
    }

    public function test_the_page_names_the_one_scope_the_role_and_the_scopes_it_must_not_have(): void
    {
        $docs = (string) file_get_contents(self::ROOT . '/docs/security.md');

        $this->assertStringContainsString('one scope, `read_api`, and one role, Reporter', $docs);
        foreach (['api', 'write_repository', 'read_repository', 'read_user', 'read_registry', 'self_rotate'] as $scope) {
            $this->assertMatchesRegularExpression('/^\| `' . $scope . '`/m', $docs, $scope . ' must be listed as a scope the token must not have');
        }
    }

    public function test_the_readme_and_env_example_ask_for_read_api_only(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        $env = (string) file_get_contents(self::ROOT . '/.env.example');

        $this->assertStringContainsString('read_api', $readme);
        $this->assertStringContainsString('docs/security.md', $readme);
        $this->assertStringContainsString('read_api', $env);
        $this->assertStringNotContainsString('scope api ', $env);
    }
}
