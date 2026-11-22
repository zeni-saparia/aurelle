<?php
declare(strict_types=1);

/** Reads configuration from real environment variables (Vercel project settings), falling back to .env for local development. */
final class Env
{
    private static array $file = [];
    private static bool $loaded = false;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $path = __DIR__ . '/../.env';
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if ($line[0] === '#' || !str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    self::$file[trim($k)] = trim($v);
                }
            }
        }
        foreach ([getenv($key), $_ENV[$key] ?? false, $_SERVER[$key] ?? false] as $v) {
            if (is_string($v) && $v !== '') return $v;
        }
        return self::$file[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null) Http::fail("Server is not configured: missing $key.", 500);
        return $v;
    }
}
