<?php

declare(strict_types=1);

namespace Waynestate\Rollcall;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Metrics\MetricsGuard;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Policy\Policy;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\DirectoryProctorReportSource;
use Waynestate\Rollcall\Source\DirectoryRepositorySource;
use Waynestate\Rollcall\Source\GitLab\GitLabRepositorySource;
use Waynestate\Rollcall\Source\ImageSource;
use Waynestate\Rollcall\Source\JsonFileImageSource;
use Waynestate\Rollcall\Source\ProctorReportSource;
use Waynestate\Rollcall\Source\RepositorySource;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Storage\SnapshotStore;

/**
 * Wires the core from a Config. The CLI, the raw-PHP entry point and the
 * Laravel adapter all go through here, so none of them holds a rule.
 */
final class Factory
{
    public function __construct(private readonly Config $config, private readonly ClockInterface $clock = new SystemClock()) {}

    public function policy(): Policy
    {
        if ($this->config->policyFile === null) {
            throw new RollcallException('No policy file. Set ROLLCALL_POLICY_FILE to CREED\'s policy.json.');
        }

        return Policy::fromFile($this->config->policyFile);
    }

    public function store(): SnapshotStore
    {
        return new JsonFileSnapshotStore($this->config->storeDir);
    }

    public function reportBuilder(): ReportBuilder
    {
        return new ReportBuilder($this->policy());
    }

    public function metricsGuard(): MetricsGuard
    {
        return new MetricsGuard($this->config->metricsToken(), $this->config->metricsAllow);
    }

    public function scanner(?RepositorySource $repositories = null): Scanner
    {
        return new Scanner(
            $repositories ?? $this->repositories(),
            $this->images(),
            $this->reports(),
            $this->clock,
            new ComposerReader($this->config->authPackages !== [] ? $this->config->authPackages : ComposerReader::DEFAULT_AUTH_PACKAGES),
        );
    }

    public function repositories(): RepositorySource
    {
        if ($this->config->source === 'gitlab') {
            if ($this->config->gitlabUrl === null || $this->config->gitlabGroups === []) {
                throw new RollcallException('Set ROLLCALL_GITLAB_URL and ROLLCALL_GITLAB_GROUPS to scan GitLab.');
            }
            $psr17 = new Psr17Factory();

            return new GitLabRepositorySource(
                new Psr18Client(HttpClient::create(['timeout' => 20, 'max_redirects' => 0]), $psr17, $psr17),
                $psr17,
                $this->config->gitlabUrl,
                $this->config->gitlabToken(),
                $this->config->gitlabGroups,
            );
        }
        if ($this->config->reposDir === null) {
            throw new RollcallException('Set ROLLCALL_REPOS_DIR to a repository fixture directory (or ROLLCALL_SOURCE=gitlab).');
        }

        return new DirectoryRepositorySource($this->config->reposDir);
    }

    private function images(): ?ImageSource
    {
        return $this->config->imagesFile === null ? null : JsonFileImageSource::fromFile($this->config->imagesFile);
    }

    private function reports(): ?ProctorReportSource
    {
        return $this->config->proctorDir === null ? null : new DirectoryProctorReportSource($this->config->proctorDir);
    }
}
