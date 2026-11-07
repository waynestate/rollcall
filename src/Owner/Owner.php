<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Owner;

/** One owner entry: a contact, whether it opted in to the digest, and its apps. */
final class Owner
{
    /** @param  list<string>  $projects  group/project paths */
    public function __construct(
        public readonly string $contact,
        public readonly bool $digest,
        public readonly array $projects,
    ) {}

    /** A safe file-name stem for this contact, such as "team-example-test". */
    public function slug(): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->contact)), '-');

        return $slug === '' ? 'owner' : substr($slug, 0, 80);
    }
}
