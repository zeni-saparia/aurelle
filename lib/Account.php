<?php
declare(strict_types=1);

/** The signed-in customer's profile, avatar, password and saved addresses. */
final class Account
{
    public static function me(): never
    {
        $u = Auth::require(); unset($u['token']);
        Http::json($u);
    }

    public static function update(): never
    {
        $u = Auth::require(); $in = Http::body();
        $row = ['full_name' => Validate::text($in, 'full_name', 'Full name', 2, 80), 'phone' => Validate::phone($in, 'phone', false)];
        $res = Supabase::rest('PATCH', 'profiles', ['id' => 'eq.' . $u['id']], $row, 'return=representation');
        Http::json($res[0] ?? []);
    }

    public static function avatar(): never
    {
        $u = Auth::require();
        $url = self::saveImage('avatars', $u['id'] . '/' . bin2hex(random_bytes(6)));
        Supabase::rest('PATCH', 'profiles', ['id' => 'eq.' . $u['id']], ['avatar_url' => $url]);
        Http::json(['avatar_url' => $url]);
    }

    /** Validates an uploaded image by real content type (not the client-supplied one) and stores it. */
    public static function saveImage(string $bucket, string $basePath): string
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) Http::fail('Choose an image to upload.', 422);
        if ($f['size'] > 4 * 1024 * 1024) Http::fail('Images must be 4 MB or smaller.', 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext) Http::fail('Only JPG, PNG or WebP images are allowed.', 422);
        return Supabase::upload($bucket, "$basePath.$ext", file_get_contents($f['tmp_name']), $mime);
    }

    public static function password(): never
    {
        $u = Auth::require(); $in = Http::body();
        $pw = Validate::password($in['password'] ?? '');
        if (($in['confirm_password'] ?? null) !== $pw) Http::fail('Passwords do not match.', 422);
        [$status] = Supabase::auth('PUT', 'user', ['password' => $pw], $u['token']);
        if ($status >= 400) Http::fail('We could not update your password. Please sign in again and retry.', 400);
        Http::json(['message' => 'Password updated.']);
    }

    private static function addressRow(array $in, string $userId): array
    {
        return [
            'user_id' => $userId,
            'label' => Validate::text($in, 'label', 'Label', 1, 30, false) ?? 'Home',
            'full_name' => Validate::text($in, 'full_name', 'Full name', 2, 80),
            'phone' => Validate::phone($in),
            'line1' => Validate::text($in, 'line1', 'Address', 5, 200),
            'city' => Validate::text($in, 'city', 'City', 2, 80),
            'state' => Validate::text($in, 'state', 'State', 2, 80),
            'postal_code' => Validate::text($in, 'postal_code', 'Postal code', 3, 12),
            'country' => Validate::text($in, 'country', 'Country', 2, 60),
            'is_default' => !empty($in['is_default']),
        ];
    }

    public static function addresses(): never
    {
        $u = Auth::require();
        Http::json(['items' => Supabase::rest('GET', 'addresses', ['user_id' => 'eq.' . $u['id'], 'order' => 'is_default.desc,created_at.desc'])]);
    }

    public static function saveAddress(?string $id): never
    {
        $u = Auth::require();
        $row = self::addressRow(Http::body(), $u['id']);
        if ($row['is_default']) Supabase::rest('PATCH', 'addresses', ['user_id' => 'eq.' . $u['id']], ['is_default' => false]);
        $res = $id
            ? Supabase::rest('PATCH', 'addresses', ['id' => 'eq.' . Validate::uuid($id), 'user_id' => 'eq.' . $u['id']], $row, 'return=representation')
            : Supabase::rest('POST', 'addresses', [], $row, 'return=representation');
        if (!$res) Http::fail('Address not found.', 404);
        Http::json($res[0], $id ? 200 : 201);
    }

    public static function deleteAddress(string $id): never
    {
        $u = Auth::require();
        Supabase::rest('DELETE', 'addresses', ['id' => 'eq.' . Validate::uuid($id), 'user_id' => 'eq.' . $u['id']]);
        Http::json(['ok' => true]);
    }
}
