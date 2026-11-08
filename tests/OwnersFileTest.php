<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Owner\Owners;

/**
 * Rollcall's own owners file (owners.json, the 1.0 decision of 2026-11-08): it loads, every
 * project the scheduled scan reads has an owner on file, and it names roles,
 * not people, because the repo is public. Nobody sits in the back row
 * unclaimed.
 */
final class OwnersFileTest extends TestCase
{
    private const FILE = __DIR__ . '/../owners.json';

    /** @return list<string> the projects the rollcall-scan job names by default */
    private function scannedProjects(): array
    {
        $yaml = (string) file_get_contents(__DIR__ . '/../.gitlab-ci.yml');
        $this->assertSame(1, preg_match('/ROLLCALL_GITLAB_PROJECTS:-([^}"]+)\}/', $yaml, $m), 'the scan job names its projects');

        return array_map('trim', explode(',', $m[1]));
    }

    public function test_the_file_loads_like_any_owners_file(): void
    {
        $owners = Owners::fromFile(self::FILE);

        $this->assertNotEmpty($owners->owners);
        $this->assertNotEmpty($owners->optedIn(), 'the stack maintainer asked for the weekly digest');
    }

    public function test_every_project_the_scan_reads_has_an_owner_on_file(): void
    {
        $projects = $this->scannedProjects();
        $this->assertCount(13, $projects);

        $this->assertSame([], Owners::fromFile(self::FILE)->unowned($projects), 'add the project to owners.json');
    }

    public function test_contacts_are_roles_or_teams_not_people(): void
    {
        foreach (Owners::fromFile(self::FILE)->owners as $owner) {
            // A mail address in a public file is a person's inbox or a list somebody must choose on purpose.
            $this->assertStringNotContainsString('@', $owner->contact, 'owners.json is public: name a role or a team');
        }
    }
}
