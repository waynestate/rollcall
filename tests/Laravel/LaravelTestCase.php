<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Waynestate\Rollcall\Laravel\RollcallServiceProvider;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

abstract class LaravelTestCase extends TestCase
{
    protected string $store = '';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [RollcallServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        $this->store = Fleet::tempDir();
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2026-10-28'));

        $config = $app['config'];
        // The web middleware group encrypts cookies, so the test app needs a key; this one is random per run.
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('rollcall.policy_file', Fleet::FIXTURES . '/policy.json');
        $config->set('rollcall.store_dir', $this->store);
        $config->set('rollcall.repos_dir', Fleet::FIXTURES . '/repos');
        $config->set('rollcall.images_file', Fleet::FIXTURES . '/images.json');
        $config->set('rollcall.proctor_dir', Fleet::FIXTURES . '/proctor');
        $this->tune($config);
    }

    /** Lets a test set its own switches before the provider's routes are registered. */
    protected function tune(\Illuminate\Contracts\Config\Repository $config): void {}

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->store !== '') {
            Fleet::remove($this->store);
        }
    }
}
