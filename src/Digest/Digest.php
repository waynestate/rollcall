<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Digest;

use Waynestate\Rollcall\Owner\Owner;

/** One owner's digest: plain words about only that owner's apps. Internal: it names apps. */
final class Digest
{
    /**
     * @param  list<string>  $changes  one plain sentence per change since the previous scan
     * @param  list<string>  $standing  one plain line per app: where it stands today
     */
    public function __construct(
        public readonly Owner $owner,
        public readonly string $scanDate,
        public readonly ?string $since,
        public readonly array $changes,
        public readonly array $standing,
    ) {}

    public function hasNews(): bool
    {
        return $this->changes !== [];
    }

    public function subject(): string
    {
        $count = count($this->changes);

        return sprintf('Rollcall: %d %s in your apps (scan of %s)', $count, $count === 1 ? 'change' : 'changes', $this->scanDate);
    }

    public function body(): string
    {
        $apps = count($this->owner->projects);
        $lines = [
            sprintf(
                'Rollcall digest for %s: %d %s, scan of %s%s.',
                $this->owner->contact,
                $apps,
                $apps === 1 ? 'app' : 'apps',
                $this->scanDate,
                $this->since === null ? ', the first roll call' : ' (since ' . $this->since . ')',
            ),
            '',
            'What changed',
        ];
        foreach ($this->changes as $change) {
            $lines[] = '- ' . $change;
        }
        $lines[] = '';
        $lines[] = 'Where each app stands';
        foreach ($this->standing as $line) {
            $lines[] = '- ' . $line;
        }
        $lines[] = '';
        $lines[] = 'Rollcall reports and never enforces. States come from CREED\'s policy on the scan day.';
        $lines[] = 'You get this digest because your entry in the owners file says "digest": true; set it to false to stop.';

        return implode("\n", $lines) . "\n";
    }

    /** @return array{to: string, subject: string, scan_date: string, since: ?string, apps: list<string>, changes: list<string>, standing: list<string>, body: string} */
    public function toArray(): array
    {
        return [
            'to' => $this->owner->contact,
            'subject' => $this->subject(),
            'scan_date' => $this->scanDate,
            'since' => $this->since,
            'apps' => $this->owner->projects,
            'changes' => $this->changes,
            'standing' => $this->standing,
            'body' => $this->body(),
        ];
    }
}
