<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Console\OwnersCommand;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * `rollcall owners`: the register cross-check. The fixture owners file lists
 * all four scanned apps, so the default run exits 0; an owners file that
 * leaves an app out is named on stdout and the command exits 1.
 */
final class OwnersCommandTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
    }

    /** @param  array<string, string>  $extra */
    private function tester(array $extra = []): ApplicationTester
    {
        $app = new BaseApplication();
        $app->setAutoExit(false);
        $app->add(new OwnersCommand([
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $this->store,
            'ROLLCALL_OWNERS_FILE' => Fleet::FIXTURES . '/owners.json',
            ...$extra,
        ]));

        return new ApplicationTester($app);
    }

    /** @param  array<string, mixed>  $input */
    private function owners(array $input = [], array $extra = []): ApplicationTester
    {
        $tester = $this->tester($extra);
        $tester->run(['command' => 'owners', '--today' => '2026-11-07', ...$input], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function save(Snapshot $snapshot): void
    {
        (new JsonFileSnapshotStore($this->store))->save($snapshot);
    }

    public function test_every_app_owned_exits_zero_and_says_so(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->owners();
        $out = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertStringContainsString('Owners file: 3 owners, 2 opted in to the digest', $out);
        $this->assertStringContainsString('All 4 scanned apps have an owner on file.', $out);
        $this->assertSame('', $tester->getErrorOutput());
    }

    public function test_an_app_missing_from_the_owners_file_is_named_and_fails(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $owners = Fleet::tempDir();
        try {
            // demo/broken is in the scan, but in no entry: nobody answers for it.
            file_put_contents($owners . '/owners.json', json_encode(['owners' => [
                ['contact' => 'ledger-team@example.test', 'digest' => true, 'projects' => ['demo/ledger', 'demo/atlas']],
                ['contact' => 'relic-team@example.test', 'digest' => true, 'projects' => ['demo/relic']],
            ]]));
            $tester = $this->owners([], ['ROLLCALL_OWNERS_FILE' => $owners . '/owners.json']);
            $out = $tester->getDisplay();

            $this->assertSame(1, $tester->getStatusCode(), $tester->getErrorOutput());
            $this->assertStringContainsString('Owners file: 2 owners, 2 opted in to the digest', $out);
            $this->assertStringContainsString('1 of 4 scanned apps have no owner on file:', $out);
            $this->assertStringContainsString('- demo/broken', $out);
            $this->assertSame('', $tester->getErrorOutput());
        } finally {
            Fleet::remove($owners);
        }
    }

    public function test_json_carries_the_four_counts_and_the_unowned_list(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $owners = Fleet::tempDir();
        try {
            file_put_contents($owners . '/owners.json', json_encode(['owners' => [
                ['contact' => 'ledger-team@example.test', 'digest' => true, 'projects' => ['demo/ledger', 'demo/atlas']],
                ['contact' => 'relic-team@example.test', 'digest' => true, 'projects' => ['demo/relic']],
            ]]));
            $json = json_decode($this->owners(['--format' => 'json'], ['ROLLCALL_OWNERS_FILE' => $owners . '/owners.json'])->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

            $this->assertSame(4, $json['scanned']);
            $this->assertSame(2, $json['owners']);
            $this->assertSame(2, $json['opted_in']);
            $this->assertSame(['demo/broken'], $json['unowned']);
        } finally {
            Fleet::remove($owners);
        }
    }

    public function test_no_scan_yet_is_a_tool_error(): void
    {
        $tester = $this->owners();

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('No scan yet', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function test_without_an_owners_file_the_command_says_what_to_set(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->owners([], ['ROLLCALL_OWNERS_FILE' => '']);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('No owners file. Set ROLLCALL_OWNERS_FILE to the file that says who answers for which apps.', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }
}
