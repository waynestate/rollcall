<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Model;

/**
 * What one app answered when ROLLCALL called its name. Facts only: the state
 * of each version is derived from the policy on the day a report is built, so
 * an old snapshot can be re-read against the dates that applied then.
 *
 * The id is the repository path (for example "group/project"). It is internal:
 * public reports never carry it.
 */
final class AppFacts
{
    /**
     * @param  list<OverrideNote>  $overrides
     * @param  array<string, string>  $blobs  file name to the blob id last read, so an unchanged file is not fetched again
     * @param  list<string>  $notes  plain-language gaps, such as "composer.lock not found"
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $phpConstraint = null,
        public readonly ?string $phpFloor = null,
        public readonly ?string $imageTag = null,
        public readonly ?string $phpImage = null,
        public readonly ?string $laravel = null,
        public readonly ?string $authPackage = null,
        public readonly ?string $authVersion = null,
        public readonly ?string $proctorResult = null,
        public readonly ?string $proctorVersion = null,
        public readonly array $overrides = [],
        public readonly array $blobs = [],
        public readonly array $notes = [],
    ) {}

    /** The Laravel major, such as "12", or null. */
    public function laravelMajor(): ?string
    {
        if ($this->laravel === null) {
            return null;
        }

        return preg_match('/^(\d+)\./', $this->laravel, $m) === 1 ? $m[1] : null;
    }

    /** @param list<OverrideNote>|null $overrides */
    public function with(
        ?string $imageTag = null,
        ?string $phpImage = null,
        ?string $proctorResult = null,
        ?string $proctorVersion = null,
        ?array $overrides = null,
    ): self {
        return new self(
            $this->id,
            $this->phpConstraint,
            $this->phpFloor,
            $imageTag ?? $this->imageTag,
            $phpImage ?? $this->phpImage,
            $this->laravel,
            $this->authPackage,
            $this->authVersion,
            $proctorResult ?? $this->proctorResult,
            $proctorVersion ?? $this->proctorVersion,
            $overrides ?? $this->overrides,
            $this->blobs,
            $this->notes,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'php_constraint' => $this->phpConstraint,
            'php_floor' => $this->phpFloor,
            'image_tag' => $this->imageTag,
            'php_image' => $this->phpImage,
            'laravel' => $this->laravel,
            'auth_package' => $this->authPackage,
            'auth_version' => $this->authVersion,
            'proctor_result' => $this->proctorResult,
            'proctor_version' => $this->proctorVersion,
            'overrides' => array_map(static fn(OverrideNote $o): array => $o->toArray(), $this->overrides),
            'blobs' => $this->blobs,
            'notes' => $this->notes,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $str = static fn(string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;

        $overrides = [];
        foreach (is_array($data['overrides'] ?? null) ? $data['overrides'] : [] as $override) {
            if (is_array($override)) {
                $overrides[] = OverrideNote::fromArray($override);
            }
        }
        $blobs = [];
        foreach (is_array($data['blobs'] ?? null) ? $data['blobs'] : [] as $file => $blob) {
            if (is_string($blob)) {
                $blobs[(string) $file] = $blob;
            }
        }
        $notes = [];
        foreach (is_array($data['notes'] ?? null) ? $data['notes'] : [] as $note) {
            if (is_string($note)) {
                $notes[] = $note;
            }
        }

        return new self(
            $str('id') ?? '',
            $str('php_constraint'),
            $str('php_floor'),
            $str('image_tag'),
            $str('php_image'),
            $str('laravel'),
            $str('auth_package'),
            $str('auth_version'),
            $str('proctor_result'),
            $str('proctor_version'),
            $overrides,
            $blobs,
            $notes,
        );
    }
}
