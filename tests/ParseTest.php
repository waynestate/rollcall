<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Parse\ImageTag;
use Waynestate\Rollcall\Parse\ProctorReportReader;
use Waynestate\Rollcall\RollcallException;

final class ParseTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function test_composer_reader_takes_the_floor_laravel_and_auth_from_the_files(): void
    {
        $facts = (new ComposerReader())->read(
            'demo/ledger',
            (string) file_get_contents(self::FIXTURES . '/repos/demo/ledger/composer.json'),
            (string) file_get_contents(self::FIXTURES . '/repos/demo/ledger/composer.lock'),
        );

        $this->assertSame('^8.4', $facts->phpConstraint);
        $this->assertSame('8.4', $facts->phpFloor);
        $this->assertSame('12.3.1', $facts->laravel);
        $this->assertSame('12', $facts->laravelMajor());
        $this->assertSame('waynestate/sesame', $facts->authPackage);
        $this->assertSame('0.1.3', $facts->authVersion, 'the require-dev package must not count');
        $this->assertSame([], $facts->notes);
    }

    public function test_an_open_ended_php_constraint_is_a_floor_of_its_lower_bound(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{"require": {"php": ">=8.1"}}', null);
        $this->assertSame('8.1', $facts->phpFloor);
        $this->assertContains('composer.lock not found', $facts->notes);
    }

    public function test_a_star_constraint_has_no_floor(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{"require": {"php": "*"}}', '{"packages": []}');
        $this->assertNull($facts->phpFloor);
        $this->assertContains('PHP constraint has no lower bound', $facts->notes);
    }

    public function test_broken_json_becomes_a_note_not_an_exception(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{ nope', '{ nope');
        $this->assertContains('composer.json is not valid JSON', $facts->notes);
        $this->assertNull($facts->laravel);
    }

    public function test_an_unreadable_php_constraint_becomes_a_note(): void
    {
        $facts = (new ComposerReader())->read('x/y', '{"require": {"php": "banana"}}', null);
        $this->assertContains('PHP constraint could not be read', $facts->notes);
    }

    public function test_the_auth_package_list_is_configurable(): void
    {
        $lock = '{"packages": [{"name": "acme/login", "version": "2.0.0"}, {"name": "waynestate/sesame", "version": "0.1.0"}]}';
        $facts = (new ComposerReader(['acme/login']))->read('x/y', '{"require": {"php": "^8.3"}}', $lock);
        $this->assertSame('acme/login', $facts->authPackage);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function tags(): iterable
    {
        yield ['php:8.4-apache', '8.4'];
        yield ['php-8.3', '8.3'];
        yield ['registry.example/php/8.5-cli:latest', '8.5'];
        yield ['php84', '8.4'];
        yield ['php:7.4-fpm', '7.4'];
        yield ['legacy-base', null];
        yield ['node:22', null];
        yield [str_repeat('a', 300) . '8.4', null];
    }

    #[DataProvider('tags')]
    public function test_image_tag_php_version(string $tag, ?string $expected): void
    {
        $this->assertSame($expected, ImageTag::phpVersion($tag));
    }

    public function test_proctor_report_is_read_in_the_shape_proctor_writes(): void
    {
        $report = (new ProctorReportReader())->read((string) file_get_contents(self::FIXTURES . '/proctor/demo__atlas.json'));

        $this->assertSame('fail', $report['result']);
        $this->assertSame('0.1.3', $report['version']);
        $this->assertCount(1, $report['overrides']);
        $this->assertTrue($report['overrides'][0]->expired);
        $this->assertSame('Upgrade scheduled', $report['overrides'][0]->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function badReports(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'another tool' => ['{"tool": "other", "result": "pass"}'];
        yield 'bad result' => ['{"tool": "proctor", "result": "maybe"}'];
        yield 'a list' => ['[1,2]'];
    }

    #[DataProvider('badReports')]
    public function test_a_report_in_the_wrong_shape_is_refused(string $json): void
    {
        $this->expectException(RollcallException::class);
        (new ProctorReportReader())->read($json);
    }

    public function test_override_text_is_bounded(): void
    {
        $json = (string) json_encode(['tool' => 'proctor', 'result' => 'pass', 'overrides' => [['check' => 'a', 'reason' => str_repeat('x', 5000), 'expired' => false]]]);
        $report = (new ProctorReportReader())->read($json);
        $this->assertSame(500, strlen($report['overrides'][0]->reason));
    }
}
