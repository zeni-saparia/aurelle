<?php
declare(strict_types=1);

/** Register / login / refresh / logout / password reset. Proxies Supabase Auth so the browser only ever talks to this API. */
final class AuthApi
{
    private static function session(array $d): array
    {
        return [
            'access_token' => $d['access_token'],
            'refresh_token' => $d['refresh_token'],
            'expires_in' => $d['expires_in'] ?? 3600
        ];
    }

    public static function register(): never
    {
        RateLimit::hit('register', 8, 900);

        $in = Http::body();
        $name = Validate::text($in, 'full_name', 'Full name', 2, 80);
        $email = Validate::email($in['email'] ?? '');
        $pw = Validate::password($in['password'] ?? '');

        if (($in['confirm_password'] ?? null) !== $pw) {
            Http::fail('Passwords do not match.', 422);
        }

        // Redirect customers to the login page after email verification.
        $redirect = Http::siteUrl() . '/login.html';

        [$status, $d] = Supabase::auth(
            'POST',
            'signup?redirect_to=' . rawurlencode($redirect),
            [
                'email' => $email,
                'password' => $pw,
                'data' => ['full_name' => $name]
            ]
        );

        if ($status >= 400) {
            $m = strtolower((string)($d['msg'] ?? $d['error_description'] ?? $d['message'] ?? ''));

            if (str_contains($m, 'already')) {
                Http::fail('An account with this email already exists. Try signing in.', 409);
            }

            Http::fail('We could not create your account. Please check your details and try again.', 400);
        }

        if (!empty($d['access_token'])) {
            Http::json(['session' => self::session($d)], 201);
        }

        Http::json([
            'message' => 'Account created. Check your email to confirm your address, then sign in.'
        ], 201);
    }

    public static function login(): never
    {
        RateLimit::hit('login', 10, 600);

        $in = Http::body();
        $email = Validate::email($in['email'] ?? '');

        [$status, $d] = Supabase::auth(
            'POST',
            'token?grant_type=password',
            [
                'email' => $email,
                'password' => (string)($in['password'] ?? '')
            ]
        );

        if ($status !== 200) {
            $m = strtolower((string)($d['error_description'] ?? $d['msg'] ?? ''));

            Http::fail(
                str_contains($m, 'confirm')
                    ? 'Please confirm your email address before signing in.'
                    : 'Incorrect email or password.',
                401
            );
        }

        Http::json(['session' => self::session($d)]);
    }

    public static function refresh(): never
    {
        $rt = (string)(Http::body()['refresh_token'] ?? '');

        [$status, $d] = Supabase::auth(
            'POST',
            'token?grant_type=refresh_token',
            ['refresh_token' => $rt]
        );

        if ($status !== 200) {
            Http::fail('Your session has expired. Please sign in again.', 401);
        }

        Http::json(['session' => self::session($d)]);
    }

    public static function logout(): never
    {
        if ($t = Http::bearer()) {
            Supabase::auth('POST', 'logout', null, $t);
        }

        Http::json(['ok' => true]);
    }

    public static function forgot(): never
    {
        RateLimit::hit('forgot', 5, 900);

        $email = Validate::email(Http::body()['email'] ?? '');

        $redirect = Http::siteUrl() . '/reset-password.html';

        Supabase::auth(
            'POST',
            'recover?redirect_to=' . rawurlencode($redirect),
            ['email' => $email]
        );

        Http::json([
            'message' => 'If an account exists for that email, a reset link is on its way.'
        ]);
    }

    /** Called from reset-password.html with the recovery access_token Supabase put in the email link. */
    public static function reset(): never
    {
        $in = Http::body();
        $pw = Validate::password($in['password'] ?? '');

        if (($in['confirm_password'] ?? null) !== $pw) {
            Http::fail('Passwords do not match.', 422);
        }

        $token = (string)($in['access_token'] ?? '');

        [$status] = Supabase::auth(
            'PUT',
            'user',
            ['password' => $pw],
            $token
        );

        if ($status >= 400) {
            Http::fail('This reset link is invalid or has expired. Request a new one.', 400);
        }

        Http::json([
            'message' => 'Your password has been updated. You can now sign in.'
        ]);
    }
}
