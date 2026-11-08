<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Console\DigestCommand;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Owner\Owners;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;
use Waynestate\Rollcall\Tests\Support\RecordingTransport;

final class DigestTest extends TestCase
{
    private string $store;

    private RecordingTransport $transport;

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
        $this->transport = new RecordingTransport();
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
        $app->add(new DigestCommand([
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $this->store,
            'ROLLCALL_OWNERS_FILE' => Fleet::FIXTURES . '/owners.json',
            ...$extra,
        ], $this->transport));

        return new ApplicationTester($app);
    }

    /** @param  array<string, mixed>  $input */
    private function digest(array $input = [], array $extra = []): ApplicationTester
    {
        $tester = $this->tester($extra);
        $tester->run(['command' => 'digest', '--today' => '2026-11-07', ...$input], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function save(Snapshot $snapshot): void
    {
        (new JsonFileSnapshotStore($this->store))->save($snapshot);
    }

    /**
     * Last month demo/ledger ran PHP 8.1 and demo/atlas passed PROCTOR; this
     * month ledger is on 8.4 and atlas fails. demo/relic did not change.
     */
    private function twoScansWithChanges(): void
    {
        $now = Fleet::snapshot('2026-11-07');
        $before = [];
        foreach ($now->apps as $app) {
            $before[] = match ($app->id) {
                'demo/ledger' => $app->with(imageTag: 'php:8.1-apache', phpImage: '8.1'),
                'demo/atlas' => $app->with(proctorResult: 'pass'),
                default => $app,
            };
        }
        $this->save(new Snapshot(new DateTimeImmutable('2026-10-01'), $before, $now->errors));
        $this->save($now);
    }

    public function test_without_an_owners_file_nobody_gets_a_digest(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->digest([], ['ROLLCALL_OWNERS_FILE' => '']);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('No owners file', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function test_the_first_scan_gives_each_opted_in_owner_a_first_roll_call(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->digest();
        $out = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertStringContainsString('To: ledger-team@example.test', $out);
        $this->assertStringContainsString('To: relic-team@example.test', $out);
        $this->assertStringContainsString('First roll call: 2 of your 2 apps answered present.', $out);
        $this->assertStringNotContainsString('quiet-team@example.test', $out, 'an owner who did not opt in gets nothing');
        $this->assertStringNotContainsString('demo/broken', $out);
    }

    public function test_an_owner_reads_only_their_own_apps_and_what_changed(): void
    {
        $this->twoScansWithChanges();
        $tester = $this->digest();
        $out = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertStringContainsString('Subject: Rollcall: ', $out);
        $this->assertStringContainsString('(since 2026-10-01)', $out);
        $this->assertStringContainsString('demo/ledger: upgraded from end-of-life PHP 8.1 to supported PHP 8.4. Present!', $out);
        $this->assertStringContainsString('demo/ledger: no longer runs an end-of-life PHP image', $out);
        $this->assertStringContainsString('demo/atlas: PROCTOR result pass to fail', $out);
        $this->assertStringContainsString('demo/atlas: now has a failing PROCTOR result', $out);
        $this->assertStringContainsString('demo/ledger: PHP image 8.4 (active)', $out);
        $this->assertStringNotContainsString('demo/relic', $out, 'relic did not change, and it is not this owner\'s app');
        $this->assertStringNotContainsString('relic-team', $out, 'nothing changed for relic-team, so it gets no digest');
        $this->assertStringContainsString('1 of 2 opted-in owners had news; the other 1 get nothing this time.', $tester->getErrorOutput());
    }

    public function test_a_forced_upgrade_date_from_citadel_is_news(): void
    {
        $now = Fleet::snapshot('2026-11-07');
        $this->save(new Snapshot(new DateTimeImmutable('2026-10-01'), $now->apps, $now->errors));
        $apps = array_map(static fn($app) => $app->id === 'demo/relic' ? $app->with(upgradeDue: '2026-12-15') : $app, $now->apps);
        $this->save(new Snapshot($now->date, $apps, $now->errors));
        $out = $this->digest()->getDisplay();

        $this->assertStringContainsString('demo/relic: a forced image upgrade is scheduled, due 2026-12-15', $out);
        $this->assertStringContainsString('PROCTOR none. Forced image upgrade due 2026-12-15. Open items:', $out);
        $this->assertStringNotContainsString('ledger-team', $out);
    }

    public function test_nothing_changed_means_no_digest_at_all(): void
    {
        $this->save(Fleet::snapshot('2026-10-01'));
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->digest(['--send' => true], [
            'ROLLCALL_DIGEST_MAIL_ENABLED' => 'true',
            'ROLLCALL_DIGEST_MAIL_DSN' => 'null://null',
            'ROLLCALL_DIGEST_MAIL_FROM' => 'rollcall@example.test',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame([], $this->transport->sent);
        $this->assertStringContainsString('0 of 2 opted-in owners had news', $tester->getErrorOutput());
    }

    public function test_an_owner_who_has_not_opted_in_cannot_be_picked(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->digest(['--owner' => 'quiet-team@example.test']);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('has not opted in', $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
    }

    public function test_one_owner_can_be_picked(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $out = $this->digest(['--owner' => 'relic-team@example.test'])->getDisplay();

        $this->assertStringContainsString('To: relic-team@example.test', $out);
        $this->assertStringNotContainsString('ledger-team', $out);
    }

    public function test_send_is_refused_unless_sending_is_switched_on(): void
    {
        $this->twoScansWithChanges();
        $tester = $this->digest(['--send' => true], ['ROLLCALL_DIGEST_MAIL_DSN' => 'null://null', 'ROLLCALL_DIGEST_MAIL_FROM' => 'rollcall@example.test']);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('Sending is off', $tester->getErrorOutput());
        $this->assertSame([], $this->transport->sent);

        $tester = $this->digest(['--send' => true], ['ROLLCALL_DIGEST_MAIL_ENABLED' => 'true']);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('ROLLCALL_DIGEST_MAIL_DSN', $tester->getErrorOutput());
        $this->assertSame([], $this->transport->sent);
    }

    public function test_switching_sending_on_without_the_flag_sends_nothing(): void
    {
        $this->twoScansWithChanges();
        $tester = $this->digest([], [
            'ROLLCALL_DIGEST_MAIL_ENABLED' => 'true',
            'ROLLCALL_DIGEST_MAIL_DSN' => 'null://null',
            'ROLLCALL_DIGEST_MAIL_FROM' => 'rollcall@example.test',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->transport->sent);
    }

    public function test_send_mails_each_owner_with_news_only_their_digest(): void
    {
        $this->twoScansWithChanges();
        $tester = $this->digest(['--send' => true], [
            'ROLLCALL_DIGEST_MAIL_ENABLED' => 'true',
            'ROLLCALL_DIGEST_MAIL_DSN' => 'null://null',
            'ROLLCALL_DIGEST_MAIL_FROM' => 'rollcall@example.test',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        $this->assertSame('ledger-team@example.test', $mail->getTo()[0]->getAddress());
        $this->assertSame('rollcall@example.test', $mail->getFrom()[0]->getAddress());
        $this->assertMatchesRegularExpression('/^Rollcall: \d+ changes in your apps \(scan of 2026-11-07\)$/', (string) $mail->getSubject());
        $this->assertStringContainsString('demo/ledger', (string) $mail->getTextBody());
        $this->assertStringNotContainsString('demo/relic', (string) $mail->getTextBody());
        $this->assertNull($mail->getHtmlBody(), 'plain text only');
        $this->assertStringContainsString('Sent 1 digest.', $tester->getErrorOutput());
    }

    public function test_a_contact_that_is_not_a_mail_address_stops_the_send_before_anything_goes_out(): void
    {
        $this->twoScansWithChanges();
        $owners = Fleet::tempDir();
        try {
            file_put_contents($owners . '/owners.json', json_encode(['owners' => [
                ['contact' => 'ledger-team@example.test', 'digest' => true, 'projects' => ['demo/ledger']],
                ['contact' => 'the atlas people', 'digest' => true, 'projects' => ['demo/atlas']],
            ]]));
            $tester = $this->digest(['--send' => true], [
                'ROLLCALL_OWNERS_FILE' => $owners . '/owners.json',
                'ROLLCALL_DIGEST_MAIL_ENABLED' => 'true',
                'ROLLCALL_DIGEST_MAIL_DSN' => 'null://null',
                'ROLLCALL_DIGEST_MAIL_FROM' => 'rollcall@example.test',
            ]);

            $this->assertSame(2, $tester->getStatusCode());
            $this->assertStringContainsString('is not a mail address, so nothing was sent', $tester->getErrorOutput());
            $this->assertSame([], $this->transport->sent);
        } finally {
            Fleet::remove($owners);
        }
    }

    public function test_out_writes_one_file_per_digest_with_news(): void
    {
        $this->twoScansWithChanges();
        $dir = $this->store . '/digests';
        $tester = $this->digest(['--out' => $dir]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $files = glob($dir . '/*.txt') ?: [];
        $this->assertCount(1, $files);
        $this->assertSame('2026-11-07-ledger-team-example-test.txt', basename($files[0]));
        $this->assertStringStartsWith("To: ledger-team@example.test\nSubject: Rollcall: ", (string) file_get_contents($files[0]));
        $this->assertStringContainsString('Wrote 1 digest file to', $tester->getErrorOutput());
    }

    public function test_json_lists_the_digests_with_news(): void
    {
        $this->twoScansWithChanges();
        $json = json_decode($this->digest(['--format' => 'json'])->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame('internal', $json['visibility']);
        $this->assertSame('2026-10-01', $json['since']);
        $this->assertSame(2, $json['opted_in']);
        $this->assertSame(1, $json['quiet']);
        $this->assertSame('ledger-team@example.test', $json['digests'][0]['to']);
        $this->assertSame(['demo/ledger', 'demo/atlas'], $json['digests'][0]['apps']);
    }

    public function test_when_every_app_has_an_owner_nothing_is_said_about_owners(): void
    {
        $this->save(Fleet::snapshot('2026-11-07'));
        $tester = $this->digest(['--format' => 'json']);
        $json = json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame([], $json['no_owner_on_file'], 'the fixture owners file lists all four apps');
        $this->assertStringNotContainsString('No owner on file', $tester->getErrorOutput());
    }

    public function test_an_app_without_an_owner_is_named_not_dropped(): void
    {
        $this->twoScansWithChanges();
        $owners = Fleet::tempDir();
        try {
            // demo/broken and demo/relic are in the scan, but in no entry: nobody answers for them.
            file_put_contents($owners . '/owners.json', json_encode(['owners' => [
                ['contact' => 'ledger-team@example.test', 'digest' => true, 'projects' => ['demo/ledger', 'demo/atlas']],
            ]]));
            $env = ['ROLLCALL_OWNERS_FILE' => $owners . '/owners.json'];

            $tester = $this->digest([], $env);
            $errors = $tester->getErrorOutput();
            $this->assertSame(0, $tester->getStatusCode(), $errors);
            $this->assertStringContainsString('To: ledger-team@example.test', $tester->getDisplay());
            $this->assertStringContainsString('No owner on file for 2 of 4 apps in this scan, so no digest covers them. Add them to the owners file:', $errors);
            $this->assertStringContainsString('- demo/relic: no owner on file', $errors);
            $this->assertStringContainsString('- demo/broken: no owner on file', $errors);
            $this->assertStringNotContainsString('no owner on file', $tester->getDisplay(), 'the note goes to stderr, so a mail pipe on stdout stays clean');

            $json = json_decode($this->digest(['--format' => 'json'], $env)->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
            $unowned = $json['no_owner_on_file'];
            sort($unowned);
            $this->assertSame(['demo/broken', 'demo/relic'], $unowned);

            $quiet = $this->digest(['--out' => $this->store . '/digests'], $env);
            $this->assertStringContainsString('- demo/broken: no owner on file', $quiet->getErrorOutput(), '--out says it too');
        } finally {
            Fleet::remove($owners);
        }
    }

    public function test_an_owner_who_has_not_opted_in_still_counts_as_an_owner(): void
    {
        $owners = Owners::fromFile(Fleet::FIXTURES . '/owners.json');

        // quiet-team has not opted in, but it answers for demo/broken, so demo/broken has an owner on file.
        $this->assertSame([], $owners->unowned(['demo/ledger', 'demo/atlas', 'demo/relic', 'demo/broken']));
        $this->assertSame(['demo/new'], $owners->unowned(['demo/ledger', 'demo/new', 'demo/new']));
    }

    public function test_the_owners_file_is_checked_before_anything_is_built(): void
    {
        $dir = Fleet::tempDir();
        try {
            foreach ([
                '{"owners": {"a": 1}}' => 'an "owners" list',
                '{"owners": [{"contact": "", "projects": []}]}' => 'needs a "contact"',
                '{"owners": [{"contact": "a@example.test", "digest": "yes", "projects": []}]}' => 'not true or false',
                '{"owners": [{"contact": "a@example.test", "projects": ["not a path"]}]}' => 'not a group/project path',
                '{"owners": [{"contact": "a@example.test", "projects": []}, {"contact": "A@example.test", "projects": []}]}' => 'repeats the contact',
            ] as $json => $message) {
                file_put_contents($dir . '/owners.json', $json);
                try {
                    Owners::fromFile($dir . '/owners.json');
                    $this->fail('accepted ' . $json);
                } catch (RollcallException $e) {
                    $this->assertStringContainsString($message, $e->getMessage());
                }
            }
        } finally {
            Fleet::remove($dir);
        }
    }

    public function test_digest_is_opt_in_when_the_key_is_left_out(): void
    {
        $owners = Owners::fromFile(Fleet::FIXTURES . '/owners.json');

        $this->assertCount(3, $owners->owners);
        $this->assertSame(['ledger-team@example.test', 'relic-team@example.test'], array_map(static fn($o): string => $o->contact, $owners->optedIn()));
    }

    public function test_the_mail_dsn_is_a_secret(): void
    {
        $config = Config::fromEnv(['ROLLCALL_DIGEST_MAIL_DSN' => 'smtp://user:hunter2@mail.example.test:25']);

        $this->assertStringNotContainsString('hunter2', print_r($config, true));
        $this->assertSame('smtp://user:hunter2@mail.example.test:25', $config->digestMailDsn());
    }

    public function test_a_listed_project_the_scan_has_not_seen_is_no_news(): void
    {
        // An owner can list a project the scan has not seen yet; it reads "not in this scan" and is no change.
        $this->save(Fleet::snapshot('2026-10-01'));
        $this->save(Fleet::snapshot('2026-11-07'));
        $owners = Fleet::tempDir();
        try {
            file_put_contents($owners . '/owners.json', json_encode(['owners' => [
                ['contact' => 'new-team@example.test', 'digest' => true, 'projects' => ['demo/not-yet']],
            ]]));
            $tester = $this->digest([], ['ROLLCALL_OWNERS_FILE' => $owners . '/owners.json']);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertSame('', $tester->getDisplay());
        } finally {
            Fleet::remove($owners);
        }
    }
}
