<?php
declare(strict_types=1);

/** Admin-only endpoints. Every route in this class is registered with the 'admin' guard in public/index.php. */
final class Admin
{
    private const FLOW = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];

    public static function stats(): never
    {
        $stats = Supabase::rpc('admin_stats', []);
        $recent = Supabase::rest('GET', 'orders', ['select' => 'id,order_number,ship_name,total,status,created_at', 'order' => 'created_at.desc', 'limit' => 8]);
        Http::json($stats + ['recent_orders' => $recent]);
    }

    private static function page(): array
    {
        $per = min(50, max(1, (int)($_GET['per_page'] ?? 15)));
        $page = max(1, (int)($_GET['page'] ?? 1));
        return ['limit' => $per, 'offset' => ($page - 1) * $per];
    }

    // ───── Products ─────
    public static function products(): never
    {
        $q = ['select' => '*,category:categories(name),product_images(url,alt,sort_order)', 'order' => 'created_at.desc'] + self::page();
        if ($t = Validate::searchTerm((string)($_GET['q'] ?? ''))) $q['or'] = "(name.ilike.*$t*,sku.ilike.*$t*)";
        $rows = Supabase::rest('GET', 'products', $q, null, 'count=exact');
        Http::json(['items' => array_map([Catalog::class, 'shape'], $rows), 'total' => Supabase::$lastCount]);
    }

    private static function productRow(array $in): array
    {
        $name = Validate::text($in, 'name', 'Name', 2, 120);
        $price = Validate::number($in['price'] ?? null, 'Price', 0, 10000000);
        $sale = ($in['sale_price'] ?? '') === '' || $in['sale_price'] === null ? null : Validate::number($in['sale_price'], 'Sale price', 0, 10000000);
        if ($sale !== null && $sale >= $price) Http::fail('Sale price must be lower than the regular price.', 422);
        $colors = $in['colors'] ?? [];
        if (is_string($colors)) $colors = array_filter(array_map('trim', explode(',', $colors)));
        return [
            'name' => $name,
            'slug' => Validate::slug((string)(($in['slug'] ?? '') !== '' ? $in['slug'] : $name)),
            'sku' => Validate::text($in, 'sku', 'SKU', 2, 40),
            'description' => Validate::text($in, 'description', 'Description', 0, 4000, false) ?? '',
            'category_id' => Validate::uuid((string)($in['category_id'] ?? '')),
            'collection' => ($in['collection'] ?? '') !== '' ? Validate::slug((string)$in['collection']) : null,
            'price' => $price, 'sale_price' => $sale,
            'stock' => (int)Validate::number($in['stock'] ?? null, 'Stock', 0, 100000),
            'materials' => Validate::text($in, 'materials', 'Materials', 0, 200, false),
            'dimensions' => Validate::text($in, 'dimensions', 'Dimensions', 0, 200, false),
            'colors' => array_values(array_map(fn($c) => strip_tags((string)$c), $colors)),
            'is_featured' => !empty($in['is_featured']), 'is_bestseller' => !empty($in['is_bestseller']),
            'is_published' => !array_key_exists('is_published', $in) || !empty($in['is_published']),
        ];
    }

    private static function saveImages(string $productId, array $urls, string $alt): void
    {
        Supabase::rest('DELETE', 'product_images', ['product_id' => 'eq.' . $productId]);
        $rows = [];
        foreach (array_values($urls) as $i => $u) $rows[] = ['product_id' => $productId, 'url' => Validate::url((string)$u), 'alt' => $alt, 'sort_order' => $i + 1];
        if ($rows) Supabase::rest('POST', 'product_images', [], $rows);
    }

    public static function saveProduct(?string $id): never
    {
        $in = Http::body(); $row = self::productRow($in);
        $res = $id
            ? Supabase::rest('PATCH', 'products', ['id' => 'eq.' . Validate::uuid($id)], $row, 'return=representation')
            : Supabase::rest('POST', 'products', [], $row, 'return=representation');
        if (!$res) Http::fail('Product not found.', 404);
        if (isset($in['images']) && is_array($in['images'])) self::saveImages($res[0]['id'], $in['images'], $row['name']);
        Http::json($res[0], $id ? 200 : 201);
    }

    public static function deleteProduct(string $id): never
    {
        Supabase::rest('DELETE', 'products', ['id' => 'eq.' . Validate::uuid($id)]);
        Http::json(['ok' => true]);
    }

    public static function upload(): never
    {
        Http::json(['url' => Account::saveImage('product-images', 'admin/' . date('Ym') . '/' . bin2hex(random_bytes(8)))], 201);
    }

    // ───── Categories ─────
    public static function saveCategory(?string $id): never
    {
        $in = Http::body();
        $name = Validate::text($in, 'name', 'Name', 2, 80);
        $row = [
            'name' => $name, 'slug' => Validate::slug((string)(($in['slug'] ?? '') !== '' ? $in['slug'] : $name)),
            'description' => Validate::text($in, 'description', 'Description', 0, 400, false) ?? '',
            'image_url' => Validate::url(isset($in['image_url']) ? (string)$in['image_url'] : null),
            'sort_order' => (int)($in['sort_order'] ?? 0),
        ];
        $res = $id
            ? Supabase::rest('PATCH', 'categories', ['id' => 'eq.' . Validate::uuid($id)], $row, 'return=representation')
            : Supabase::rest('POST', 'categories', [], $row, 'return=representation');
        if (!$res) Http::fail('Category not found.', 404);
        Http::json($res[0], $id ? 200 : 201);
    }

    public static function deleteCategory(string $id): never
    {
        $n = Supabase::rest('GET', 'products', ['category_id' => 'eq.' . Validate::uuid($id), 'select' => 'id', 'limit' => 1]);
        if ($n) Http::fail('Move or delete the products in this category first.', 409);
        Supabase::rest('DELETE', 'categories', ['id' => 'eq.' . $id]);
        Http::json(['ok' => true]);
    }

    // ───── Orders ─────
    public static function orders(): never
    {
        $q = ['select' => '*,order_items(product_name,quantity)', 'order' => 'created_at.desc'] + self::page();
        if (in_array($_GET['status'] ?? '', [...self::FLOW, 'cancelled'], true)) $q['status'] = 'eq.' . $_GET['status'];
        if ($t = Validate::searchTerm((string)($_GET['q'] ?? ''))) $q['or'] = "(order_number.ilike.*$t*,ship_name.ilike.*$t*,ship_email.ilike.*$t*)";
        $rows = Supabase::rest('GET', 'orders', $q, null, 'count=exact');
        Http::json(['items' => $rows, 'total' => Supabase::$lastCount]);
    }

    public static function order(string $id): never
    {
        $o = Supabase::rest('GET', 'orders', ['id' => 'eq.' . Validate::uuid($id), 'select' => '*,order_items(*)']);
        if (!$o) Http::fail('Order not found.', 404);
        Http::json($o[0]);
    }

    public static function setOrderStatus(string $id): never
    {
        $id = Validate::uuid($id);
        $to = (string)(Http::body()['status'] ?? '');
        $o = Supabase::rest('GET', 'orders', ['id' => 'eq.' . $id, 'select' => 'status,payment_method']);
        if (!$o) Http::fail('Order not found.', 404);
        $from = $o[0]['status'];
        if ($to === 'cancelled') {
            Supabase::rpc('cancel_order', ['p_order' => $id, 'p_user' => Auth::user()['id'], 'p_as_admin' => true]);
            Http::json(['ok' => true]);
        }
        $fi = array_search($from, self::FLOW, true); $ti = array_search($to, self::FLOW, true);
        if ($ti === false || $fi === false || $ti <= $fi) Http::fail("An order that is $from cannot move to " . ($to ?: 'that status') . '.', 409);
        $patch = ['status' => $to];
        if ($to === 'delivered' && $o[0]['payment_method'] === 'cod') $patch['payment_status'] = 'paid'; // cash collected on delivery
        Supabase::rest('PATCH', 'orders', ['id' => 'eq.' . $id], $patch);
        Http::json(['ok' => true]);
    }

    // ───── Customers ─────
    public static function customers(): never
    {
        $q = ['select' => 'id,full_name,email,phone,created_at', 'role' => 'eq.customer', 'order' => 'created_at.desc'] + self::page();
        if ($t = Validate::searchTerm((string)($_GET['q'] ?? ''))) $q['or'] = "(full_name.ilike.*$t*,email.ilike.*$t*)";
        $rows = Supabase::rest('GET', 'profiles', $q, null, 'count=exact');
        $total = Supabase::$lastCount;
        $stats = [];
        foreach (Supabase::rest('GET', 'orders', ['select' => 'user_id,total,status', 'limit' => 10000]) as $o) {
            if ($o['status'] === 'cancelled') continue;
            $stats[$o['user_id']]['orders'] = ($stats[$o['user_id']]['orders'] ?? 0) + 1;
            $stats[$o['user_id']]['spent'] = ($stats[$o['user_id']]['spent'] ?? 0) + $o['total'];
        }
        foreach ($rows as &$r) $r += ['orders' => $stats[$r['id']]['orders'] ?? 0, 'spent' => $stats[$r['id']]['spent'] ?? 0];
        Http::json(['items' => $rows, 'total' => $total]);
    }

    public static function customer(string $id): never
    {
        $id = Validate::uuid($id);
        $p = Supabase::rest('GET', 'profiles', ['id' => 'eq.' . $id, 'select' => '*']);
        if (!$p) Http::fail('Customer not found.', 404);
        Http::json(['profile' => $p[0], 'orders' => Supabase::rest('GET', 'orders', ['user_id' => 'eq.' . $id, 'select' => 'id,order_number,status,total,created_at', 'order' => 'created_at.desc'])]);
    }

    // ───── Reviews ─────
    public static function reviews(): never
    {
        Http::json(['items' => Supabase::rest('GET', 'reviews', ['select' => '*,product:products(name,slug),profiles(full_name,email)', 'order' => 'created_at.desc', 'limit' => 100])]);
    }

    public static function deleteReview(string $id): never
    {
        Supabase::rest('DELETE', 'reviews', ['id' => 'eq.' . Validate::uuid($id)]);
        Http::json(['ok' => true]);
    }

    // ───── Coupons ─────
    public static function coupons(): never
    {
        Http::json(['items' => Supabase::rest('GET', 'coupons', ['select' => '*', 'order' => 'created_at.desc'])]);
    }

    public static function saveCoupon(?string $id): never
    {
        $in = Http::body();
        $type = (string)($in['discount_type'] ?? '');
        if (!in_array($type, ['percent', 'fixed'], true)) Http::fail('Choose percent or fixed discount.', 422);
        $val = Validate::number($in['discount_value'] ?? null, 'Discount value', 0.01, $type === 'percent' ? 100 : 10000000);
        $exp = ($in['expires_at'] ?? '') !== '' ? date('c', strtotime((string)$in['expires_at']) ?: Http::fail('Enter a valid expiry date.', 422)) : null;
        $row = [
            'code' => strtoupper(Validate::text($in, 'code', 'Code', 3, 30)), 'discount_type' => $type, 'discount_value' => $val,
            'min_subtotal' => Validate::number($in['min_subtotal'] ?? 0, 'Minimum spend', 0),
            'max_uses' => ($in['max_uses'] ?? '') !== '' ? (int)$in['max_uses'] : null, 'expires_at' => $exp, 'is_active' => !empty($in['is_active']),
        ];
        if (!preg_match('/^[A-Z0-9_-]+$/', $row['code'])) Http::fail('Codes may only contain letters, numbers, - and _.', 422);
        $res = $id
            ? Supabase::rest('PATCH', 'coupons', ['id' => 'eq.' . Validate::uuid($id)], $row, 'return=representation')
            : Supabase::rest('POST', 'coupons', [], $row, 'return=representation');
        if (!$res) Http::fail('Coupon not found.', 404);
        Http::json($res[0], $id ? 200 : 201);
    }

    public static function deleteCoupon(string $id): never
    {
        Supabase::rest('DELETE', 'coupons', ['id' => 'eq.' . Validate::uuid($id)]);
        Http::json(['ok' => true]);
    }

    // ───── Settings ─────
    public static function saveSettings(): never
    {
        $in = Http::body();
        $allowed = ['store', 'announcement', 'contact', 'shipping'];
        foreach ($in as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_array($value)) Http::fail('Unknown settings section.', 422);
            array_walk_recursive($value, function (&$v) { if (is_string($v)) $v = strip_tags($v); });
            Supabase::rest('POST', 'store_settings', ['on_conflict' => 'key'], ['key' => $key, 'value' => $value], 'resolution=merge-duplicates');
        }
        Http::json(['ok' => true]);
    }
}
