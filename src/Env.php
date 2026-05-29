<?php
declare(strict_types=1);

/**
 * Lightweight .env file loader
 * No external dependencies required.
 *
 * Supports:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='value with spaces'
 *   # comments
 *   empty lines
 */
class Env {

    private static bool $loaded = false;

    /**
     * Load a .env file into $_ENV and putenv()
     * Safe to call multiple times — only loads once unless $force=true
     */
    public static function load(string $filePath, bool $force = false): void {
        if(self::$loaded && !$force) return;

        if(!is_file($filePath)) return;

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if($lines === false) return;

        foreach($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if($line === '' || str_starts_with($line, '#')) continue;

            // Must contain =
            if(!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if($key === '') continue;

            // L-6: Validate key names to prevent putenv injection
            if(!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) continue;

            // Strip surrounding quotes
            if(
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Don't overwrite existing environment variables (allows server-level env to take priority)
            if(getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    /**
     * Get an environment variable with an optional default
     */
    public static function get(string $key, string $default = ''): string {
        $val = getenv($key);
        if($val !== false) return (string)$val;
        return $_ENV[$key] ?? $default;
    }

    /**
     * Get as integer
     */
    public static function int(string $key, int $default = 0): int {
        $val = self::get($key, (string)$default);
        return is_numeric($val) ? (int)$val : $default;
    }
}
