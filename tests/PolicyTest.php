<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Policy\Policy;
use Waynestate\Rollcall\Policy\State;
use Waynestate\Rollcall\RollcallException;

final class PolicyTest extends TestCase
{
    private function policy(): Policy
    {
        return Policy::fromFile(__DIR__ . '/fixtures/policy.json');
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    /** @return iterable<string, array{string, string, string, State}> */
    public static function states(): iterable
    {
        yield 'php 8.4 is active on its last active day' => ['php', '8.4', '2026-12-31', State::Active];
        yield 'php 8.4 turns security-only the day after' => ['php', '8.4', '2027-01-01', State::SecurityOnly];
        yield 'php 8.2 is security-only now' => ['php', '8.2', '2026-10-28', State::SecurityOnly];
        yield 'php 8.2 is still supported on its last day' => ['php', '8.2', '2026-12-31', State::SecurityOnly];
        yield 'php 8.2 is EOL the day after' => ['php', '8.2', '2027-01-01', State::Eol];
        yield 'php 8.1 has only a security date and is EOL after it' => ['php', '8.1', '2026-10-28', State::Eol];
        yield 'php 7.4 is older than anything listed, so EOL' => ['php', '7.4', '2026-10-28', State::Eol];
        yield 'php 9.0 is newer than the policy, so unknown' => ['php', '9.0', '2026-10-28', State::Unknown];
        yield 'laravel 10 is EOL' => ['laravel', '10', '2026-10-28', State::Eol];
        yield 'laravel 9 is older than listed, so EOL' => ['laravel', '9', '2026-10-28', State::Eol];
        yield 'laravel 12 is security-only' => ['laravel', '12', '2026-10-28', State::SecurityOnly];
        yield 'an unlisted component is unknown' => ['cobol', '1', '2026-10-28', State::Unknown];
    }

    #[DataProvider('states')]
    public function test_state_is_derived_from_the_dates(string $component, string $version, string $today, State $expected): void
    {
        $this->assertSame($expected, $this->policy()->stateOf($component, $version, self::day($today)));
    }

    public function test_a_missing_version_is_unknown(): void
    {
        $this->assertSame(State::Unknown, $this->policy()->stateOf('php', null, self::day('2026-10-28')));
    }

    public function test_days_to_eol_counts_to_the_security_date_and_goes_negative(): void
    {
        $policy = $this->policy();
        $this->assertSame(64, $policy->daysToEol('php', '8.2', self::day('2026-10-28')));
        $this->assertSame(-301, $policy->daysToEol('php', '8.1', self::day('2026-10-28')));
        $this->assertNull($policy->daysToEol('php', '7.4', self::day('2026-10-28')));
    }

    public function test_grace_comes_from_the_policy_rules(): void
    {
        $policy = $this->policy();
        $this->assertSame(90, $policy->eolGraceDays);
        $this->assertTrue($policy->isPastGrace('php', '8.1', self::day('2026-10-28')));
        $this->assertFalse($policy->isPastGrace('php', '8.2', self::day('2027-02-01')));
        $this->assertTrue($policy->isPastGrace('php', '8.2', self::day('2027-04-02')));
    }

    /** @return iterable<string, array{string}> */
    public static function badPolicies(): iterable
    {
        yield 'not json' => ['{ nope'];
        yield 'wrong schema version' => ['{"schema_version": 2, "components": {"php": {"versions": {}}}}'];
        yield 'no components' => ['{"schema_version": 1, "components": {}}'];
        yield 'component without versions' => ['{"schema_version": 1, "components": {"php": {}}}'];
        yield 'bad date' => ['{"schema_version": 1, "components": {"php": {"versions": {"8.4": {"security_until": "soon"}}}}}'];
        yield 'bad grace' => ['{"schema_version": 1, "components": {"php": {"versions": {}}}, "rules": {"eol_grace_days": -1}}'];
    }

    #[DataProvider('badPolicies')]
    public function test_a_bad_policy_is_refused(string $json): void
    {
        $this->expectException(RollcallException::class);
        Policy::fromJson($json);
    }

    public function test_a_missing_file_names_the_setting(): void
    {
        $this->expectExceptionMessage('ROLLCALL_POLICY_FILE');
        Policy::fromFile(__DIR__ . '/fixtures/nope.json');
    }
}
