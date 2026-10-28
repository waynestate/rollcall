<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

use Waynestate\Rollcall\RollcallException;

/**
 * Reads an export of the app list: {"apps": [{"project": "group/project", "image": "php:8.4-apache"}]}.
 * The Citadel team's export is the expected producer; ROLLCALL never calls
 * Citadel itself in 0.1. Also serves as the in-memory fake: build it with
 * fromArray().
 */
final class JsonFileImageSource implements ImageSource
{
    /** @param  array<string, string>  $images  project id to image tag */
    private function __construct(private readonly array $images) {}

    /** @param  array<string, string>  $images */
    public static function fromArray(array $images): self
    {
        return new self($images);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RollcallException(sprintf('Image list "%s" is missing or unreadable. Set ROLLCALL_IMAGES_FILE.', $path));
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RollcallException('Image list is not valid JSON: ' . $e->getMessage());
        }
        if (! is_array($data) || ! is_array($data['apps'] ?? null)) {
            throw new RollcallException('Image list must be an object with an "apps" array.');
        }

        $images = [];
        foreach ($data['apps'] as $app) {
            if (is_array($app) && is_string($app['project'] ?? null) && is_string($app['image'] ?? null)) {
                $images[$app['project']] = $app['image'];
            }
        }

        return new self($images);
    }

    public function imageTag(string $project): ?string
    {
        return $this->images[$project] ?? null;
    }
}
