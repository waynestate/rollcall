<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Waynestate\Playbook\Playbook;
use Waynestate\Rollcall\Config;

require_once __DIR__ . '/../fixtures/playbook-stub.php';

if (class_exists(\Orchestra\Testbench\TestCase::class)) {
    final class GitlabUrlConfigTest extends LaravelTestCase
    {
        protected function tune(\Illuminate\Contracts\Config\Repository $config): void
        {
            $config->set('rollcall.gitlab_url', 'https://config.example.test');
        }

        protected function setUp(): void
        {
            parent::setUp();
            putenv('ROLLCALL_GITLAB_URL=https://env.example.test');
            $_ENV['ROLLCALL_GITLAB_URL'] = 'https://env.example.test';
            $_SERVER['ROLLCALL_GITLAB_URL'] = 'https://env.example.test';
            Playbook::setValues(['ROLLCALL_GITLAB_URL' => 'https://playbook.example.test']);
        }

        protected function tearDown(): void
        {
            parent::tearDown();
            putenv('ROLLCALL_GITLAB_URL');
            unset($_ENV['ROLLCALL_GITLAB_URL'], $_SERVER['ROLLCALL_GITLAB_URL']);
            Playbook::setValues([]);
        }

        public function test_config_value_set_wins(): void
        {
            $this->assertSame('https://config.example.test', $this->app->make(Config::class)->gitlabUrl);
        }
    }
} else {
    final class GitlabUrlConfigTest extends TestCase
    {
        public function test_config_value_set_wins(): void
        {
            $this->markTestSkipped('Laravel (orchestra/testbench) is not installed in vendor; the gitlab_url config test needs it.');
        }
    }
}
