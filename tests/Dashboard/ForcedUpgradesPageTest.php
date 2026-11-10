<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Dashboard;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * Citadel's forced image upgrades on the dashboard: three panels for everyone
 * (pending, due today or earlier, the next date ahead), and each app's date in
 * the internal table, in its badge once the report's day has reached it. The
 * Vue twin prints the same markup; tools/vue-dashboard/parity.mjs holds it to
 * this page in CI. The bell tolls on a date, not on a feeling.
 */
final class ForcedUpgradesPageTest extends TestCase
{
    private const DUE_BADGE = '<span class="wsu-badge wsu-badge--danger">Due today or earlier</span>';

    public function test_the_citadel_fleet_shows_its_counts_and_each_apps_date(): void
    {
        $builder = new ReportBuilder(Policy::fromFile(Fleet::FIXTURES . '/policy.json'));
        $snapshot = Fleet::citadelSnapshot('2026-11-07');
        $today = new DateTimeImmutable('2026-11-07');

        $public = (new DashboardPage())->render($builder->build($snapshot, [$snapshot], $today), false);
        $this->assertSame(['Apps with an upgrade pending' => '2', 'Due today or earlier' => '1', 'Next date ahead' => '2026-12-15'], $this->panels($public));
        $this->assertStringContainsString('<span class="wsu-stat__label">' . self::DUE_BADGE . '</span>', $public);
        $this->assertStringNotContainsString('demo/', $public, 'the public page counts, it never names');
        $this->assertStringNotContainsString('Upgrade due', $public, 'the per-app column is internal only');

        $internal = (new DashboardPage())->render($builder->build($snapshot, [$snapshot], $today, true), true);
        $this->assertStringContainsString('<th scope="col">Image state</th><th scope="col">Upgrade due</th><th scope="col">PHP floor</th>', $internal);
        $this->assertStringContainsString('Each app&#039;s versions, Citadel upgrade date, PROCTOR result, MySQL 5.6 CI job and drift', $internal);
        // demo/broken's date passed on Nov 1; demo/atlas has Dec 15 ahead; demo/ledger has none.
        $this->assertSame('<span class="wsu-badge wsu-badge--danger">2026-11-01, date has come</span>', $this->dueCell($internal, 'demo/broken'));
        $this->assertSame('2026-12-15', $this->dueCell($internal, 'demo/atlas'));
        $this->assertSame('none', $this->dueCell($internal, 'demo/ledger'));
    }

    public function test_a_date_on_the_report_day_has_come_and_the_day_after_has_not(): void
    {
        $report = $this->report(['pending' => 3, 'overdue' => 2, 'next_due' => '2026-11-11']);
        $report['apps'] = [
            ['id' => 'demo/today', 'upgrade_due' => '2026-11-10'],
            ['id' => 'demo/tomorrow', 'upgrade_due' => '2026-11-11'],
            ['id' => 'demo/last-week', 'upgrade_due' => '2026-11-03'],
            ['id' => 'demo/none', 'upgrade_due' => null],
            ['id' => 'demo/old-report'],
        ];

        $body = (new DashboardPage())->render($report, true);

        $this->assertSame('<span class="wsu-badge wsu-badge--danger">2026-11-10, date has come</span>', $this->dueCell($body, 'demo/today'));
        $this->assertSame('2026-11-11', $this->dueCell($body, 'demo/tomorrow'));
        $this->assertSame('<span class="wsu-badge wsu-badge--danger">2026-11-03, date has come</span>', $this->dueCell($body, 'demo/last-week'));
        $this->assertSame('none', $this->dueCell($body, 'demo/none'));
        $this->assertSame('none', $this->dueCell($body, 'demo/old-report'), 'a report without the field reads none');
    }

    public function test_nothing_due_wears_no_badge_and_no_date_ahead_reads_none(): void
    {
        $page = new DashboardPage();

        $quiet = $page->render($this->report(['pending' => 0, 'overdue' => 0, 'next_due' => null]), false);
        $this->assertSame(['Apps with an upgrade pending' => '0', 'Due today or earlier' => '0', 'Next date ahead' => 'none'], $this->panels($quiet));
        $this->assertStringNotContainsString('wsu-badge', $quiet);

        $allDue = $page->render($this->report(['pending' => 2, 'overdue' => 2, 'next_due' => null]), false);
        $this->assertSame(['Apps with an upgrade pending' => '2', 'Due today or earlier' => '2', 'Next date ahead' => 'none'], $this->panels($allDue));
        $this->assertSame(1, substr_count($allDue, 'class="wsu-badge'), 'only the due panel wears a badge');
    }

    public function test_a_report_from_before_the_counts_shows_blank_panels_not_zeros(): void
    {
        $report = $this->report([]);
        unset($report['forced_upgrades']);

        $body = (new DashboardPage())->render($report, false);

        $this->assertSame(['Apps with an upgrade pending' => '', 'Due today or earlier' => '', 'Next date ahead' => 'none'], $this->panels($body));
        $this->assertStringNotContainsString('wsu-badge', $body);
    }

    /**
     * The forced upgrades section's panels as label => value, the badge read as its words.
     *
     * @return array<string, string>
     */
    private function panels(string $body): array
    {
        $this->assertSame(1, preg_match('#<section aria-labelledby="h-forced">\s*<h2 id="h-forced">Forced image upgrades</h2>(.*?)</section>#s', $body, $section));
        preg_match_all('#<div class="wsu-stat"><span class="wsu-stat__label">((?:<span[^>]*>[^<]*</span>)|[^<]*)</span><span class="wsu-stat__value">([^<]*)</span></div>#', $section[1], $stats, PREG_SET_ORDER);

        $out = [];
        foreach ($stats as [, $label, $value]) {
            $out[strip_tags($label)] = $value;
        }

        return $out;
    }

    /** The "Upgrade due" cell of one app's row: the fifth cell after the app's name. */
    private function dueCell(string $body, string $id): string
    {
        $this->assertSame(1, preg_match('#<th scope="row">' . preg_quote($id, '#') . '</th>(?:<td>[^<]*</td>){4}<td>((?:<span[^>]*>[^<]*</span>)|[^<]*)</td>#', $body, $cell), 'no row for ' . $id);

        return $cell[1];
    }

    /**
     * A report with only what the page needs, on Nov 10, 2026.
     *
     * @param  array<string, mixed>  $forced
     * @return array<string, mixed>
     */
    private function report(array $forced): array
    {
        return [
            'scan_date' => '2026-11-10',
            'as_of' => '2026-11-10',
            'totals' => ['apps' => 0],
            'progress' => ['since' => null, 'moved' => 0, 'upgraded_to_supported' => 0],
            'forced_upgrades' => $forced,
        ];
    }
}
