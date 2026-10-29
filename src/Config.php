<?php

declare(strict_types=1);

namespace Waynestate\Rollcall;

use Waynestate\Rollcall\Support\Setting;

/**
 * Every setting, read from environment variables. The GitLab URL also falls
 * back to PLAYBOOK (waynestate/playbook) when the environment has none;
 * tokens never do. Nothing is read from a file in the repository and nothing
 * has a secret default. Secrets are hidden from var_dump() and print_r() so a
 * debug line cannot leak them.
 */
final class Config
{
    /**
     * @param  list<string>  $gitlabGroups
     * @param  list<string>  $authPackages
     * @param  list<string>  $metricsAllow
     */
    public function __construct(
        public readonly ?string $policyFile = null,
        public readonly string $storeDir = 'var/snapshots',
        public readonly string $source = 'directory',
        public readonly ?string $reposDir = null,
        public readonly ?string $gitlabUrl = null,
        private readonly string $gitlabToken = '',
        public readonly array $gitlabGroups = [],
        public readonly ?string $imagesFile = null,
        public readonly ?string $proctorDir = null,
        public readonly array $authPackages = [],
        public readonly bool $metricsEnabled = false,
        private readonly string $metricsToken = '',
        public readonly array $metricsAllow = [],
        public readonly bool $publicReportEnabled = false,
        public readonly int $retentionDays = 90,
    ) {}

    /** @param  array<string, string|false|null>  $env */
    public static function fromEnv(array $env): self
    {
        $get = static fn(string $key): ?string => isset($env[$key]) && is_string($env[$key]) && $env[$key] !== '' ? $env[$key] : null;
        $list = static fn(?string $value): array => $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''));

        $source = $get('ROLLCALL_SOURCE') ?? 'directory';
        if (! in_array($source, ['directory', 'gitlab'], true)) {
            throw new RollcallException('ROLLCALL_SOURCE must be "directory" or "gitlab".');
        }

        return new self(
            policyFile: $get('ROLLCALL_POLICY_FILE'),
            storeDir: $get('ROLLCALL_STORE_DIR') ?? 'var/snapshots',
            source: $source,
            reposDir: $get('ROLLCALL_REPOS_DIR'),
            gitlabUrl: Setting::get('ROLLCALL_GITLAB_URL'),
            gitlabToken: $get('ROLLCALL_GITLAB_TOKEN') ?? '',
            gitlabGroups: $list($get('ROLLCALL_GITLAB_GROUPS')),
            imagesFile: $get('ROLLCALL_IMAGES_FILE'),
            proctorDir: $get('ROLLCALL_PROCTOR_DIR'),
            authPackages: array_map('strtolower', $list($get('ROLLCALL_AUTH_PACKAGES'))),
            metricsEnabled: self::truthy($get('ROLLCALL_METRICS_ENABLED')),
            metricsToken: $get('ROLLCALL_METRICS_TOKEN') ?? '',
            metricsAllow: $list($get('ROLLCALL_METRICS_ALLOW')),
            publicReportEnabled: self::truthy($get('ROLLCALL_PUBLIC_REPORT_ENABLED')),
            retentionDays: self::days($get('ROLLCALL_RETENTION_DAYS'), 90),
        );
    }

    private static function truthy(?string $value): bool
    {
        return in_array(strtolower($value ?? ''), ['1', 'true', 'yes', 'on'], true);
    }

    /** A whole number of days; 0 keeps every snapshot forever. */
    private static function days(?string $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RollcallException('ROLLCALL_RETENTION_DAYS must be a whole number of days (0 keeps everything).');
        }

        return (int) $value;
    }

    public function gitlabToken(): string
    {
        return $this->gitlabToken;
    }

    public function metricsToken(): string
    {
        return $this->metricsToken;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $vars = get_object_vars($this);
        foreach (['gitlabToken', 'metricsToken'] as $secret) {
            $vars[$secret] = $vars[$secret] === '' ? '' : '***';
        }

        return $vars;
    }
}
