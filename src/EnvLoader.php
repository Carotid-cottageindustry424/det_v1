<?php

namespace DetV1;

final class EnvLoader
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = function_exists('getenv') ? getenv($key) : false;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        if (isset(self::$values[$key]) && self::$values[$key] !== '') {
            return self::$values[$key];
        }

        return $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        return (int) round((float) $value);
    }

    public static function getFloat(string $key, float $default): float
    {
        $value = self::get($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        $num = (float) $value;
        return is_finite($num) ? $num : $default;
    }

    public static function getBool(string $key, bool $default): bool
    {
        $value = strtolower(trim((string) self::get($key, $default ? '1' : '0')));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }
}
