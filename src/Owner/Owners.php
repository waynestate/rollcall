<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Owner;

use Waynestate\Rollcall\RollcallException;

/**
 * Who answers for which apps, read from the file behind ROLLCALL_OWNERS_FILE:
 * {"owners": [{"contact": "team@example.test", "digest": true, "projects": ["group/project"]}]}.
 *
 * Internal data, kept by the people who run Rollcall, never in git and never
 * in a public report. An owner gets a digest only when its entry says
 * "digest": true; leaving the key out means no. Rollcall never guesses an
 * owner from commit authors (the substitute teacher does not assign seats).
 */
final class Owners
{
    private const MAX_OWNERS = 500;

    private const MAX_PROJECTS = 500;

    /** @param  list<Owner>  $owners */
    private function __construct(public readonly array $owners) {}

    /** @param  list<Owner>  $owners */
    public static function fromList(array $owners): self
    {
        return new self($owners);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RollcallException(sprintf('Owners file "%s" is missing or unreadable. Set ROLLCALL_OWNERS_FILE.', $path));
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RollcallException('Owners file is not valid JSON: ' . $e->getMessage());
        }
        if (! is_array($data) || ! is_array($data['owners'] ?? null) || ! array_is_list($data['owners'])) {
            throw new RollcallException('Owners file must be an object with an "owners" list.');
        }
        if (count($data['owners']) > self::MAX_OWNERS) {
            throw new RollcallException(sprintf('Owners file lists more than %d owners.', self::MAX_OWNERS));
        }

        $owners = [];
        $seen = [];
        foreach ($data['owners'] as $i => $entry) {
            $where = sprintf('Owners file entry %d', $i + 1);
            if (! is_array($entry)) {
                throw new RollcallException($where . ' must be an object.');
            }
            $contact = $entry['contact'] ?? null;
            if (! is_string($contact) || trim($contact) === '' || strlen($contact) > 200 || preg_match('/[\x00-\x1F\x7F]/', $contact) === 1) {
                throw new RollcallException($where . ' needs a "contact": a mail address or team alias of at most 200 characters.');
            }
            $contact = trim($contact);
            if (isset($seen[strtolower($contact)])) {
                throw new RollcallException(sprintf('%s repeats the contact "%s"; list each contact once.', $where, $contact));
            }
            $seen[strtolower($contact)] = true;
            $digest = $entry['digest'] ?? false;
            if (! is_bool($digest)) {
                throw new RollcallException($where . ' has a "digest" that is not true or false.');
            }
            $projects = $entry['projects'] ?? null;
            if (! is_array($projects) || ! array_is_list($projects) || count($projects) > self::MAX_PROJECTS) {
                throw new RollcallException(sprintf('%s needs a "projects" list of at most %d group/project paths.', $where, self::MAX_PROJECTS));
            }
            $paths = [];
            foreach ($projects as $project) {
                if (! is_string($project) || preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)+$#', $project) !== 1) {
                    throw new RollcallException($where . ' has a project that is not a group/project path.');
                }
                $paths[] = $project;
            }
            $owners[] = new Owner($contact, $digest, array_values(array_unique($paths)));
        }

        return new self($owners);
    }

    /** @return list<Owner> the owners who asked for a digest */
    public function optedIn(): array
    {
        return array_values(array_filter($this->owners, static fn(Owner $o): bool => $o->digest));
    }

    public function find(string $contact): ?Owner
    {
        foreach ($this->owners as $owner) {
            if (strcasecmp($owner->contact, $contact) === 0) {
                return $owner;
            }
        }

        return null;
    }
}
