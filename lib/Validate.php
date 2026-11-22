<?php
declare(strict_types=1);

/** Small server-side validation helpers. Every user-supplied value passes through these. */
final class Validate
{
    public static function email(mixed $v): string
    {
        $v = strtolower(trim((string)$v));
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || strlen($v) > 254) Http::fail('Enter a valid email address.', 422);
        return $v;
    }

    public static function text(array $in, string $key, string $label, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        $v = trim((string)($in[$key] ?? ''));
        if ($v === '') {
            if ($required) Http::fail("$label is required.", 422);
            return null;
        }
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) Http::fail("$label must be between $min and $max characters.", 422);
        return strip_tags($v); // output is also escaped in the frontend; this is defence in depth
    }

    public static function password(mixed $v): string
    {
        $v = (string)$v;
        if (strlen($v) < 8 || !preg_match('/[A-Za-z]/', $v) || !preg_match('/\d/', $v)) {
            Http::fail('Password must be at least 8 characters and include a letter and a number.', 422);
        }
        return $v;
    }

    public static function phone(array $in, string $key = 'phone', bool $required = true): ?string
    {
        $v = trim((string)($in[$key] ?? ''));
        if ($v === '' && !$required) return null;
        if (!preg_match('/^\+?[0-9\s\-]{7,15}$/', $v)) Http::fail('Enter a valid phone number.', 422);
        return $v;
    }

    public static function uuid(string $v): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) Http::fail('Invalid identifier.', 422);
        return strtolower($v);
    }

    public static function number(mixed $v, string $label, float $min = 0, ?float $max = null): float
    {
        if (!is_numeric($v)) Http::fail("$label must be a number.", 422);
        $n = (float)$v;
        if ($n < $min || ($max !== null && $n > $max)) Http::fail("$label is out of range.", 422);
        return $n;
    }

    public static function slug(string $v): string
    {
        $v = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-'));
        return $v === '' ? 'item' : substr($v, 0, 80);
    }

    /** Strip characters that have special meaning inside PostgREST filter expressions. */
    public static function searchTerm(string $v): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', mb_substr($v, 0, 60)));
    }

    /** URL must be http(s) or a site-relative path — blocks javascript: and data: URLs stored in image fields. */
    public static function url(?string $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (preg_match('#^/(?!/)[A-Za-z0-9._~/%-]+$#', $v)) return $v;   // image hosted in this site, e.g. /assets/products/vase.svg
        if (!preg_match('#^https?://#i', $v) || !filter_var($v, FILTER_VALIDATE_URL)) Http::fail('Image URLs must start with http://, https:// or /.', 422);
        return $v;
    }
}
