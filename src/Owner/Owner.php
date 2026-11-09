<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Owner;

/**
 * One owner entry: a contact, whether it opted in to the digest, its apps,
 * and the day the contact last confirmed it still answers for them.
 */
final class Owner
{
    /**
     * @param  list<string>  $projects  group/project paths
     * @param  string|null  $confirmed  YYYY-MM-DD, or null when nobody ever confirmed
     */
    public function __construct(
        public readonly string $contact,
        public readonly bool $digest,
        public readonly array $projects,
        public readonly ?string $confirmed = null,
    ) {}

    /** A safe file-name stem for this contact, such as "team-example-test". */
    public function slug(): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->contact)), '-');

        return $slug === '' ? 'owner' : substr($slug, 0, 80);
    }
}
