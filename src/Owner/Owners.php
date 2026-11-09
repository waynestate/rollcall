<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Owner;

use DateTimeImmutable;
use Waynestate\Rollcall\RollcallException;

/**
 * Who answers for which apps, read from the file behind ROLLCALL_OWNERS_FILE:
 * {"owners": [{"contact": "team@example.test", "digest": true, "confirmed": "2026-11-01", "projects": ["group/project"]}]}.
 * "confirmed" is the day the contact last said it still answers for those
 * apps; `rollcall owners` calls an owner unknown once that day is older than
 * ROLLCALL_OWNERS_CONFIRM_DAYS.
 *
 * Rollcall keeps its own file for the stack's projects in the repo
 * (owners.json, roles and team aliases only, because the repo is public); a
 * deployer scanning another fleet points ROLLCALL_OWNERS_FILE at theirs. It is
 * read only by `rollcall digest`, never by a report. An owner gets a digest
 * only when its entry says "digest": true; leaving the key out means no. An
 * app no entry lists is "no owner on file", said out loud, never dropped.
 * Rollcall never guesses an owner from commit authors (the substitute teacher
 * does not assign seats).
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
            $confirmed = $entry['confirmed'] ?? null;
            if ($confirmed !== null && (! is_string($confirmed) || ! self::isDate($confirmed))) {
                throw new RollcallException($where . ' has a "confirmed" that is not a YYYY-MM-DD date.');
            }
            $owners[] = new Owner($contact, $digest, array_values(array_unique($paths)), $confirmed);
        }

        return new self($owners);
    }

    /** @return list<Owner> the owners who asked for a digest */
    public function optedIn(): array
    {
        return array_values(array_filter($this->owners, static fn(Owner $o): bool => $o->digest));
    }

    /**
     * The apps that no entry lists, opted in or not: "no owner on file".
     *
     * @param  list<string>  $appIds  the group/project paths of a scan
     * @return list<string> the ones nobody answers for, in scan order
     */
    public function unowned(array $appIds): array
    {
        $owned = [];
        foreach ($this->owners as $owner) {
            foreach ($owner->projects as $project) {
                $owned[$project] = true;
            }
        }

        return array_values(array_unique(array_filter($appIds, static fn(string $id): bool => ! isset($owned[$id]))));
    }

    /**
     * The apps an entry lists, but no entry that lists them was confirmed
     * within the last $days days: their owner is on file, yet nobody has said
     * lately that it is still true, so the owner is unknown. A confirmation
     * dated after today is not trusted (a typo is not a promise). With $days
     * 0 the check is off and nothing is returned.
     *
     * @param  list<string>  $appIds  the group/project paths of a scan
     * @return list<array{app: string, last_confirmed: string|null}> in scan order
     */
    public function unconfirmed(array $appIds, DateTimeImmutable $today, int $days): array
    {
        if ($days <= 0) {
            return [];
        }
        $day = $today->format('Y-m-d');
        $cutoff = $today->modify(sprintf('-%d days', $days))->format('Y-m-d');

        /** @var array<string, string|null> $last the latest trusted confirmation per listed app */
        $last = [];
        foreach ($this->owners as $owner) {
            $when = $owner->confirmed !== null && $owner->confirmed <= $day ? $owner->confirmed : null;
            foreach ($owner->projects as $project) {
                $seen = $last[$project] ?? null;
                $last[$project] = $when !== null && ($seen === null || $when > $seen) ? $when : $seen;
            }
        }

        $out = [];
        $named = [];
        foreach ($appIds as $id) {
            if (! array_key_exists($id, $last) || isset($named[$id])) {
                continue;
            }
            $named[$id] = true;
            if ($last[$id] === null || $last[$id] < $cutoff) {
                $out[] = ['app' => $id, 'last_confirmed' => $last[$id]];
            }
        }

        return $out;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
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
