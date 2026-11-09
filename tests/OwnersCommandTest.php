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

    private string $ownersDir;

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
        $this->ownersDir = Fleet::tempDir();
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
        Fleet::remove($this->ownersDir);
    }

    /**
     * An owners file for the four fixture apps; each entry is [contact, confirmed or null, projects].
     *
     * @param  list<array{0: string, 1: string|null, 2: list<string>}>  $entries
     * @return array<string, string> the setting that points at it
     */
    private function ownersFile(array $entries): array
    {
        $owners = [];
        foreach ($entries as [$contact, $confirmed, $projects]) {
            $owners[] = ['contact' => $contact, 'projects' => $projects, ...($confirmed === null ? [] : ['confirmed' => $confirmed])];
        }
        $path = $this->ownersDir . '/owners-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode(['owners' => $owners]));

        return ['ROLLCALL_OWNERS_FILE' => $path];
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
        $this->assertStringContainsString('All 4 scanned apps have an owner on file, confirmed in the last 365 days.', $out);
        $this->assertSame('', $tester->getErrorOutput());
    }

    public function test_an_app_missing_from_the_owners_file_is_named_and_fails(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $owners = Fleet::tempDir();
        try {
            // demo/broken is in the scan, but in no entry: nobody answers for it.
            file_put_contents($owners . '/owners.json', json_encode(['owners' => [
                ['contact' => 'ledger-team@example.test', 'digest' => true, 'confirmed' => '2026-10-01', 'projects' => ['demo/ledger', 'demo/atlas']],
                ['contact' => 'relic-team@example.test', 'digest' => true, 'confirmed' => '2026-10-01', 'projects' => ['demo/relic']],
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
                ['contact' => 'ledger-team@example.test', 'digest' => true, 'confirmed' => '2026-10-01', 'projects' => ['demo/ledger', 'demo/atlas']],
                ['contact' => 'relic-team@example.test', 'digest' => true, 'confirmed' => '2026-10-01', 'projects' => ['demo/relic']],
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

    public function test_an_owner_not_confirmed_within_the_window_is_owner_unknown_and_fails(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        // Today is 2026-11-07 and the window is 365 days, so 2025-11-07 is the oldest date that still counts.
        $tester = $this->owners([], $this->ownersFile([
            ['ledger-team@example.test', '2025-11-07', ['demo/ledger']],
            ['atlas-team@example.test', '2025-11-06', ['demo/atlas']],
            ['relic-team@example.test', null, ['demo/relic']],
            ['broken-team@example.test', '2026-10-01', ['demo/broken']],
        ]));
        $out = $tester->getDisplay();

        $this->assertSame(1, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertStringContainsString('An owner counts as confirmed for 365 days after its "confirmed" date.', $out);
        $this->assertStringContainsString('2 of 4 scanned apps have an owner on file that nobody confirmed in the last 365 days:', $out);
        $this->assertStringContainsString('- demo/atlas (last confirmed 2025-11-06)', $out);
        $this->assertStringContainsString('- demo/relic (never confirmed)', $out);
        $this->assertStringNotContainsString('demo/ledger', $out, 'confirmed exactly 365 days ago still counts');
        $this->assertStringNotContainsString('demo/broken', $out);
        $this->assertStringNotContainsString('no owner on file:', $out);
        $this->assertStringContainsString('Owner unknown for 2 of 4 scanned apps. Ask who answers for them, then list or re-date them in the owners file.', $out);
    }

    public function test_one_fresh_confirmation_is_enough_and_a_future_date_is_not_trusted(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $json = json_decode($this->owners(['--format' => 'json'], $this->ownersFile([
            ['ledger-team@example.test', '2026-10-01', ['demo/ledger', 'demo/atlas', 'demo/broken']],
            // demo/atlas is also under a stale entry: the fresh one above answers for it.
            ['old-team@example.test', '2024-01-01', ['demo/atlas']],
            // A confirmation dated after today is a typo, not a promise.
            ['relic-team@example.test', '2026-12-01', ['demo/relic']],
        ]))->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame(365, $json['confirm_days']);
        $this->assertSame([], $json['unowned']);
        $this->assertSame([['app' => 'demo/relic', 'last_confirmed' => null]], $json['unconfirmed']);
        $this->assertSame(1, $json['owner_unknown']);
    }

    public function test_no_owner_and_no_confirmation_are_both_owner_unknown(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->owners(['--format' => 'json'], $this->ownersFile([
            ['ledger-team@example.test', '2026-10-01', ['demo/ledger', 'demo/atlas']],
            ['relic-team@example.test', '2025-01-01', ['demo/relic']],
        ]));
        $json = json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame(['demo/broken'], $json['unowned']);
        $this->assertSame([['app' => 'demo/relic', 'last_confirmed' => '2025-01-01']], $json['unconfirmed']);
        $this->assertSame(2, $json['owner_unknown']);
    }

    public function test_the_window_is_a_setting_and_zero_turns_the_check_off(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));

        // The fixture owners were confirmed on 2026-10-01: 37 days ago, inside 365 but outside 30.
        $tight = $this->owners(['--format' => 'json'], ['ROLLCALL_OWNERS_CONFIRM_DAYS' => '30']);
        $json = json_decode($tight->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $tight->getStatusCode());
        $this->assertSame(30, $json['confirm_days']);
        $this->assertCount(4, $json['unconfirmed']);

        $off = $this->owners([], ['ROLLCALL_OWNERS_CONFIRM_DAYS' => '0', ...$this->ownersFile([
            ['everyone@example.test', null, ['demo/ledger', 'demo/atlas', 'demo/relic', 'demo/broken']],
        ])]);
        $this->assertSame(0, $off->getStatusCode(), $off->getErrorOutput());
        $this->assertStringContainsString('Confirmation check: off (ROLLCALL_OWNERS_CONFIRM_DAYS=0).', $off->getDisplay());
        $this->assertStringContainsString('All 4 scanned apps have an owner on file.', $off->getDisplay());
    }

    public function test_a_bad_window_or_a_bad_date_is_a_tool_error(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));

        $window = $this->owners([], ['ROLLCALL_OWNERS_CONFIRM_DAYS' => 'yearly']);
        $this->assertSame(2, $window->getStatusCode());
        $this->assertStringContainsString('ROLLCALL_OWNERS_CONFIRM_DAYS must be a whole number of days (0 turns the check off).', $window->getErrorOutput());

        $date = $this->owners([], $this->ownersFile([['ledger-team@example.test', '2026-02-30', ['demo/ledger']]]));
        $this->assertSame(2, $date->getStatusCode());
        $this->assertStringContainsString('Owners file entry 1 has a "confirmed" that is not a YYYY-MM-DD date.', $date->getErrorOutput());
        $this->assertSame('', $date->getDisplay());
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
