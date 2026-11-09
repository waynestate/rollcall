<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Console\Application;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Http\Endpoint;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * The contract suite: real output, produced by the real commands from the
 * fixtures, validated against the schemas in contract/. A field added to the
 * output without the contract (or the other way round) fails here, so the
 * shape a consumer reads cannot drift quietly. Roll call for the roll call.
 */
final class ContractTest extends TestCase
{
    private const CONTRACT = __DIR__ . '/../contract';

    private string $store;

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $this->store,
            'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
            'ROLLCALL_IMAGES_FILE' => Fleet::FIXTURES . '/images.json',
            'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/proctor',
            'ROLLCALL_OWNERS_FILE' => Fleet::FIXTURES . '/owners.json',
        ];
    }

    /** @param  array<string, mixed>  $input */
    private function rollcall(array $input): string
    {
        $app = new Application($this->env());
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $code = $tester->run($input, ['capture_stderr_separately' => true]);
        $this->assertSame(0, $code, $tester->getDisplay());

        return $tester->getDisplay();
    }

    /** Two scans, a month apart, so progress and the burn-down have a "before". */
    private function scanTwice(): void
    {
        $this->rollcall(['command' => 'scan', '--today' => '2026-10-01']);
        $this->rollcall(['command' => 'scan', '--today' => '2026-11-07']);
    }

    private function schemaId(string $name): string
    {
        return 'https://github.com/waynestate/rollcall/blob/main/contract/' . $name;
    }

    private function assertMatchesContract(string $schema, string $json): void
    {
        $validator = new Validator();
        foreach (glob(self::CONTRACT . '/*.schema.json') ?: [] as $file) {
            $validator->resolver()?->registerFile($this->schemaId(basename($file)), $file);
        }
        $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        $result = $validator->validate($data, $this->schemaId($schema));
        $error = $result->error();
        $this->assertTrue(
            $result->isValid(),
            $schema . ' rejects the output: ' . ($error === null ? '' : json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT)),
        );
    }

    private function assertBreaksContract(string $schema, string $json): void
    {
        $validator = new Validator();
        foreach (glob(self::CONTRACT . '/*.schema.json') ?: [] as $file) {
            $validator->resolver()?->registerFile($this->schemaId(basename($file)), $file);
        }
        $result = $validator->validate(json_decode($json, false, 64, JSON_THROW_ON_ERROR), $this->schemaId($schema));
        $this->assertFalse($result->isValid(), $schema . ' should refuse this output');
    }

    public function test_every_schema_is_json_with_an_id_that_names_its_file(): void
    {
        $files = glob(self::CONTRACT . '/*.schema.json') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $schema = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $this->assertIsArray($schema);
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null, basename($file));
            $this->assertSame($this->schemaId(basename($file)), $schema['$id'] ?? null, basename($file));
            $this->assertStringContainsString(basename($file), (string) file_get_contents(self::CONTRACT . '/README.md'), basename($file) . ' is missing from contract/README.md');
        }
    }

    public function test_the_public_json_report_matches_the_contract(): void
    {
        $this->scanTwice();
        $json = $this->rollcall(['command' => 'report', '--format' => 'json', '--today' => '2026-11-07']);

        $this->assertMatchesContract('report.schema.json', $json);
        $this->assertSame('public', json_decode($json, true)['visibility']);
    }

    public function test_the_internal_json_report_matches_the_contract(): void
    {
        $this->scanTwice();
        $json = $this->rollcall(['command' => 'report', '--format' => 'json', '--internal' => true, '--today' => '2026-11-07']);

        $this->assertMatchesContract('report.schema.json', $json);
        $this->assertCount(4, json_decode($json, true)['apps']);
    }

    public function test_a_first_scan_report_with_no_history_matches_the_contract(): void
    {
        $this->rollcall(['command' => 'scan', '--today' => '2026-11-07']);

        $this->assertMatchesContract('report.schema.json', $this->rollcall(['command' => 'report', '--format' => 'json', '--today' => '2026-11-07']));
        $this->assertMatchesContract('report.schema.json', $this->rollcall(['command' => 'report', '--format' => 'json', '--internal' => true, '--today' => '2026-11-07']));
    }

    public function test_report_json_from_the_endpoint_matches_the_contract(): void
    {
        $this->scanTwice();
        $config = Config::fromEnv([...$this->env(), 'ROLLCALL_PUBLIC_REPORT_ENABLED' => 'true']);
        [$status, , $body] = (new Endpoint($config, new Factory($config)))->handle('GET', '/report.json', null, '127.0.0.1');

        $this->assertSame(200, $status);
        $this->assertMatchesContract('report.schema.json', $body);
    }

    public function test_every_stored_snapshot_matches_the_contract(): void
    {
        $this->scanTwice();
        $files = glob($this->store . '/*.json') ?: [];
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertMatchesContract('snapshot.schema.json', (string) file_get_contents($file));
        }
    }

    public function test_the_image_list_fixture_matches_the_input_contract(): void
    {
        $this->assertMatchesContract('images-export.schema.json', (string) file_get_contents(Fleet::FIXTURES . '/images.json'));
    }

    public function test_the_citadel_export_fixture_matches_the_input_contract(): void
    {
        $this->assertMatchesContract('citadel-export.schema.json', (string) file_get_contents(Fleet::FIXTURES . '/citadel-export.json'));
    }

    public function test_a_report_and_snapshot_from_the_citadel_export_match_the_contract(): void
    {
        $env = [...$this->env(), 'ROLLCALL_CITADEL_EXPORT' => Fleet::FIXTURES . '/citadel-export.json'];
        unset($env['ROLLCALL_IMAGES_FILE']);
        $app = new Application($env);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $this->assertSame(0, $tester->run(['command' => 'scan', '--today' => '2026-11-07']));
        $tester->run(['command' => 'report', '--format' => 'json', '--internal' => true, '--today' => '2026-11-07'], ['capture_stderr_separately' => true]);
        $json = $tester->getDisplay();

        $this->assertMatchesContract('report.schema.json', $json);
        $this->assertSame(2, json_decode($json, true)['forced_upgrades']['pending']);
        $this->assertMatchesContract('snapshot.schema.json', (string) file_get_contents($this->store . '/2026-11-07.json'));
    }

    public function test_the_owners_fixture_matches_the_input_contract(): void
    {
        $this->assertMatchesContract('owners.schema.json', (string) file_get_contents(Fleet::FIXTURES . '/owners.json'));
    }

    public function test_rollcalls_own_owners_file_matches_the_input_contract(): void
    {
        $this->assertMatchesContract('owners.schema.json', (string) file_get_contents(dirname(__DIR__) . '/owners.json'));
    }

    public function test_the_json_digest_matches_the_contract(): void
    {
        $this->rollcall(['command' => 'scan', '--today' => '2026-11-07']);
        $json = $this->rollcall(['command' => 'digest', '--format' => 'json', '--today' => '2026-11-07']);

        $this->assertMatchesContract('digest.schema.json', $json);
        $this->assertCount(2, json_decode($json, true)['digests'], 'the first roll call goes to both opted-in owners');
    }

    public function test_the_contract_has_teeth(): void
    {
        $this->scanTwice();
        $public = json_decode($this->rollcall(['command' => 'report', '--format' => 'json', '--today' => '2026-11-07']), true);
        $internal = json_decode($this->rollcall(['command' => 'report', '--format' => 'json', '--internal' => true, '--today' => '2026-11-07']), true);

        // A public report that grew a per-app list or an owner is not a public report.
        $this->assertBreaksContract('report.schema.json', (string) json_encode([...$public, 'apps' => $internal['apps']]));
        $this->assertBreaksContract('report.schema.json', (string) json_encode([...$public, 'owner' => 'someone']));
        // An internal report without its list, or a drift kind nobody declared, is broken too.
        $withoutApps = $internal;
        unset($withoutApps['apps']);
        $this->assertBreaksContract('report.schema.json', (string) json_encode($withoutApps));
        $made = $public;
        $made['drift']['made_up'] = 1;
        $this->assertBreaksContract('report.schema.json', (string) json_encode($made));
    }

    public function test_the_json_owners_check_matches_the_contract(): void
    {
        $this->rollcall(['command' => 'scan', '--today' => '2026-11-07']);
        $json = $this->rollcall(['command' => 'owners', '--format' => 'json', '--today' => '2026-11-07']);

        $this->assertMatchesContract('owners-check.schema.json', $json);
        $this->assertSame([], json_decode($json, true)['unowned'], 'the fixture owners file lists every scanned app');
    }

    public function test_every_other_schema_has_teeth_too(): void
    {
        $this->rollcall(['command' => 'scan', '--today' => '2026-11-07']);

        // A snapshot from a schema version nobody wrote, or with a field the contract never promised.
        $snapshot = json_decode((string) file_get_contents($this->store . '/2026-11-07.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertBreaksContract('snapshot.schema.json', (string) json_encode([...$snapshot, 'schema_version' => 2]));
        $this->assertBreaksContract('snapshot.schema.json', (string) json_encode([...$snapshot, 'owner' => 'someone']));

        // An image list entry without its image, and a Citadel upgrade with a date that is not a date.
        $this->assertBreaksContract('images-export.schema.json', (string) json_encode(['apps' => [['project' => 'demo/ledger']]]));
        $this->assertBreaksContract('citadel-export.schema.json', (string) json_encode([
            'applications' => [['repository' => 'demo/ledger', 'base_image' => 'citadel/php-apache:8.4r5']],
            'base_image_upgrades' => [['old_image' => 'citadel/php-apache:8.1r2', 'due_date' => 'soon', 'completed' => false]],
        ]));

        // An owner with no contact, or a project that is not a group/project path.
        $this->assertBreaksContract('owners.schema.json', (string) json_encode(['owners' => [['projects' => ['demo/ledger']]]]));
        $this->assertBreaksContract('owners.schema.json', (string) json_encode(['owners' => [['contact' => 'team@example.test', 'projects' => ['ledger']]]]));
        $this->assertBreaksContract('owners.schema.json', (string) json_encode(['owners' => [['contact' => 'team@example.test', 'confirmed' => 'soon', 'projects' => ['demo/ledger']]]]));

        // A digest that forgot the apps nobody answers for.
        $digest = json_decode($this->rollcall(['command' => 'digest', '--format' => 'json', '--today' => '2026-11-07']), true, 64, JSON_THROW_ON_ERROR);
        unset($digest['no_owner_on_file']);
        $this->assertBreaksContract('digest.schema.json', (string) json_encode($digest));

        // An owners check that grew a ranking is not a register any more.
        $check = json_decode($this->rollcall(['command' => 'owners', '--format' => 'json', '--today' => '2026-11-07']), true, 64, JSON_THROW_ON_ERROR);
        $this->assertBreaksContract('owners-check.schema.json', (string) json_encode([...$check, 'rank' => 1]));
        // And an unconfirmed owner needs a real date or null, never a guess in words.
        $this->assertBreaksContract('owners-check.schema.json', (string) json_encode([...$check, 'unconfirmed' => [['app' => 'demo/relic', 'last_confirmed' => 'last year']]]));
    }

    public function test_every_schema_is_held_by_real_output_and_has_teeth(): void
    {
        // Roll call for the contract itself: a schema nobody validates against is a promise nobody checks.
        $suite = (string) file_get_contents(__FILE__);
        foreach (glob(self::CONTRACT . '/*.schema.json') ?: [] as $file) {
            $name = basename($file);
            $this->assertStringContainsString("assertMatchesContract('" . $name . "'", $suite, $name . ' is not validated against any real output here');
            $this->assertStringContainsString("assertBreaksContract('" . $name . "'", $suite, $name . ' has no case it must refuse here');
        }
    }

    public function test_the_contract_and_the_code_agree_on_drift_kinds_and_the_snapshot_version(): void
    {
        $report = json_decode((string) file_get_contents(self::CONTRACT . '/report.schema.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(ReportBuilder::DRIFT_KINDS, $report['$defs']['driftKind']['enum']);
        $this->assertSame(ReportBuilder::DRIFT_KINDS, $report['properties']['drift']['required']);

        $snapshot = json_decode((string) file_get_contents(self::CONTRACT . '/snapshot.schema.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(Snapshot::SCHEMA_VERSION, $snapshot['properties']['schema_version']['const']);
    }
}
