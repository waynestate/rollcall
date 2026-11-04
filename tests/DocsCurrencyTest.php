<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

final class DocsCurrencyTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function docsFiles(): array
    {
        $root = dirname(__DIR__);

        $this->assertFileExists($root . '/OPEN_QUESTIONS.md');
        $this->assertFileExists($root . '/CHANGELOG.md');

        return [
            'OPEN_QUESTIONS.md' => (string) file_get_contents($root . '/OPEN_QUESTIONS.md'),
            'CHANGELOG.md' => (string) file_get_contents($root . '/CHANGELOG.md'),
        ];
    }

    public function test_open_questions_no_longer_claims_the_deploy_and_override_storage_are_missing(): void
    {
        $openQuestions = $this->docsFiles()['OPEN_QUESTIONS.md'];

        $this->assertStringNotContainsString('no deploy is built', $openQuestions);
        $this->assertStringNotContainsString('Override storage owned by Rollcall', $openQuestions);
    }

    public function test_open_questions_records_the_override_and_mirror_decisions(): void
    {
        $openQuestions = $this->docsFiles()['OPEN_QUESTIONS.md'];

        $this->assertStringContainsString('PROCTOR is the one source of truth for overrides', $openQuestions);
        $this->assertStringContainsString('push mirror from nougat', $openQuestions);
    }

    public function test_changelog_records_the_docs_update(): void
    {
        $changelog = $this->docsFiles()['CHANGELOG.md'];

        $this->assertStringContainsString('override storage is closed', $changelog);
    }
}
