<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Dashboard\RawDashboard;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * The MySQL 5.6 CI job panels (D-079): four counts in words, and "missing" in
 * its badge only while some app is missing the job. The Vue twin prints the
 * same markup; tools/vue-dashboard/parity.mjs holds it to this page in CI.
 * Roll the bones, but run the migrations on 5.6 first.
 */
final class DashboardPageTest extends TestCase
{
    private const BADGE = '<span class="wsu-badge wsu-badge--danger">missing</span>';

    private string $store = '';

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2026-10-28'));
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
    }

    public function test_the_public_page_counts_the_fleet_by_mysql56_job_state(): void
    {
        $body = $this->page(false);

        $this->assertStringContainsString('<section aria-labelledby="h-mysql56">', $body);
        $this->assertStringContainsString('<h2 id="h-mysql56">MySQL 5.6 CI job</h2>', $body);

        $counts = $this->panels($body);
        $this->assertSame(['held', 'missing', 'not applicable', 'unknown'], array_keys($counts), 'four panels, in page order, in plain words');
        // demo/atlas holds the job, demo/ledger has migrations and no job; the other two have no readable report.
        $this->assertSame(['held' => 1, 'missing' => 1, 'not applicable' => 0, 'unknown' => 2], $counts);
        $this->assertStringContainsString('<span class="wsu-stat__label">' . self::BADGE . '</span>', $body, 'missing wears its badge while an app is missing the job');
        $this->assertStringNotContainsString('demo/', $body, 'the public page never names an app');
    }

    public function test_missing_wears_no_badge_when_no_app_is_missing_the_job(): void
    {
        $page = new DashboardPage();
        $quiet = $page->render($this->report(['held' => 3, 'missing' => 0, 'not_applicable' => 2, 'unknown' => 1]), false);
        $loud = $page->render($this->report(['held' => 3, 'missing' => 2, 'not_applicable' => 2, 'unknown' => 1]), false);

        $this->assertStringNotContainsString('wsu-badge', $quiet);
        $this->assertStringContainsString('<span class="wsu-stat__label">missing</span><span class="wsu-stat__value">0</span>', $quiet);
        $this->assertStringContainsString('<span class="wsu-stat__label">' . self::BADGE . '</span><span class="wsu-stat__value">2</span>', $loud);
        $this->assertSame(1, substr_count($loud, 'class="wsu-badge'), 'only missing wears a badge');
    }

    public function test_the_internal_table_has_each_apps_mysql56_state_beside_proctor(): void
    {
        $body = $this->page(true);

        $this->assertStringContainsString('Each app&#039;s versions, PROCTOR result, MySQL 5.6 CI job and drift', $body);
        $this->assertStringContainsString('<th scope="col">PROCTOR</th><th scope="col">MySQL 5.6 CI</th><th scope="col">Drift</th>', $body);
        $this->assertMatchesRegularExpression('#<th scope="row">demo/atlas</th>(?:<td>[^<]*</td>){8}<td>held</td>#', $body);
        $this->assertMatchesRegularExpression('#<th scope="row">demo/ledger</th>(?:<td>[^<]*</td>){8}<td>' . preg_quote(self::BADGE, '#') . '</td>#', $body);
        $this->assertSame(2, substr_count($body, self::BADGE), 'the panel and the ledger row, nothing else');
    }

    public function test_an_unknown_or_absent_state_reads_unknown_in_the_table(): void
    {
        $report = $this->report(['held' => 0, 'missing' => 0, 'not_applicable' => 1, 'unknown' => 1]);
        $report['apps'] = [
            ['id' => 'demo/a', 'mysql56_ci' => 'not_applicable'],
            ['id' => 'demo/b'],
        ];

        $body = (new DashboardPage())->render($report, true);

        $this->assertMatchesRegularExpression('#<th scope="row">demo/a</th>(?:<td>[^<]*</td>){8}<td>not applicable</td>#', $body);
        $this->assertMatchesRegularExpression('#<th scope="row">demo/b</th>(?:<td>[^<]*</td>){8}<td>unknown</td>#', $body);
    }

    /** The raw-PHP page for the fixture fleet. */
    private function page(bool $internal): string
    {
        $response = RawDashboard::response([
            'env' => [
                'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
                'ROLLCALL_STORE_DIR' => $this->store,
                'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
                'ROLLCALL_IMAGES_FILE' => Fleet::FIXTURES . '/images.json',
                'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/proctor',
                'ROLLCALL_DASHBOARD_ENABLED' => 'true',
                'ROLLCALL_INTERNAL_ENABLED' => 'true',
            ],
            'internal' => $internal,
        ]);
        $this->assertSame(200, $response['status']);

        return $response['body'];
    }

    /**
     * The MySQL 5.6 section's panels as label => count, the badge read as its word.
     *
     * @return array<string, int>
     */
    private function panels(string $body): array
    {
        $this->assertSame(1, preg_match('#<section aria-labelledby="h-mysql56">(.*?)</section>#s', $body, $section));
        preg_match_all('#<div class="wsu-stat"><span class="wsu-stat__label">((?:<span[^>]*>[^<]*</span>)|[^<]*)</span><span class="wsu-stat__value">(\d+)</span></div>#', $section[1], $stats, PREG_SET_ORDER);

        $out = [];
        foreach ($stats as [, $label, $count]) {
            $out[strip_tags($label)] = (int) $count;
        }

        return $out;
    }

    /**
     * A report with only what the page needs.
     *
     * @param  array<string, int>  $mysql56
     * @return array<string, mixed>
     */
    private function report(array $mysql56): array
    {
        return [
            'scan_date' => '2026-11-10',
            'as_of' => '2026-11-10',
            'totals' => ['apps' => array_sum($mysql56)],
            'progress' => ['since' => null, 'moved' => 0, 'upgraded_to_supported' => 0],
            'mysql56_ci' => $mysql56,
        ];
    }
}
