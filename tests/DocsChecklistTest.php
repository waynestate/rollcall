<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

final class DocsChecklistTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function touchedFiles(): array
    {
        $root = dirname(__DIR__);

        return [
            'MAINTAINING.md' => file_get_contents($root . '/MAINTAINING.md'),
            'OPEN_QUESTIONS.md' => file_get_contents($root . '/OPEN_QUESTIONS.md'),
            'CHANGELOG.md' => file_get_contents($root . '/CHANGELOG.md'),
        ];
    }

    public function test_maintaining_has_the_smoke_test_checklist(): void
    {
        $maintaining = $this->touchedFiles()['MAINTAINING.md'];

        $this->assertStringContainsString('Smoke test on every OS', $maintaining);
        $this->assertStringContainsString('parity.sh', $maintaining);
        $this->assertStringContainsString('parity.ps1', $maintaining);
        $this->assertStringContainsString('a11y.sh', $maintaining);
        $this->assertStringContainsString('a11y.ps1', $maintaining);
        $this->assertStringContainsString('docker build -t rollcall-collector', $maintaining);
    }

    public function test_open_questions_no_longer_says_built_and_hand_written(): void
    {
        $openQuestions = $this->touchedFiles()['OPEN_QUESTIONS.md'];

        $this->assertStringNotContainsString('built and hand-written', $openQuestions);
    }

    public function test_touched_files_have_no_em_or_en_dashes(): void
    {
        foreach ($this->touchedFiles() as $name => $content) {
            $this->assertStringNotContainsString("\u{2014}", $content, $name . ' has an em dash');
            $this->assertStringNotContainsString("\u{2013}", $content, $name . ' has an en dash');
        }
    }
}
