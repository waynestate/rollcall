<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

use DateTimeImmutable;
use Waynestate\Rollcall\RollcallException;

/**
 * Reads a saved export of Citadel's registrations, in Citadel's own words:
 *
 *     {"applications": [{"repository": "group/project", "base_image": "citadel/php-apache:8.4r5"}],
 *      "base_image_upgrades": [{"old_image": "...", "new_image": "...", "due_date": "2026-12-15", "completed": false}]}
 *
 * The shape is contract/citadel-export.schema.json. Rollcall never calls
 * Citadel: a person or a job saves the export, and ROLLCALL_CITADEL_EXPORT
 * points at the file. If Citadel ever serves the same JSON from a read-only
 * endpoint, saving that answer to a file is all it takes. One image source
 * among others: OpenShift, Coolify or plain Docker use ROLLCALL_IMAGES_FILE.
 */
final class CitadelExportSource implements ImageSource, UpgradeDueSource
{
    private const MAX_APPLICATIONS = 5000;

    private const MAX_UPGRADES = 500;

    /**
     * @param  array<string, string>  $images  repository path to base image
     * @param  array<string, string>  $due  base image to the earliest pending due date
     */
    private function __construct(private readonly array $images, private readonly array $due) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RollcallException(sprintf('Citadel export "%s" is missing or unreadable. Set ROLLCALL_CITADEL_EXPORT.', $path));
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RollcallException('Citadel export is not valid JSON: ' . $e->getMessage());
        }
        if (! is_array($data)) {
            throw new RollcallException('Citadel export must be an object with an "applications" list.');
        }

        return self::fromArray($data);
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $applications = $data['applications'] ?? null;
        if (! is_array($applications) || ! array_is_list($applications)) {
            throw new RollcallException('Citadel export must be an object with an "applications" list.');
        }
        if (count($applications) > self::MAX_APPLICATIONS) {
            throw new RollcallException(sprintf('Citadel export lists more than %d applications.', self::MAX_APPLICATIONS));
        }
        $upgrades = $data['base_image_upgrades'] ?? [];
        if (! is_array($upgrades) || ! array_is_list($upgrades) || count($upgrades) > self::MAX_UPGRADES) {
            throw new RollcallException(sprintf('Citadel export "base_image_upgrades" must be a list of at most %d upgrades.', self::MAX_UPGRADES));
        }

        // An entry Rollcall cannot read is skipped, like the image list does: the app then has no image, and the
        // report says unknown rather than guessing.
        $images = [];
        foreach ($applications as $app) {
            if (is_array($app) && is_string($app['repository'] ?? null) && $app['repository'] !== '' && is_string($app['base_image'] ?? null) && $app['base_image'] !== '') {
                $images[$app['repository']] = substr($app['base_image'], 0, 200);
            }
        }

        $due = [];
        foreach ($upgrades as $upgrade) {
            if (! is_array($upgrade) || ($upgrade['completed'] ?? null) !== false || ! is_string($upgrade['old_image'] ?? null)) {
                continue; // completed, or not a shape we can trust
            }
            $date = is_string($upgrade['due_date'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $upgrade['due_date']) : false;
            if ($date === false || $date->format('Y-m-d') !== $upgrade['due_date']) {
                continue;
            }
            $image = substr($upgrade['old_image'], 0, 200);
            if (! isset($due[$image]) || $upgrade['due_date'] < $due[$image]) {
                $due[$image] = $upgrade['due_date'];
            }
        }

        return new self($images, $due);
    }

    public function imageTag(string $project): ?string
    {
        return $this->images[$project] ?? null;
    }

    public function upgradeDue(string $project): ?string
    {
        $image = $this->images[$project] ?? null;

        return $image === null ? null : ($this->due[$image] ?? null);
    }
}
