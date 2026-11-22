<?php
declare(strict_types=1);

/** Public, read-only storefront endpoints (products, categories, search, reviews, settings, newsletter). */
final class Catalog
{
    private const SELECT = '*,category:categories(name,slug),product_images(url,alt,sort_order)';

    public static function shape(array $p): array
    {
        $imgs = $p['product_images'] ?? [];
        usort($imgs, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        $p['images'] = $imgs;
        $p['image'] = $imgs[0]['url'] ?? null;
        unset($p['product_images']);
        return $p;
    }

    public static function products(): never
    {
        $g = $_GET;
        $q = ['select' => self::SELECT, 'is_published' => 'eq.true'];
        $and = [];

        if (!empty($g['category'])) {
            $c = Supabase::rest('GET', 'categories', ['slug' => 'eq.' . Validate::slug((string)$g['category']), 'select' => 'id']);
            $q['category_id'] = 'eq.' . ($c[0]['id'] ?? '00000000-0000-0000-0000-000000000000');
        }
        if (!empty($g['collection'])) $q['collection'] = 'eq.' . Validate::slug((string)$g['collection']);
        if (isset($g['price_min']) && $g['price_min'] !== '') $and[] = 'effective_price.gte.' . (float)$g['price_min'];
        if (isset($g['price_max']) && $g['price_max'] !== '') $and[] = 'effective_price.lte.' . (float)$g['price_max'];
        if ($and) $q['and'] = '(' . implode(',', $and) . ')';
        if (($g['in_stock'] ?? '') === '1') $q['stock'] = 'gt.0';
        if (($g['bestseller'] ?? '') === '1') $q['is_bestseller'] = 'eq.true';
        if (($g['featured'] ?? '') === '1') $q['is_featured'] = 'eq.true';
        if (($g['new'] ?? '') === '1') $q['created_at'] = 'gte.' . gmdate('c', time() - 45 * 86400);

        $term = Validate::searchTerm((string)($g['q'] ?? ''));
        if ($term !== '') {
            $conds = ["name.ilike.*$term*", "description.ilike.*$term*"];
            $cats = Supabase::rest('GET', 'categories', ['name' => "ilike.*$term*", 'select' => 'id']);
            if ($cats) $conds[] = 'category_id.in.(' . implode(',', array_column($cats, 'id')) . ')';
            $q['or'] = '(' . implode(',', $conds) . ')';
        }

        $q['order'] = match ($g['sort'] ?? 'newest') {
            'price_asc' => 'effective_price.asc',
            'price_desc' => 'effective_price.desc',
            'popular' => 'sold_count.desc',
            'rating' => 'rating_avg.desc,rating_count.desc',
            default => 'created_at.desc',
        };
        $per = min(48, max(1, (int)($g['per_page'] ?? 12)));
        $page = max(1, (int)($g['page'] ?? 1));
        $q['limit'] = $per;
        $q['offset'] = ($page - 1) * $per;

        $rows = Supabase::rest('GET', 'products', $q, null, 'count=exact');
        Http::json([
            'items' => array_map([self::class, 'shape'], $rows ?? []),
            'total' => Supabase::$lastCount, 'page' => $page, 'per_page' => $per,
        ]);
    }

    public static function product(string $slug): never
    {
        $rows = Supabase::rest('GET', 'products', ['slug' => 'eq.' . $slug, 'is_published' => 'eq.true', 'select' => self::SELECT]);
        if (!$rows) Http::fail('Product not found.', 404);
        $p = self::shape($rows[0]);
        $related = Supabase::rest('GET', 'products', [
            'category_id' => 'eq.' . $p['category_id'], 'id' => 'neq.' . $p['id'], 'is_published' => 'eq.true',
            'select' => self::SELECT, 'limit' => 4, 'order' => 'sold_count.desc']);
        Http::json(['product' => $p, 'related' => array_map([self::class, 'shape'], $related ?? [])]);
    }

    public static function categories(): never
    {
        $cats = Supabase::rest('GET', 'categories', ['select' => '*', 'order' => 'sort_order.asc']);
        $counts = [];
        foreach (Supabase::rest('GET', 'products', ['select' => 'category_id', 'is_published' => 'eq.true', 'limit' => 5000]) as $r) {
            $counts[$r['category_id']] = ($counts[$r['category_id']] ?? 0) + 1;
        }
        foreach ($cats as &$c) $c['product_count'] = $counts[$c['id']] ?? 0;
        Http::json(['items' => $cats]);
    }

    public static function reviews(string $productId): never
    {
        Http::json(['items' => Supabase::rest('GET', 'reviews', [
            'product_id' => 'eq.' . Validate::uuid($productId), 'select' => 'id,rating,title,body,created_at,profiles(full_name)',
            'order' => 'created_at.desc', 'limit' => 50])]);
    }

    public static function addReview(string $productId): never
    {
        $u = Auth::require();
        $in = Http::body();
        $rating = (int)Validate::number($in['rating'] ?? null, 'Rating', 1, 5);
        $row = [
            'product_id' => Validate::uuid($productId), 'user_id' => $u['id'], 'rating' => $rating,
            'title' => Validate::text($in, 'title', 'Title', 0, 80, false) ?? '',
            'body' => Validate::text($in, 'body', 'Review', 0, 1000, false) ?? '',
        ];
        Supabase::rest('POST', 'reviews', ['on_conflict' => 'product_id,user_id'], $row, 'resolution=merge-duplicates');
        Http::json(['ok' => true], 201);
    }

    public static function settings(): never
    {
        $out = [];
        foreach (Supabase::rest('GET', 'store_settings', ['select' => 'key,value']) as $r) $out[$r['key']] = $r['value'];
        Http::json($out);
    }

    public static function subscribe(): never
    {
        RateLimit::hit('newsletter', 10, 600);
        $email = Validate::email(Http::body()['email'] ?? '');
        Supabase::rest('POST', 'newsletter_subscribers', ['on_conflict' => 'email'], ['email' => $email], 'resolution=ignore-duplicates');
        Http::json(['ok' => true, 'message' => 'Thank you for subscribing.'], 201);
    }
}
