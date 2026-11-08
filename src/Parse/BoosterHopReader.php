<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Parse;

use Waynestate\Rollcall\RollcallException;

/**
 * Reads the JSON Booster prints as `booster-hop.json` at the app's repository
 * root after a real upgrade run. Anything that is not that shape is rejected,
 * because the record is outside input and may be hostile or simply stale.
 */
final class BoosterHopReader
{
    private const MAX_HOPS = 50;

    /**
     * @return array{result: string, version: ?string, at: string}
     */
    public function read(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RollcallException('Booster hop record is not valid JSON.');
        }
        if (! is_array($data) || ($data['tool'] ?? null) !== 'booster') {
            throw new RollcallException('Booster hop record must have "tool": "booster".');
        }
        $result = $data['result'] ?? null;
        if ($result !== 'pass' && $result !== 'fail') {
            throw new RollcallException('Booster hop record "result" must be "pass" or "fail".');
        }
        foreach (['started_at', 'finished_at'] as $key) {
            $value = $data[$key] ?? null;
            if (! is_string($value) || $value === '' || strlen($value) > 40) {
                throw new RollcallException('Booster hop record "' . $key . '" must be a non-empty string of at most 40 characters.');
            }
        }
        $version = is_string($data['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+([-+.\w]*)$/', $data['version']) === 1
            ? $data['version']
            : null;
        if (array_key_exists('hops', $data) && ! is_array($data['hops'])) {
            throw new RollcallException('Booster hop record "hops" must be a list.');
        }
        if (is_array($data['hops'] ?? null) && count($data['hops']) > self::MAX_HOPS) {
            throw new RollcallException('Booster hop record "hops" must hold at most 50 entries.');
        }

        return ['result' => $result, 'version' => $version, 'at' => $data['finished_at']];
    }
}
