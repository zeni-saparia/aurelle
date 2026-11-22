<?php
declare(strict_types=1);

/** Verifies the Supabase access token on every protected request and loads the caller's profile + role. */
final class Auth
{
    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        $token = Http::bearer();
        if (!$token) return null;
        [$status, $u] = Supabase::auth('GET', 'user', null, $token);   // Supabase validates signature + expiry
        if ($status !== 200 || empty($u['id'])) return null;
        $rows = Supabase::rest('GET', 'profiles', ['id' => 'eq.' . $u['id'], 'select' => '*']);
        if (!$rows) return null;
        return self::$user = $rows[0] + ['token' => $token];
    }

    public static function require(): array
    {
        return self::user() ?? Http::fail('Please sign in to continue.', 401);
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') Http::fail('You do not have permission to do that.', 403);
        return $u;
    }
}
