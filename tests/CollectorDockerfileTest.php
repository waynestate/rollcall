<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The collector Dockerfile and .dockerignore, judged as text:
 * a non-root user, an entry point, no token, a lean context, and no dashes.
 */
final class CollectorDockerfileTest extends TestCase
{
    private const DOCKERFILE = __DIR__ . '/../Dockerfile';

    private const DOCKERIGNORE = __DIR__ . '/../.dockerignore';

    private const README = __DIR__ . '/../README.md';

    public function testTheDockerfileExists(): void
    {
        $this->assertFileExists(self::DOCKERFILE);
    }

    public function testTheCollectorRunsAsANonRootUser(): void
    {
        $userLines = array_values(array_filter(
            $this->linesOf(self::DOCKERFILE),
            static fn(string $line): bool => str_starts_with(trim($line), 'USER'),
        ));
        $this->assertNotEmpty($userLines, 'The Dockerfile needs a USER line.');
        foreach ($userLines as $line) {
            $user = trim(substr(trim($line), 4));
            $this->assertNotSame('root', $user, 'The collector must not run as root.');
            $this->assertNotSame('0', $user, 'The collector must not run as uid 0.');
        }
    }

    public function testTheDockerfileSetsAnEntrypoint(): void
    {
        $entrypoints = array_values(array_filter(
            $this->linesOf(self::DOCKERFILE),
            static fn(string $line): bool => str_starts_with(trim($line), 'ENTRYPOINT'),
        ));
        $this->assertNotEmpty($entrypoints, 'The Dockerfile needs an ENTRYPOINT.');
    }

    public function testNoLineCarriesTheGitLabToken(): void
    {
        $tokenLines = array_values(array_filter(
            $this->linesOf(self::DOCKERFILE),
            static fn(string $line): bool => str_contains($line, 'ROLLCALL_GITLAB_TOKEN'),
        ));
        $this->assertSame([], $tokenLines, 'No line of the Dockerfile may carry the GitLab token name.');
    }

    public function testTheDockerignoreKeepsTheContextLean(): void
    {
        $entries = array_map('trim', $this->linesOf(self::DOCKERIGNORE));
        foreach (['tests', 'deploy', 'site', '.git', '.env'] as $entry) {
            $this->assertContains($entry, $entries, '.dockerignore must list ' . $entry . '.');
        }
    }

    public function testTheReadmeNamesTheDockerSection(): void
    {
        $this->assertStringContainsString(
            'Run the collector in Docker',
            $this->read(self::README),
            'The README needs a Docker section.',
        );
    }

    public function testNeitherFileUsesAnEmOrEnDash(): void
    {
        foreach ([self::DOCKERFILE, self::DOCKERIGNORE] as $path) {
            $text = $this->read($path);
            $this->assertStringNotContainsString("\u{2014}", $text, $path . ' must not use an em dash.');
            $this->assertStringNotContainsString("\u{2013}", $text, $path . ' must not use an en dash.');
        }
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path);
        $text = file_get_contents($path);
        $this->assertIsString($text);

        return $text;
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $path): array
    {
        $lines = preg_split('/\R/', $this->read($path));

        return is_array($lines) ? $lines : [];
    }
}
