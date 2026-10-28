<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Lifecycle\Support;
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

    /** @return iterable<string, array{string, string, string, Support}> */
    public static function states(): iterable
    {
        yield 'php 8.4 is active on its last active day' => ['php', '8.4', '2026-12-31', Support::Active];
        yield 'php 8.4 turns security-only the day after' => ['php', '8.4', '2027-01-01', Support::SecurityOnly];
        yield 'php 8.2 is security-only now' => ['php', '8.2', '2026-10-28', Support::SecurityOnly];
        yield 'php 8.2 is still supported on its last day' => ['php', '8.2', '2026-12-31', Support::SecurityOnly];
        yield 'php 8.2 is EOL the day after' => ['php', '8.2', '2027-01-01', Support::Eol];
        yield 'php 8.1 has only a security date and is EOL after it' => ['php', '8.1', '2026-10-28', Support::Eol];
        yield 'php 7.4 is older than anything listed, so unknown' => ['php', '7.4', '2026-10-28', Support::Unknown];
        yield 'php 9.0 is newer than the policy, so unknown' => ['php', '9.0', '2026-10-28', Support::Unknown];
        yield 'laravel 10 is EOL' => ['laravel', '10', '2026-10-28', Support::Eol];
        yield 'laravel 9 is older than listed, so unknown' => ['laravel', '9', '2026-10-28', Support::Unknown];
        yield 'laravel 12 is security-only' => ['laravel', '12', '2026-10-28', Support::SecurityOnly];
        yield 'an unlisted component is unknown' => ['cobol', '1', '2026-10-28', Support::Unknown];
    }

    #[DataProvider('states')]
    public function test_state_is_derived_from_the_dates(string $component, string $version, string $today, Support $expected): void
    {
        $this->assertSame($expected, Support::fromTryStateOf($this->policy()->tryStateOf($component, $version, self::day($today))));
    }

    public function test_a_missing_version_is_unknown(): void
    {
        $this->assertSame(Support::Unknown, Support::fromTryStateOf($this->policy()->tryStateOf('php', null, self::day('2026-10-28'))));
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
        $this->assertSame(90, $policy->rules()['eol_grace_days']);
        $this->assertTrue($policy->isPastGrace('php', '8.1', self::day('2026-10-28')));
        $this->assertFalse($policy->isPastGrace('php', '8.2', self::day('2027-02-01')));
        $this->assertTrue($policy->isPastGrace('php', '8.2', self::day('2027-04-02')));
    }

    public function test_a_policy_without_a_schema_version_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::fromArray(['components' => ['php' => ['versions' => []]]]);
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::fromFile(__DIR__ . '/fixtures/nope.json');
    }

    public function test_a_file_that_is_not_json_is_refused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rollcall-policy-');
        file_put_contents($file, '{ nope');
        try {
            $this->expectException(InvalidArgumentException::class);
            Policy::fromFile($file);
        } finally {
            unlink($file);
        }
    }

    public function test_the_factory_names_the_setting_when_the_file_is_missing(): void
    {
        $this->expectException(RollcallException::class);
        $this->expectExceptionMessage('ROLLCALL_POLICY_FILE');
        (new Factory(new Config(policyFile: __DIR__ . '/fixtures/nope.json')))->policy();
    }
}
