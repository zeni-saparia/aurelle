<?php
declare(strict_types=1);

/** Cart, wishlist and orders for signed-in customers. Prices and stock ALWAYS come from the database (quote_cart). */
final class Shop
{
    // ───── Cart ─────
    private static function cartId(string $userId): string
    {
        $c = Supabase::rest('GET', 'carts', ['user_id' => 'eq.' . $userId, 'select' => 'id']);
        if ($c) return $c[0]['id'];
        $c = Supabase::rest('POST', 'carts', ['on_conflict' => 'user_id'], ['user_id' => $userId], 'return=representation,resolution=merge-duplicates');
        return $c[0]['id'];
    }

    private static function cartItems(string $userId): array
    {
        return Supabase::rest('GET', 'cart_items', ['cart_id' => 'eq.' . self::cartId($userId), 'select' => 'product_id,quantity']);
    }

    public static function quote(?array $items, ?string $coupon, ?string $userId): array
    {
        $items = array_map(fn($i) => ['product_id' => Validate::uuid((string)($i['product_id'] ?? '')), 'quantity' => max(1, min(99, (int)($i['quantity'] ?? 1)))], $items ?? []);
        return Supabase::rpc('quote_cart', ['p_items' => $items, 'p_coupon' => $coupon ?: null, 'p_user' => $userId]);
    }

    /** Public: price a cart held in the browser (guests). */
    public static function quoteGuest(): never
    {
        $in = Http::body();
        Http::json(self::quote($in['items'] ?? [], $in['coupon'] ?? null, Auth::user()['id'] ?? null));
    }

    public static function cart(): never
    {
        $u = Auth::require();
        Http::json(self::quote(self::cartItems($u['id']), $_GET['coupon'] ?? null, $u['id']));
    }

    /** Set an item's quantity (0 removes it). Quantity is capped at current stock. */
    public static function setCartItem(): never
    {
        $u = Auth::require(); $in = Http::body();
        $pid = Validate::uuid((string)($in['product_id'] ?? ''));
        $qty = (int)Validate::number($in['quantity'] ?? null, 'Quantity', 0, 99);
        $cartId = self::cartId($u['id']);
        $note = null;
        if ($qty === 0) {
            Supabase::rest('DELETE', 'cart_items', ['cart_id' => 'eq.' . $cartId, 'product_id' => 'eq.' . $pid]);
        } else {
            $p = Supabase::rest('GET', 'products', ['id' => 'eq.' . $pid, 'is_published' => 'eq.true', 'select' => 'stock,name']);
            if (!$p) Http::fail('This product is no longer available.', 404);
            if ($p[0]['stock'] < 1) Http::fail($p[0]['name'] . ' is out of stock.', 409);
            if ($qty > $p[0]['stock']) { $qty = $p[0]['stock']; $note = 'Only ' . $qty . ' in stock — quantity adjusted.'; }
            Supabase::rest('POST', 'cart_items', ['on_conflict' => 'cart_id,product_id'],
                ['cart_id' => $cartId, 'product_id' => $pid, 'quantity' => $qty], 'resolution=merge-duplicates');
        }
        Http::json(['ok' => true, 'note' => $note, 'quantity' => $qty]);
    }

    public static function clearCart(): never
    {
        $u = Auth::require();
        Supabase::rest('DELETE', 'cart_items', ['cart_id' => 'eq.' . self::cartId($u['id'])]);
        Http::json(['ok' => true]);
    }

    // ───── Wishlist ─────
    private static function wishlistId(string $userId): string
    {
        $w = Supabase::rest('GET', 'wishlists', ['user_id' => 'eq.' . $userId, 'select' => 'id']);
        if ($w) return $w[0]['id'];
        $w = Supabase::rest('POST', 'wishlists', ['on_conflict' => 'user_id'], ['user_id' => $userId], 'return=representation,resolution=merge-duplicates');
        return $w[0]['id'];
    }

    public static function wishlist(): never
    {
        $u = Auth::require();
        $rows = Supabase::rest('GET', 'wishlist_items', [
            'wishlist_id' => 'eq.' . self::wishlistId($u['id']), 'order' => 'created_at.desc',
            'select' => 'product:products(*,category:categories(name,slug),product_images(url,alt,sort_order))']);
        $items = array_values(array_filter(array_map(fn($r) => $r['product'] && $r['product']['is_published'] ? Catalog::shape($r['product']) : null, $rows)));
        Http::json(['items' => $items, 'ids' => array_column($items, 'id')]);
    }

    public static function addWishlist(string $pid): never
    {
        $u = Auth::require();
        Supabase::rest('POST', 'wishlist_items', ['on_conflict' => 'wishlist_id,product_id'],   // duplicates are ignored
            ['wishlist_id' => self::wishlistId($u['id']), 'product_id' => Validate::uuid($pid)], 'resolution=ignore-duplicates');
        Http::json(['ok' => true], 201);
    }

    public static function removeWishlist(string $pid): never
    {
        $u = Auth::require();
        Supabase::rest('DELETE', 'wishlist_items', ['wishlist_id' => 'eq.' . self::wishlistId($u['id']), 'product_id' => 'eq.' . Validate::uuid($pid)]);
        Http::json(['ok' => true]);
    }

    // ───── Orders ─────
    private const ORDER_SELECT = '*,order_items(id,product_id,product_name,sku,image_url,unit_price,quantity)';

    public static function placeOrder(): never
    {
        $u = Auth::require(); $in = Http::body(); $s = $in['shipping'] ?? [];
        if (!is_array($s)) Http::fail('Shipping details are required.', 422);
        $ship = [
            'name' => Validate::text($s, 'name', 'Full name', 2, 80), 'email' => Validate::email($s['email'] ?? ''),
            'phone' => Validate::phone($s), 'address' => Validate::text($s, 'address', 'Address', 5, 200),
            'city' => Validate::text($s, 'city', 'City', 2, 80), 'state' => Validate::text($s, 'state', 'State', 2, 80),
            'postal_code' => Validate::text($s, 'postal_code', 'Postal code', 3, 12), 'country' => Validate::text($s, 'country', 'Country', 2, 60),
        ];
        $payment = (string)($in['payment_method'] ?? '');
        if (!in_array($payment, ['cod', 'demo_online'], true)) Http::fail('Choose a payment method.', 422);
        $items = self::cartItems($u['id']);
        if (!$items) Http::fail('Your cart is empty.', 409);
        // Prices, stock, coupon and shipping are all recalculated inside the database transaction.
        $order = Supabase::rpc('place_order', ['p_user' => $u['id'], 'p_items' => $items, 'p_coupon' => ($in['coupon'] ?? null) ?: null, 'p_ship' => $ship, 'p_payment' => $payment]);
        Http::json($order, 201);
    }

    public static function orders(): never
    {
        $u = Auth::require();
        Http::json(['items' => Supabase::rest('GET', 'orders', ['user_id' => 'eq.' . $u['id'], 'select' => self::ORDER_SELECT, 'order' => 'created_at.desc'])]);
    }

    public static function order(string $id): never
    {
        $u = Auth::require();
        $o = Supabase::rest('GET', 'orders', ['id' => 'eq.' . Validate::uuid($id), 'user_id' => 'eq.' . $u['id'], 'select' => self::ORDER_SELECT]);
        if (!$o) Http::fail('Order not found.', 404);
        Http::json($o[0]);
    }

    public static function cancelOrder(string $id): never
    {
        $u = Auth::require();
        Supabase::rpc('cancel_order', ['p_order' => Validate::uuid($id), 'p_user' => $u['id'], 'p_as_admin' => false]);
        Http::json(['ok' => true]);
    }
}
