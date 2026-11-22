<?php
declare(strict_types=1);

/** Minimal per-IP rate limiter for auth endpoints (file based; use Redis/your host's WAF at larger scale). */
final class RateLimit
{
    public static function hit(string $bucket, int $max, int $windowSeconds): void
    {
        $file = sys_get_temp_dir() . '/aurelle_rl_' . md5($bucket . Http::ip());
        $now = time();
        $hits = is_readable($file) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($file))), fn($t) => $t > $now - $windowSeconds) : [];
        if (count($hits) >= $max) Http::fail('Too many attempts. Please wait a few minutes and try again.', 429);
        $hits[] = $now;
        file_put_contents($file, implode(',', $hits), LOCK_EX);
    }
}
