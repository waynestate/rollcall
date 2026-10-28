<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Model;

use DateTimeImmutable;
use Waynestate\Rollcall\RollcallException;

/**
 * One scan: the date it ran and every app's facts. Snapshots are history for
 * the burn-down; `rollcall prune` drops old ones but always keeps the newest.
 */
final class Snapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<AppFacts>  $apps
     * @param  list<string>  $errors  per-source problems that did not stop the scan
     */
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly array $apps,
        public readonly array $errors = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'date' => $this->date->format('Y-m-d'),
            'errors' => $this->errors,
            'apps' => array_map(static fn(AppFacts $a): array => $a->toArray(), $this->apps),
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RollcallException('Snapshot has an unknown schema_version.');
        }
        $date = is_string($data['date'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $data['date']) : false;
        if ($date === false) {
            throw new RollcallException('Snapshot has no valid date.');
        }
        $apps = [];
        foreach (is_array($data['apps'] ?? null) ? $data['apps'] : [] as $app) {
            if (is_array($app)) {
                $apps[] = AppFacts::fromArray($app);
            }
        }
        $errors = [];
        foreach (is_array($data['errors'] ?? null) ? $data['errors'] : [] as $error) {
            if (is_string($error)) {
                $errors[] = $error;
            }
        }

        return new self($date, $apps, $errors);
    }
}
