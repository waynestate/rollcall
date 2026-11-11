<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Dashboard\RawDashboard;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * The Booster hop panels: three counts in words, and "fail" in its badge only
 * while some app's last hop failed. The internal table gives each app its
 * result in a Booster column beside the MySQL 5.6 one. The Vue twin prints the
 * same markup; tools/vue-dashboard/parity.mjs holds it to this page in CI.
 * A booster shot is only on the chart once somebody writes it down.
 */
final class BoosterPanelsTest extends TestCase
{
    private const BADGE = '<span class="wsu-badge wsu-badge--danger">fail</span>';

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

    public function test_the_public_page_counts_the_fleet_by_booster_hop_result(): void
    {
        $body = $this->page(false);

        $this->assertStringContainsString('<section aria-labelledby="h-booster">', $body);
        $this->assertStringContainsString('<h2 id="h-booster">Booster hop record</h2>', $body);
        // demo/ledger's hop passed, demo/atlas's failed its gate; demo/relic has no record and demo/broken's is unreadable.
        $this->assertSame(['pass' => 1, 'fail' => 1, 'no record' => 2], $this->panels($body), 'three panels, in page order, in plain words');
        $this->assertStringContainsString('<span class="wsu-stat__label">' . self::BADGE . '</span>', $body, 'fail wears its badge while an app failed');
        $this->assertStringNotContainsString('demo/', $body, 'the public page never names an app');
        $this->assertStringNotContainsString('<th scope="col">Booster</th>', $body, 'the per-app column is internal only');
    }

    public function test_fail_wears_no_badge_when_no_hop_failed(): void
    {
        $page = new DashboardPage();
        $quiet = $page->render($this->report(['pass' => 4, 'fail' => 0, 'none' => 2]), false);
        $loud = $page->render($this->report(['pass' => 4, 'fail' => 3, 'none' => 2]), false);

        $this->assertStringNotContainsString('wsu-badge', $quiet);
        $this->assertStringContainsString('<span class="wsu-stat__label">fail</span><span class="wsu-stat__value">0</span>', $quiet);
        $this->assertStringContainsString('<span class="wsu-stat__label">' . self::BADGE . '</span><span class="wsu-stat__value">3</span>', $loud);
        $this->assertSame(1, substr_count($loud, 'class="wsu-badge'), 'only fail wears a badge');
    }

    public function test_the_internal_table_has_each_apps_booster_result(): void
    {
        $body = $this->page(true);

        $this->assertStringContainsString('<th scope="col">MySQL 5.6 CI</th><th scope="col">Booster</th><th scope="col">Drift</th>', $body);
        $this->assertSame('pass', $this->boosterCell($body, 'demo/ledger'));
        $this->assertSame(self::BADGE, $this->boosterCell($body, 'demo/atlas'));
        $this->assertSame('no record', $this->boosterCell($body, 'demo/relic'));
        $this->assertSame('no record', $this->boosterCell($body, 'demo/broken'), 'an unreadable record is no record');
        $this->assertSame(2, substr_count($body, self::BADGE), 'the panel and the atlas row, nothing else');
    }

    public function test_an_absent_result_reads_no_record_and_an_old_report_shows_blank_panels(): void
    {
        $report = $this->report(null);
        $report['apps'] = [['id' => 'demo/old']];

        $body = (new DashboardPage())->render($report, true);

        $this->assertSame('no record', $this->boosterCell($body, 'demo/old'));
        // A report from before the counts existed has no numbers to give: the panels stay, empty, never a made-up zero.
        $this->assertStringContainsString('<span class="wsu-stat__label">fail</span><span class="wsu-stat__value"></span>', $body);
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
     * The Booster section's panels as label => count, the badge read as its word.
     *
     * @return array<string, int>
     */
    private function panels(string $body): array
    {
        $this->assertSame(1, preg_match('#<section aria-labelledby="h-booster">(.*?)</section>#s', $body, $section));
        preg_match_all('#<div class="wsu-stat"><span class="wsu-stat__label">((?:<span[^>]*>[^<]*</span>)|[^<]*)</span><span class="wsu-stat__value">(\d+)</span></div>#', $section[1], $stats, PREG_SET_ORDER);

        $out = [];
        foreach ($stats as [, $label, $count]) {
            $out[strip_tags($label)] = (int) $count;
        }

        return $out;
    }

    /** One app's Booster cell: the eleventh cell after its name, the column before Drift. */
    private function boosterCell(string $body, string $app): string
    {
        $this->assertSame(1, preg_match('#<th scope="row">' . preg_quote($app, '#') . '</th>((?:<td>(?:<span[^>]*>[^<]*</span>|[^<]*)</td>){12})</tr>#', $body, $row), $app . ' has a row of 12 cells');
        preg_match_all('#<td>((?:<span[^>]*>[^<]*</span>)|[^<]*)</td>#', $row[1], $cells);

        return $cells[1][10];
    }

    /**
     * A report with only what the page needs.
     *
     * @param  array<string, int>|null  $booster  null: a report from before the counts existed
     * @return array<string, mixed>
     */
    private function report(?array $booster): array
    {
        $report = [
            'scan_date' => '2026-11-10',
            'as_of' => '2026-11-10',
            'totals' => ['apps' => $booster === null ? 1 : array_sum($booster)],
            'progress' => ['since' => null, 'moved' => 0, 'upgraded_to_supported' => 0],
        ];
        if ($booster !== null) {
            $report['booster'] = $booster;
        }

        return $report;
    }
}
