<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Console\Application;
use Waynestate\Rollcall\Lifecycle\PackageAdvisories;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * The advisories check: a saved Packagist response, read like the drift feed.
 * A package with no advisory data is "no data", never "safe".
 */
final class AdvisoriesTest extends TestCase
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

    private function lookup(): PackageAdvisories
    {
        return new PackageAdvisories((string) file_get_contents(Fleet::FIXTURES . '/advisories.json'));
    }

    /** @param  array<string, string>  $extra */
    private function tester(array $extra = []): ApplicationTester
    {
        $app = new Application([
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $this->store,
            'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
            'ROLLCALL_IMAGES_FILE' => Fleet::FIXTURES . '/images.json',
            'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/proctor',
            ...$extra,
        ]);
        $app->setAutoExit(false);

        return new ApplicationTester($app);
    }

    public function test_a_version_inside_the_saved_range_is_affected(): void
    {
        $this->assertSame(['status' => 'affected', 'count' => 1, 'ids' => ['PKSA-2026-0001']], $this->lookup()->check('laravel/framework', '10.48.2'));
    }

    public function test_a_version_outside_every_saved_range_is_clear(): void
    {
        $this->assertSame(['status' => 'clear', 'count' => 0, 'ids' => []], $this->lookup()->check('laravel/framework', '12.3.1'));
    }

    public function test_a_package_with_an_empty_advisory_list_is_clear(): void
    {
        $this->assertSame(['status' => 'clear', 'count' => 0, 'ids' => []], $this->lookup()->check('apereo/phpcas', '1.6.1'));
    }

    public function test_a_package_absent_from_the_feed_is_no_data(): void
    {
        $this->assertSame(['status' => 'no data', 'count' => 0, 'ids' => []], $this->lookup()->check('waynestate/sesame', '0.1.3'));
    }

    public function test_a_null_version_is_no_data(): void
    {
        $this->assertSame(['status' => 'no data', 'count' => 0, 'ids' => []], $this->lookup()->check('laravel/framework', null));
    }

    public function test_a_v_prefixed_version_is_normalised_before_the_match(): void
    {
        $this->assertSame('affected', $this->lookup()->check('laravel/framework', 'v10.48.2')['status']);
    }

    public function test_an_unparseable_version_is_no_data_not_a_crash(): void
    {
        $this->assertSame('no data', $this->lookup()->check('laravel/framework', 'not-a-version')['status']);
    }

    public function test_an_unparseable_range_is_no_data_not_a_crash(): void
    {
        $lookup = new PackageAdvisories('{"advisories":{"acme/widget":[{"advisoryId":"PKSA-2026-9999","affectedVersions":"not a range"}]}}');
        $this->assertSame(['status' => 'no data', 'count' => 0, 'ids' => []], $lookup->check('acme/widget', '1.0.0'));
    }

    public function test_invalid_feed_json_throws(): void
    {
        $this->expectException(RollcallException::class);
        new PackageAdvisories('{ this is not json');
    }

    public function test_a_feed_without_the_advisories_object_throws(): void
    {
        $this->expectException(RollcallException::class);
        new PackageAdvisories('{"advisory": []}');
    }

    public function test_the_command_checks_the_latest_snapshot_against_the_saved_feed(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->run(['command' => 'scan', '--today' => '2026-10-28']));
        $this->assertSame(0, $tester->run(['command' => 'advisories', '--feed' => Fleet::FIXTURES . '/advisories.json']));
        $display = $tester->getDisplay();

        $this->assertStringContainsString('apereo/phpcas 1.6.1: clear (0 advisories, none) in 1 apps', $display);
        $this->assertStringContainsString('laravel/framework 10.48.2: affected (1 advisories, PKSA-2026-0001) in 1 apps', $display);
        $this->assertStringContainsString('laravel/framework 12.3.1: clear (0 advisories, none) in 1 apps', $display);
        $this->assertStringContainsString('waynestate/sesame 0.1.3: no data (0 advisories, none) in 1 apps', $display);
        foreach (['demo/atlas', 'demo/broken', 'demo/ledger', 'demo/relic'] as $id) {
            $this->assertStringNotContainsString($id, $display, 'app ids stay internal');
        }
    }

    public function test_the_command_needs_a_scan_first_and_a_readable_feed(): void
    {
        $this->assertSame(2, $this->tester()->run(['command' => 'advisories', '--feed' => Fleet::FIXTURES . '/advisories.json']));

        $tester = $this->tester();
        $tester->run(['command' => 'scan', '--today' => '2026-10-28']);
        $this->assertSame(2, $tester->run(['command' => 'advisories', '--feed' => Fleet::FIXTURES . '/no-such-file.json']));
    }
}
