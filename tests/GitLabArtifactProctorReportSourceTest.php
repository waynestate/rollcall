<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\GitLab\GitLabArtifactProctorReportSource;
use Waynestate\Rollcall\Source\GitLab\GitLabRepositorySource;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * PROCTOR reports read from the proctor job's CI artifact. A MockHttpClient
 * plays GitLab back; no test reaches a real host, and the token is made up.
 *
 * The recorded fleet: demo/ledger (project 101, default branch main) and
 * demo/sub/atlas (102, develop). Pipelines and jobs are numbered so a reader
 * can tell at a glance which one a request came from.
 */
final class GitLabArtifactProctorReportSourceTest extends TestCase
{
    private const BASE = 'https://gitlab.example.test';

    private const TOKEN = 'glpat-not-a-real-token';

    /** @var list<array{method: string, url: string, token: ?string}> */
    private array $requests = [];

    private function fixture(string $name): string
    {
        return (string) file_get_contents(Fleet::FIXTURES . '/' . $name);
    }

    /** @param  callable(string, string): ?MockResponse  $respond  answers past the project listing; null is a 404 */
    private function gitlab(callable $respond): GitLabRepositorySource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($respond): MockResponse {
            $token = null;
            foreach ($options['headers'] as $header) {
                if (str_starts_with(strtolower($header), 'private-token:')) {
                    $token = trim(substr($header, 14));
                }
            }
            $this->requests[] = ['method' => $method, 'url' => $url, 'token' => $token];
            if (str_contains($url, '/groups/demo/projects')) {
                return new MockResponse($this->fixture('gitlab/projects-page1.json'));
            }

            return $respond($method, $url) ?? new MockResponse('{"message":"404 Not Found"}', ['http_code' => 404]);
        });
        $psr17 = new Psr17Factory();

        return new GitLabRepositorySource(new Psr18Client($http, $psr17, $psr17), $psr17, self::BASE, self::TOKEN, ['demo'], static function (int $seconds): void {});
    }

    /**
     * GitLab with one finished pipeline (900) on ledger's main whose proctor job (9001) kept $artifact.
     *
     * @param  ?MockResponse  $artifact  null: the file is not in the artifact (404)
     */
    private function oneReport(?MockResponse $artifact, string $jobStatus = 'failed'): GitLabRepositorySource
    {
        return $this->gitlab(static function (string $method, string $url) use ($artifact, $jobStatus): ?MockResponse {
            if (str_contains($url, '/projects/101/pipelines?')) {
                return new MockResponse('[{"id": 900, "status": "failed", "ref": "main"}]');
            }
            if (str_contains($url, '/projects/101/pipelines/900/jobs')) {
                return new MockResponse('[{"id": 9002, "name": "lint", "status": "success"}, {"id": 9001, "name": "proctor", "status": "' . $jobStatus . '"}]');
            }
            if (str_contains($url, '/projects/101/jobs/9001/artifacts/')) {
                return $artifact;
            }

            return null;
        });
    }

    /** @return list<string> the URLs requested after the project listing */
    private function calls(): array
    {
        return array_values(array_map(
            static fn(array $r): string => $r['url'],
            array_filter($this->requests, static fn(array $r): bool => ! str_contains($r['url'], '/groups/')),
        ));
    }

    public function test_a_report_is_read_from_the_newest_finished_pipelines_proctor_job(): void
    {
        $report = $this->fixture('proctor/demo__atlas.json');
        $source = new GitLabArtifactProctorReportSource($this->oneReport(new MockResponse($report)));

        $this->assertSame($report, $source->report('demo/ledger'));

        $this->assertSame([
            self::BASE . '/api/v4/projects/101/pipelines?ref=main&scope=finished&order_by=id&sort=desc&per_page=5',
            self::BASE . '/api/v4/projects/101/pipelines/900/jobs?per_page=100',
            self::BASE . '/api/v4/projects/101/jobs/9001/artifacts/proctor.json',
        ], $this->calls());
        foreach ($this->requests as $request) {
            $this->assertSame('GET', $request['method'], 'only reads');
            $this->assertSame(self::TOKEN, $request['token'], 'the scanner token, to the configured host only');
        }
    }

    public function test_a_failed_proctor_job_is_read_because_a_failing_report_is_the_point(): void
    {
        $source = new GitLabArtifactProctorReportSource($this->oneReport(new MockResponse('{"result": "fail"}'), 'failed'));

        $this->assertSame('{"result": "fail"}', $source->report('demo/ledger'));
    }

    public function test_the_job_name_and_artifact_path_are_configurable_and_the_path_is_encoded(): void
    {
        $gitlab = $this->gitlab(static function (string $method, string $url): ?MockResponse {
            if (str_contains($url, '/projects/102/pipelines?')) {
                return new MockResponse('[{"id": 700}]');
            }
            if (str_contains($url, '/projects/102/pipelines/700/jobs')) {
                return new MockResponse('[{"id": 7001, "name": "proctor-api", "status": "success"}]');
            }
            if (str_contains($url, '/projects/102/jobs/7001/artifacts/reports/proctor%20report.json')) {
                return new MockResponse('{}');
            }

            return null;
        });

        // A space is not allowed by the setting; the encoding is proven on the adapter itself.
        $this->assertSame('{}', $gitlab->jobArtifact('demo/sub/atlas', 'proctor-api', 'reports/proctor report.json', 1024));
        $this->assertStringContainsString('ref=develop', $this->calls()[0], 'the default branch, not main');
    }

    public function test_a_404_for_the_file_is_no_report_not_a_failed_scan(): void
    {
        $source = new GitLabArtifactProctorReportSource($this->oneReport(null));

        $this->assertNull($source->report('demo/ledger'));
    }

    public function test_no_pipeline_no_job_or_an_unknown_project_is_no_report(): void
    {
        $noPipelines = $this->gitlab(static fn(string $m, string $u): ?MockResponse => str_contains($u, '/pipelines?') ? new MockResponse('[]') : null);
        $this->assertNull((new GitLabArtifactProctorReportSource($noPipelines))->report('demo/ledger'));

        // Two finished pipelines, neither ran the proctor job (one was canceled): no file is asked for.
        $this->requests = [];
        $noJob = $this->gitlab(static function (string $method, string $url): ?MockResponse {
            if (str_contains($url, '/pipelines?')) {
                return new MockResponse('[{"id": 902}, {"id": 901}]');
            }
            if (str_contains($url, '/pipelines/902/jobs')) {
                return new MockResponse('[{"id": 9021, "name": "lint", "status": "success"}]');
            }
            if (str_contains($url, '/pipelines/901/jobs')) {
                return new MockResponse('[{"id": 9011, "name": "proctor", "status": "canceled"}]');
            }

            return new MockResponse('should not be read', ['http_code' => 500]);
        });
        $this->assertNull((new GitLabArtifactProctorReportSource($noJob))->report('demo/ledger'));
        $this->assertCount(3, $this->calls());

        $this->assertNull((new GitLabArtifactProctorReportSource($this->oneReport(new MockResponse('{}'))))->report('demo/not-in-gitlab'));
    }

    public function test_a_pipeline_without_the_job_falls_back_to_the_one_before_it(): void
    {
        $gitlab = $this->gitlab(static function (string $method, string $url): ?MockResponse {
            if (str_contains($url, '/pipelines?')) {
                return new MockResponse('[{"id": 902}, {"id": 901}]');
            }
            if (str_contains($url, '/pipelines/902/jobs')) {
                return new MockResponse('[]');
            }
            if (str_contains($url, '/pipelines/901/jobs')) {
                return new MockResponse('[{"id": 9011, "name": "proctor", "status": "success"}, {"id": 9015, "name": "proctor", "status": "failed"}]');
            }
            if (str_contains($url, '/jobs/9015/artifacts/proctor.json')) {
                return new MockResponse('{"retried": true}');
            }

            return null;
        });

        $this->assertSame('{"retried": true}', (new GitLabArtifactProctorReportSource($gitlab))->report('demo/ledger'), 'the newest run of the job wins');
    }

    public function test_ci_turned_off_for_a_project_is_no_report(): void
    {
        $gitlab = $this->gitlab(static fn(string $m, string $u): ?MockResponse => str_contains($u, '/pipelines?') ? new MockResponse('{"message":"403 Forbidden"}', ['http_code' => 403]) : null);

        $this->assertNull((new GitLabArtifactProctorReportSource($gitlab))->report('demo/ledger'));
    }

    public function test_other_errors_are_handled_like_every_other_gitlab_call(): void
    {
        $refused = $this->oneReport(new MockResponse('', ['http_code' => 401]));
        try {
            (new GitLabArtifactProctorReportSource($refused))->report('demo/ledger');
            $this->fail('a refused token stops the scan');
        } catch (RollcallException $e) {
            $this->assertStringContainsString('refused the token (HTTP 401)', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        $broken = $this->oneReport(new MockResponse('', ['http_code' => 500]));
        $this->expectException(RollcallException::class);
        $this->expectExceptionMessage('GitLab answered HTTP 500.');
        (new GitLabArtifactProctorReportSource($broken))->report('demo/ledger');
    }

    public function test_a_report_over_the_size_ceiling_is_no_report(): void
    {
        $source = new GitLabArtifactProctorReportSource($this->oneReport(new MockResponse(str_repeat(' ', 1_048_577))));

        $this->assertNull($source->report('demo/ledger'));
    }

    public function test_a_malformed_body_is_handed_on_and_becomes_a_note_like_a_bad_file_in_the_directory(): void
    {
        $gitlab = $this->oneReport(new MockResponse('not a report'));
        $source = new GitLabArtifactProctorReportSource($gitlab);

        $this->assertSame('not a report', $source->report('demo/ledger'), 'the reader judges nothing, as the directory reader');

        $this->requests = [];
        $scanner = new Scanner($gitlab, null, $source, new FixedClock('2026-11-09'), new ComposerReader());
        $apps = [];
        foreach ($scanner->scan()->apps as $app) {
            $apps[$app->id] = $app;
        }

        $this->assertContains('PROCTOR report could not be read', $apps['demo/ledger']->notes);
        $this->assertNull($apps['demo/ledger']->proctorResult);
        $this->assertArrayHasKey('demo/sub/atlas', $apps, 'the rest of the scan carries on');
    }

    public function test_the_job_and_artifact_settings_are_checked(): void
    {
        $this->assertTrue(GitLabArtifactProctorReportSource::validJob('proctor'));
        $this->assertTrue(GitLabArtifactProctorReportSource::validJob('proctor_api.v2'));
        $this->assertFalse(GitLabArtifactProctorReportSource::validJob('-proctor'));
        $this->assertFalse(GitLabArtifactProctorReportSource::validJob('proctor&job=other'));
        $this->assertTrue(GitLabArtifactProctorReportSource::validArtifact('proctor.json'));
        $this->assertTrue(GitLabArtifactProctorReportSource::validArtifact('reports/proctor.json'));
        foreach (['/etc/passwd', '../proctor.json', 'a/../b.json', 'reports/', 'proctor.json?x=1', ''] as $bad) {
            $this->assertFalse(GitLabArtifactProctorReportSource::validArtifact($bad), $bad);
        }

        $this->expectException(RollcallException::class);
        $this->expectExceptionMessage('ROLLCALL_PROCTOR_ARTIFACT must be a relative path');
        Config::fromEnv(['ROLLCALL_PROCTOR_ARTIFACT' => '../proctor.json']);
    }

    public function test_the_setting_defaults_to_the_directory_and_refuses_other_values(): void
    {
        $config = Config::fromEnv([]);
        $this->assertSame('directory', $config->proctorSource);
        $this->assertSame('proctor', $config->proctorJob);
        $this->assertSame('proctor.json', $config->proctorArtifact);

        $this->expectException(RollcallException::class);
        $this->expectExceptionMessage('ROLLCALL_PROCTOR_SOURCE must be "directory" or "gitlab".');
        Config::fromEnv(['ROLLCALL_PROCTOR_SOURCE' => 's3']);
    }

    public function test_the_switch_chooses_the_artifact_reader_and_shares_the_scans_gitlab_adapter(): void
    {
        $report = $this->fixture('proctor/demo__ledger.json');
        $gitlab = $this->gitlab(static function (string $method, string $url) use ($report): ?MockResponse {
            if (str_contains($url, '/projects/101/pipelines?')) {
                return new MockResponse('[{"id": 900}]');
            }
            if (str_contains($url, '/projects/101/pipelines/900/jobs')) {
                return new MockResponse('[{"id": 9001, "name": "proctor", "status": "success"}]');
            }
            if (str_contains($url, '/projects/101/jobs/9001/artifacts/proctor.json')) {
                return new MockResponse($report);
            }
            if (str_contains($url, '/pipelines?')) {
                return new MockResponse('[]');
            }

            return null; // every repository file is missing; the scan still runs
        });
        $factory = new Factory(Config::fromEnv([
            'ROLLCALL_PROCTOR_SOURCE' => 'gitlab',
            // Ignored when the source is gitlab: a directory that does not exist would otherwise throw.
            'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/no-such-directory',
        ]), new FixedClock('2026-11-09'));

        $apps = [];
        foreach ($factory->scanner($gitlab)->scan()->apps as $app) {
            $apps[$app->id] = $app;
        }

        $this->assertSame('pass', $apps['demo/ledger']->proctorResult);
        $this->assertSame('0.1.3', $apps['demo/ledger']->proctorVersion);
        $this->assertNull($apps['demo/sub/atlas']->proctorResult, 'no pipeline is no report');
        $listings = array_filter($this->requests, static fn(array $r): bool => str_contains($r['url'], '/groups/demo/projects'));
        $this->assertCount(1, $listings, 'the reader reuses the scan adapter, so the project list is read once');
    }

    public function test_the_switch_set_to_gitlab_needs_the_gitlab_settings_even_with_a_directory_source(): void
    {
        $factory = new Factory(Config::fromEnv([
            'ROLLCALL_SOURCE' => 'directory',
            'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
            'ROLLCALL_PROCTOR_SOURCE' => 'gitlab',
            'ROLLCALL_GITLAB_TOKEN' => self::TOKEN,
        ]), new FixedClock('2026-11-09'));

        $this->expectException(RollcallException::class);
        $this->expectExceptionMessage('Set ROLLCALL_GITLAB_URL');
        $factory->scanner();
    }

    public function test_the_default_switch_still_reads_the_directory(): void
    {
        $factory = new Factory(Config::fromEnv([
            'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
            'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/proctor',
        ]), new FixedClock('2026-10-28'));

        $results = array_map(static fn(AppFacts $a): ?string => $a->proctorResult, $factory->scanner()->scan()->apps);

        $this->assertContains('pass', $results, 'demo/ledger from tests/fixtures/proctor');
        $this->assertContains('fail', $results, 'demo/atlas from tests/fixtures/proctor');
    }
}
