<?php

declare(strict_types=1);

namespace Waynestate\Rollcall;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Metrics\MetricsGuard;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\CitadelExportSource;
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

        // The generated CREED fromFile() docblock omits the RuntimeException its loadFile() can
        // throw when the file exists but cannot be read. Both exception types must become a
        // RollcallException so a bad policy file keeps the CLI's exit code 2.
        try {
            return Policy::fromFile($this->config->policyFile);
        } catch (InvalidArgumentException|RuntimeException $e) { // @phpstan-ignore catch.neverThrown (see comment above the try)
            throw new RollcallException(sprintf('Policy file "%s" is missing or unreadable. Set ROLLCALL_POLICY_FILE.', $this->config->policyFile));
        }
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
            if ($this->config->gitlabUrl === null || ($this->config->gitlabGroups === [] && $this->config->gitlabProjects === [])) {
                throw new RollcallException('Set ROLLCALL_GITLAB_URL and ROLLCALL_GITLAB_GROUPS (or ROLLCALL_GITLAB_PROJECTS) to scan GitLab. Set it in the environment, or install waynestate/playbook (see the README).');
            }
            $psr17 = new Psr17Factory();

            return new GitLabRepositorySource(
                new Psr18Client(HttpClient::create(['timeout' => 20, 'max_redirects' => 0]), $psr17, $psr17),
                $psr17,
                $this->config->gitlabUrl,
                $this->config->gitlabToken(),
                $this->config->gitlabGroups,
                projects: $this->config->gitlabProjects,
            );
        }
        if ($this->config->reposDir === null) {
            throw new RollcallException('Set ROLLCALL_REPOS_DIR to a repository fixture directory (or ROLLCALL_SOURCE=gitlab).');
        }

        return new DirectoryRepositorySource($this->config->reposDir);
    }

    /**
     * One image source or none. The Citadel export is one platform's source
     * among others; the plain image list serves every other platform. Both
     * at once would leave two answers for one app, so that is refused.
     */
    private function images(): ?ImageSource
    {
        if ($this->config->citadelExport !== null) {
            if ($this->config->imagesFile !== null) {
                throw new RollcallException('Set ROLLCALL_IMAGES_FILE or ROLLCALL_CITADEL_EXPORT, not both: each is a complete image source.');
            }

            return CitadelExportSource::fromFile($this->config->citadelExport);
        }

        return $this->config->imagesFile === null ? null : JsonFileImageSource::fromFile($this->config->imagesFile);
    }

    private function reports(): ?ProctorReportSource
    {
        return $this->config->proctorDir === null ? null : new DirectoryProctorReportSource($this->config->proctorDir);
    }
}
