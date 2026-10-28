<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Model;

/**
 * One override as PROCTOR reported it. ROLLCALL only shows it, with its reason
 * and expiry; an expired one stays on the list as expired, never removed.
 */
final class OverrideNote
{
    public function __construct(
        public readonly string $check,
        public readonly string $reason,
        public readonly ?string $expires,
        public readonly bool $expired,
    ) {}

    /** @return array{check: string, reason: string, expires: ?string, expired: bool} */
    public function toArray(): array
    {
        return ['check' => $this->check, 'reason' => $this->reason, 'expires' => $this->expires, 'expired' => $this->expired];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $expires = $data['expires'] ?? null;

        return new self(
            is_string($data['check'] ?? null) ? $data['check'] : '',
            is_string($data['reason'] ?? null) ? $data['reason'] : '',
            is_string($expires) ? $expires : null,
            ($data['expired'] ?? false) === true,
        );
    }
}
