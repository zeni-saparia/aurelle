<?php
declare(strict_types=1);

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function fail(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['error' => $message] + $extra, $status);
    }

    /** Parsed JSON request body (empty array when there is none). */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) self::fail('Request body must be valid JSON.');
        return $data;
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }

    public static function ip(): string
    {
        // Behind Vercel the real client IP arrives in x-forwarded-for (first entry).
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') return trim(explode(',', $xff)[0]);
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /** Public site address, used in password-reset emails. FRONTEND_URL wins; otherwise the host serving this request. */
    public static function siteUrl(): string
    {
        $fixed = Env::get('FRONTEND_URL');
        if ($fixed) return rtrim($fixed, '/');
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https';
        return $proto . '://' . explode(',', $host)[0];
    }
}
