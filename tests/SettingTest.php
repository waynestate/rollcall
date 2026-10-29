<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Playbook\Playbook;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Support\Setting;

require __DIR__ . '/fixtures/playbook-stub.php';

/**
 * ROLLCALL_GITLAB_URL is a WSU value: the environment wins, then PLAYBOOK
 * (the stub above stands in for the private package), then null. The GitLab
 * token never comes through here, so it has no test in this file.
 */
final class SettingTest extends TestCase
{
    private const KEY = 'ROLLCALL_GITLAB_URL';

    protected function setUp(): void
    {
        putenv(self::KEY);
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        Playbook::setValues([]);
    }

    protected function tearDown(): void
    {
        putenv(self::KEY);
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        Playbook::setValues([]);
    }

    public function test_the_environment_beats_playbook(): void
    {
        putenv(self::KEY . '=https://env.example.test');
        Playbook::setValues([self::KEY => 'https://playbook.example.test']);

        $this->assertSame('https://env.example.test', Setting::get(self::KEY));
        $this->assertSame('https://env.example.test', Config::fromEnv([])->gitlabUrl);
    }

    public function test_playbook_is_used_when_the_environment_has_none(): void
    {
        Playbook::setValues([self::KEY => 'https://cas.example.test']);

        $this->assertSame('https://cas.example.test', Setting::get(self::KEY));
        $this->assertSame('https://cas.example.test', Config::fromEnv([])->gitlabUrl);
    }

    public function test_a_missing_value_keeps_the_fatal_error_with_the_new_hint(): void
    {
        $this->assertNull(Setting::get(self::KEY));

        $factory = new Factory(Config::fromEnv(['ROLLCALL_SOURCE' => 'gitlab']));

        try {
            $factory->repositories();
            $this->fail('A gitlab source without its settings must refuse to start.');
        } catch (RollcallException $e) {
            $this->assertStringContainsString('ROLLCALL_GITLAB_URL', $e->getMessage());
            $this->assertStringContainsString('Set it in the environment, or install waynestate/playbook (see the README).', $e->getMessage());
        }
    }
}
