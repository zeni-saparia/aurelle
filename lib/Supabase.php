<?php
declare(strict_types=1);

/**
 * Thin Supabase client.
 *  - rest()/rpc()/upload(): use the SERVICE ROLE key (server only). Authorization is enforced by Auth.php before these run.
 *  - auth(): talks to Supabase Auth (GoTrue) with the public anon key.
 */
final class Supabase
{
    public static int $lastCount = 0;

    private static function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $respHeaders[strtolower(trim($k))] = trim($v); }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        if ($out === false) { curl_close($ch); Http::fail('Could not reach the database service.', 502); }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $out === '' ? null : json_decode($out, true);
        if (isset($respHeaders['content-range']) && preg_match('#/(\d+)$#', $respHeaders['content-range'], $m)) self::$lastCount = (int)$m[1];
        return [$status, $data];
    }

    /** PostgREST call. $prefer e.g. "return=representation", "resolution=merge-duplicates", "count=exact". */
    public static function rest(string $method, string $table, array $query = [], mixed $body = null, string $prefer = ''): mixed
    {
        $key = Env::require('SUPABASE_SERVICE_ROLE_KEY');
        $url = Env::require('SUPABASE_URL') . '/rest/v1/' . $table . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $headers = ["apikey: $key", "Authorization: Bearer $key", 'Content-Type: application/json'];
        if ($prefer !== '') $headers[] = "Prefer: $prefer";
        [$status, $data] = self::send($method, $url, $headers, $body === null ? null : json_encode($body));
        if ($status >= 400) self::dbError($status, $data);
        return $data ?? [];
    }

    public static function rpc(string $fn, array $args): mixed
    {
        return self::rest('POST', 'rpc/' . $fn, [], $args ?: new stdClass());
    }

    /** Auth endpoints (signup, token, recover, user, logout). Returns [status, data] without throwing. */
    public static function auth(string $method, string $path, ?array $body = null, ?string $userToken = null): array
    {
        $anon = Env::require('SUPABASE_ANON_KEY');
        $headers = ["apikey: $anon", 'Authorization: Bearer ' . ($userToken ?? $anon), 'Content-Type: application/json'];
        return self::send($method, Env::require('SUPABASE_URL') . '/auth/v1/' . $path, $headers, $body === null ? null : json_encode($body));
    }

    /** Upload a validated image to a public Storage bucket and return its public URL. */
    public static function upload(string $bucket, string $path, string $bytes, string $mime): string
    {
        $key = Env::require('SUPABASE_SERVICE_ROLE_KEY');
        $base = Env::require('SUPABASE_URL');
        [$status] = self::send('POST', "$base/storage/v1/object/$bucket/$path",
            ["apikey: $key", "Authorization: Bearer $key", "Content-Type: $mime", 'x-upsert: true'], $bytes);
        if ($status >= 400) Http::fail('Image upload failed. Check that the storage buckets exist.', 502);
        return "$base/storage/v1/object/public/$bucket/$path";
    }

    private static function dbError(int $status, mixed $data): never
    {
        $msg = is_array($data) ? (string)($data['message'] ?? 'Database error') : 'Database error';
        // Errors raised by our SQL functions look like "CODE: human message"
        if (preg_match('/^(CART_INVALID|COUPON_INVALID|NOT_CANCELLABLE|ORDER_NOT_FOUND)(?::\s*(.*))?$/s', $msg, $m)) {
            Http::fail($m[2] ?? 'Request could not be completed.', $m[1] === 'ORDER_NOT_FOUND' ? 404 : 409, ['code' => $m[1]]);
        }
        if (($data['code'] ?? '') === '23505') Http::fail('That value already exists (it must be unique).', 409);
        if (($data['code'] ?? '') === '23503') Http::fail('This item is still referenced elsewhere and cannot be removed.', 409);
        if (($data['code'] ?? '') === '23514') Http::fail('A value is outside the allowed range.', 422);
        error_log('Supabase error: ' . json_encode($data));
        Http::fail(Env::get('APP_DEBUG') === 'true' ? $msg : 'Something went wrong. Please try again.', $status >= 500 ? 502 : 400);
    }
}
