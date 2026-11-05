<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Parse\ComposerReader;

final class AuthPackagesTest extends TestCase
{
    /** @param list<string> $packages */
    private function lockWith(array $packages): string
    {
        $rows = [];
        foreach ($packages as $package) {
            $rows[] = ['name' => $package, 'version' => '1.2.3'];
        }

        return (string) json_encode(['packages' => $rows]);
    }

    public function test_a_bare_entry_matches_the_package_under_any_vendor(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{"require": {"php": "^8.3"}}', $this->lockWith(['acme/wayne-cas']));

        $this->assertSame('acme/wayne-cas', $facts->authPackage);
        $this->assertSame('1.2.3', $facts->authVersion);
    }

    public function test_the_bare_simplecas_entry_matches_any_vendor_too(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{"require": {"php": "^8.3"}}', $this->lockWith(['acme/simplecas']));

        $this->assertSame('acme/simplecas', $facts->authPackage);
        $this->assertSame('1.2.3', $facts->authVersion);
    }

    public function test_an_exact_entry_does_not_match_another_vendor(): void
    {
        $facts = (new ComposerReader(['acme/wayne-cas']))->read(
            'x/y',
            '{"require": {"php": "^8.3"}}',
            $this->lockWith(['other/wayne-cas']),
        );

        $this->assertNull($facts->authPackage);
    }

    public function test_a_bare_override_entry_works_when_passed_to_the_constructor(): void
    {
        $facts = (new ComposerReader(['their-auth']))->read(
            'x/y',
            '{"require": {"php": "^8.3"}}',
            $this->lockWith(['acme/their-auth']),
        );

        $this->assertSame('acme/their-auth', $facts->authPackage);
    }

    public function test_a_bare_entry_does_not_match_a_lookalike_name(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{"require": {"php": "^8.3"}}', $this->lockWith(['acme/cas-lookalike-tools']));

        $this->assertNull($facts->authPackage);
    }

    public function test_the_exact_entry_wins_over_the_bare_one_in_list_order(): void
    {
        $facts = (new ComposerReader())->read(
            'x/y',
            '{"require": {"php": "^8.3"}}',
            $this->lockWith(['acme/wayne-cas', 'waynestate/wayne-cas']),
        );

        $this->assertSame('waynestate/wayne-cas', $facts->authPackage);
    }

    public function test_a_composer_json_only_app_matches_the_bare_package_in_require(): void
    {
        $facts = (new ComposerReader())->read(
            'x/y',
            '{"require": {"php": "^8.3", "acme/wayne-cas": "^1.0"}}',
            null,
        );

        $this->assertSame('acme/wayne-cas', $facts->authPackage);
        $this->assertContains('composer.lock not found', $facts->notes);
    }
}
