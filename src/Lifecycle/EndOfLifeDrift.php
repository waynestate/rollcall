<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Lifecycle;

use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\RollcallException;

/**
 * Compares CREED's policy.json with a saved endoflife.date v1 product page
 * (for example https://endoflife.date/api/v1/products/php/ saved to a file).
 * endoflife.date is a drift check only: the policy stays the source of truth,
 * and a difference is a flag for a person to look at, never a state change.
 */
final class EndOfLifeDrift
{
    public function __construct(private readonly Policy $policy) {}

    /**
     * @return list<array{version: string, field: string, policy: ?string, feed: ?string}>
     */
    public function compare(string $component, string $feedJson): array
    {
        try {
            $data = json_decode($feedJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RollcallException('The endoflife.date file is not valid JSON.');
        }
        $releases = is_array($data) && is_array($data['result'] ?? null) && is_array($data['result']['releases'] ?? null)
            ? $data['result']['releases']
            : null;
        if ($releases === null) {
            throw new RollcallException('The endoflife.date file has no result.releases (expected the v1 product shape).');
        }

        $drift = [];
        foreach ($releases as $release) {
            if (! is_array($release) || ! is_string($release['name'] ?? null)) {
                continue;
            }
            $version = $release['name'];
            if (! in_array($version, $this->policy->versions($component), true)) {
                continue;
            }
            $pairs = [
                'security_until' => [$this->policy->securityUntil($component, $version)?->format('Y-m-d'), $release['eolFrom'] ?? null],
                'active_until' => [$this->policy->activeUntil($component, $version)?->format('Y-m-d'), $release['eoasFrom'] ?? null],
            ];
            foreach ($pairs as $field => [$policyDate, $feedDate]) {
                $feedDate = is_string($feedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $feedDate) === 1 ? $feedDate : null;
                // Only a date both sides state can disagree; a missing date is not drift.
                if ($policyDate !== null && $feedDate !== null && $policyDate !== $feedDate) {
                    $drift[] = ['version' => $version, 'field' => $field, 'policy' => $policyDate, 'feed' => $feedDate];
                }
            }
        }

        return $drift;
    }
}
