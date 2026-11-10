<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Advisor;

use DateTimeImmutable;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\Module;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Module\PromptDefinition;
use Waynestate\Advisor\Module\ResourceDefinition;
use Waynestate\Advisor\Module\ToolDefinition;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Creed\State;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Storage\SnapshotStore;

/**
 * ADVISOR's Rollcall module: three read-only tools over the stored snapshots.
 *
 * The tools call the existing report builder and snapshot store, they never
 * re-implement them, and none takes a path, command or URL. Every answer is
 * the public report only (the same fields /report.json serves): the per-app
 * list is internal and never a tool output. Support states come from CREED's
 * policy, judged on the day ADVISOR's clock says is today, so a test can pin
 * the date. Each result names its source and its dates.
 */
final class RollcallModule implements Module
{
    /** Where the fleet answers come from, said without a host path. */
    private const SNAPSHOT_SOURCE = 'Rollcall snapshot store (ROLLCALL_STORE_DIR)';

    /** Where the version answers come from, said without a host path. */
    private const POLICY_SOURCE = 'CREED policy.json, bundled with waynestate/rollcall';

    /** The public report fields a fleet summary carries. */
    private const SUMMARY_FIELDS = [
        'totals',
        'image_states',
        'php_image',
        'php_floor',
        'laravel',
        'auth_package',
        'proctor',
        'mysql56_ci',
        'drift',
        'progress',
        'forced_upgrades',
    ];

    public function __construct(private readonly ModuleContext $context) {}

    public function id(): string
    {
        return 'rollcall';
    }

    /** @return list<ToolDefinition> */
    public function tools(): array
    {
        return [
            new ToolDefinition(
                name: 'rollcall_fleet_summary',
                title: 'Fleet summary from the latest scan',
                description: 'Counts from Rollcall\'s latest stored snapshot: apps by PHP image, declared PHP floor, Laravel line, auth package and PROCTOR result, drift per kind, progress since the previous scan, pending and overdue forced image upgrades from a Citadel export (with the next due date), and the snapshot date. Public counts only: no app names, owners or rankings. If no snapshot exists it says so in plain words and names rollcall scan. Read-only; every answer names its source and dates.',
                inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                handler: fn(array $args): array => $this->fleetSummary(),
                readOnly: true,
                idempotent: true,
                openWorld: false,
            ),
            new ToolDefinition(
                name: 'rollcall_burn_down',
                title: 'EOL burn-down series',
                description: 'The dated EOL burn-down from Rollcall\'s stored snapshots: for each scan day, how many apps ran an end-of-life PHP image that day, plus the supported and unknown counts and the same roll per support state, per PHP minor of the image and per Laravel major. Optional days (integer 1 to 365) keeps only the most recent scan days; the default is every stored day. Public counts only. If no snapshot exists it says so in plain words and names rollcall scan. Read-only; every answer names its source and dates.',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'days' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 365,
                            'description' => 'How many of the most recent scan days to include. Default: all of them.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                handler: fn(array $args): array => $this->burnDown($args),
                readOnly: true,
                idempotent: true,
                openWorld: false,
            ),
            new ToolDefinition(
                name: 'rollcall_supported_versions',
                title: 'What CREED\'s policy supports now',
                description: 'Every version in CREED\'s policy.json (bundled with waynestate/rollcall), grouped by what the policy says today: active, security only, or end of life, with the active_until and security_until dates of each line. No network call: the answer is the bundled file judged on today\'s date. Read-only; the answer names its source and date.',
                inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                handler: fn(array $args): array => $this->supportedVersions(),
                readOnly: true,
                idempotent: true,
                openWorld: false,
            ),
        ];
    }

    /** @return list<ResourceDefinition> */
    public function resources(): array
    {
        return [];
    }

    /** @return list<PromptDefinition> */
    public function prompts(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    private function fleetSummary(): array
    {
        [$store, $latest] = $this->latestSnapshot();
        if ($latest === null) {
            return $this->noSnapshot();
        }

        $today = $this->today();
        $report = (new ReportBuilder($this->policy()))->build($latest, $store->all(), $today, false);

        $summary = [
            'source' => self::SNAPSHOT_SOURCE,
            'as_of' => $today->format('Y-m-d'),
            'scan_date' => $latest->date->format('Y-m-d'),
        ];
        foreach (self::SUMMARY_FIELDS as $field) {
            $summary[$field] = $report[$field];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function burnDown(array $args): array
    {
        $days = null;
        if (array_key_exists('days', $args)) {
            $value = $args['days'];
            if (!is_int($value) || $value < 1 || $value > 365) {
                throw new ToolInputError('days must be an integer between 1 and 365.');
            }
            $days = $value;
        }

        [$store, $latest] = $this->latestSnapshot();
        if ($latest === null) {
            return $this->noSnapshot();
        }

        $today = $this->today();
        $series = (new ReportBuilder($this->policy()))->build($latest, $store->all(), $today, false)['burn_down'];
        if (!is_array($series)) {
            throw new RollcallException('The report has no burn-down series.');
        }
        if ($days !== null) {
            $series = array_slice($series, -$days);
        }

        return [
            'source' => self::SNAPSHOT_SOURCE,
            'as_of' => $today->format('Y-m-d'),
            'scan_date' => $latest->date->format('Y-m-d'),
            'series' => array_values($series),
        ];
    }

    /** @return array<string, mixed> */
    private function supportedVersions(): array
    {
        $policy = $this->policy();
        $today = $this->today();
        $components = [];
        foreach ($policy->components() as $component) {
            $buckets = ['active' => [], 'security_only' => [], 'end_of_life' => []];
            foreach ($policy->versions($component) as $version) {
                $state = $policy->stateOf($component, $version, $today);
                $bucket = match ($state) {
                    State::Active => 'active',
                    State::Security => 'security_only',
                    State::Eol, State::EolGraceExpired => 'end_of_life',
                };
                $buckets[$bucket][] = [
                    'version' => $version,
                    'state' => $state->value,
                    'active_until' => $policy->activeUntil($component, $version)?->format('Y-m-d'),
                    'security_until' => $policy->securityUntil($component, $version)?->format('Y-m-d'),
                ];
            }
            $components[$component] = $buckets;
        }

        return [
            'source' => self::POLICY_SOURCE,
            'as_of' => $today->format('Y-m-d'),
            'components' => $components,
        ];
    }

    /**
     * An empty store, in plain words, naming the command that fills it.
     *
     * @return array<string, mixed>
     */
    private function noSnapshot(): array
    {
        return [
            'source' => self::SNAPSHOT_SOURCE,
            'as_of' => $this->today()->format('Y-m-d'),
            'note' => 'No snapshot yet: run rollcall scan to take one.',
        ];
    }

    /**
     * The newest stored snapshot, or null when the store is empty.
     *
     * @return array{0: SnapshotStore, 1: ?\Waynestate\Rollcall\Model\Snapshot}
     */
    private function latestSnapshot(): array
    {
        $store = new JsonFileSnapshotStore($this->config()->storeDir);

        return [$store, $store->latest()];
    }

    /**
     * The policy the answers are judged against: ROLLCALL_POLICY_FILE when
     * set, like the rest of the app, otherwise the bundled CREED policy.
     */
    private function policy(): Policy
    {
        $config = $this->config();
        if ($config->policyFile !== null) {
            return Policy::fromFile($config->policyFile);
        }

        return Policy::bundled();
    }

    private function config(): Config
    {
        return Config::fromEnv(array_merge($_ENV, getenv()));
    }

    private function today(): DateTimeImmutable
    {
        return $this->context->clock->today();
    }
}
