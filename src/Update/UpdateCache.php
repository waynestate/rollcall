<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Update;

/**
 * The once-a-day cache: one JSON file in the user cache dir holding the last
 * check time and the newest stable release it found.
 */
final class UpdateCache
{
    public function __construct(private readonly string $dir) {}

    /**
     * The default location: $XDG_CACHE_HOME/waynestate/rollcall, else
     * %LOCALAPPDATA%\waynestate\rollcall on Windows, else
     * ~/.cache/waynestate/rollcall. Null when no home can be found at all.
     *
     * @param  array<string, string|false|null>  $env
     */
    public static function fromEnv(array $env): ?self
    {
        $xdg = self::string($env, 'XDG_CACHE_HOME');
        if ($xdg !== null) {
            return new self($xdg . '/waynestate/rollcall');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $local = self::string($env, 'LOCALAPPDATA');
            if ($local !== null) {
                return new self($local . '/waynestate/rollcall');
            }
        }

        $home = self::string($env, 'HOME') ?? self::string($env, 'USERPROFILE');
        if ($home === null) {
            return null;
        }

        return new self($home . '/.cache/waynestate/rollcall');
    }

    public function path(): string
    {
        return $this->dir . '/update-check.json';
    }

    /**
     * The cached result, or null when there is none or the file is unreadable
     * or malformed.
     *
     * @return array{checked_at: int, latest: string|null}|null
     */
    public function read(): ?array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return null;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $raw = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        if (! is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        $checkedAt = $data['checked_at'] ?? null;
        $latest = $data['latest'] ?? null;
        if (! is_int($checkedAt) || ($latest !== null && ! is_string($latest))) {
            return null;
        }

        return ['checked_at' => $checkedAt, 'latest' => $latest];
    }

    /**
     * Whether the cache dir exists and is writable, or could be created where
     * a writable parent stands. The notice stays silent when this is false.
     */
    public function usable(): bool
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }

        $parent = dirname($this->dir);
        while ($parent !== '' && $parent !== dirname($parent) && ! is_dir($parent)) {
            $parent = dirname($parent);
        }

        return is_dir($parent) && is_writable($parent);
    }

    /** Writes the result of one check; false when the dir cannot be made or written. */
    public function write(int $checkedAt, ?string $latest): bool
    {
        if (! is_dir($this->dir)) {
            set_error_handler(static fn(): bool => true);
            try {
                $made = mkdir($this->dir, 0o775, true);
            } finally {
                restore_error_handler();
            }
            if (! $made && ! is_dir($this->dir)) {
                return false;
            }
        }

        $payload = json_encode(['checked_at' => $checkedAt, 'latest' => $latest], JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return false;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $written = file_put_contents($this->path(), $payload, LOCK_EX);
        } finally {
            restore_error_handler();
        }

        return $written !== false;
    }

    /**
     * @param  array<string, string|false|null>  $env
     */
    private static function string(array $env, string $key): ?string
    {
        $value = $env[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
