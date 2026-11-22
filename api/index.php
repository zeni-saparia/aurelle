<?php
declare(strict_types=1);

// AURELLE API front controller. On Vercel it runs as a PHP serverless function (vercel-php runtime) from the same project as the site.
// Locally:  php -S localhost:3000 dev-server.php   (see README)
spl_autoload_register(fn(string $c) => is_file($f = __DIR__ . '/../lib/' . $c . '.php') ? require $f : null);

set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    Http::fail(Env::get('APP_DEBUG') === 'true' ? $e->getMessage() : 'Unexpected server error.', 500);
});

// Same-origin: the site and the API are served from one Vercel project, so no CORS headers are needed.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$path = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path = preg_replace('#^/api(/index\.php)?#', '', $path) ?: '/';

$id = '([0-9a-fA-F-]{36})';
$slug = '([a-z0-9-]+)';
// [method, regex, guard (public|user|admin), handler]
$routes = [
    ['GET',  '#^/health$#', 'public', fn() => Http::json(['status' => 'ok'])],
    ['GET',  '#^/products$#', 'public', fn() => Catalog::products()],
    ['GET',  "#^/products/$slug$#", 'public', fn($m) => Catalog::product($m[1])],
    ['GET',  "#^/products/$id/reviews$#", 'public', fn($m) => Catalog::reviews($m[1])],
    ['POST', "#^/products/$id/reviews$#", 'user', fn($m) => Catalog::addReview($m[1])],
    ['GET',  '#^/categories$#', 'public', fn() => Catalog::categories()],
    ['GET',  '#^/settings$#', 'public', fn() => Catalog::settings()],
    ['POST', '#^/newsletter$#', 'public', fn() => Catalog::subscribe()],

    ['POST', '#^/auth/register$#', 'public', fn() => AuthApi::register()],
    ['POST', '#^/auth/login$#', 'public', fn() => AuthApi::login()],
    ['POST', '#^/auth/refresh$#', 'public', fn() => AuthApi::refresh()],
    ['POST', '#^/auth/logout$#', 'public', fn() => AuthApi::logout()],
    ['POST', '#^/auth/forgot$#', 'public', fn() => AuthApi::forgot()],
    ['POST', '#^/auth/reset$#', 'public', fn() => AuthApi::reset()],

    ['GET',  '#^/me$#', 'user', fn() => Account::me()],
    ['PUT',  '#^/me$#', 'user', fn() => Account::update()],
    ['POST', '#^/me/avatar$#', 'user', fn() => Account::avatar()],
    ['POST', '#^/me/password$#', 'user', fn() => Account::password()],
    ['GET',  '#^/addresses$#', 'user', fn() => Account::addresses()],
    ['POST', '#^/addresses$#', 'user', fn() => Account::saveAddress(null)],
    ['PUT',  "#^/addresses/$id$#", 'user', fn($m) => Account::saveAddress($m[1])],
    ['DELETE', "#^/addresses/$id$#", 'user', fn($m) => Account::deleteAddress($m[1])],

    ['POST', '#^/cart/quote$#', 'public', fn() => Shop::quoteGuest()],
    ['GET',  '#^/cart$#', 'user', fn() => Shop::cart()],
    ['PUT',  '#^/cart/items$#', 'user', fn() => Shop::setCartItem()],
    ['DELETE', '#^/cart$#', 'user', fn() => Shop::clearCart()],
    ['GET',  '#^/wishlist$#', 'user', fn() => Shop::wishlist()],
    ['POST', "#^/wishlist/$id$#", 'user', fn($m) => Shop::addWishlist($m[1])],
    ['DELETE', "#^/wishlist/$id$#", 'user', fn($m) => Shop::removeWishlist($m[1])],
    ['POST', '#^/orders$#', 'user', fn() => Shop::placeOrder()],
    ['GET',  '#^/orders$#', 'user', fn() => Shop::orders()],
    ['GET',  "#^/orders/$id$#", 'user', fn($m) => Shop::order($m[1])],
    ['POST', "#^/orders/$id/cancel$#", 'user', fn($m) => Shop::cancelOrder($m[1])],

    ['GET',  '#^/admin/stats$#', 'admin', fn() => Admin::stats()],
    ['GET',  '#^/admin/products$#', 'admin', fn() => Admin::products()],
    ['POST', '#^/admin/products$#', 'admin', fn() => Admin::saveProduct(null)],
    ['PUT',  "#^/admin/products/$id$#", 'admin', fn($m) => Admin::saveProduct($m[1])],
    ['DELETE', "#^/admin/products/$id$#", 'admin', fn($m) => Admin::deleteProduct($m[1])],
    ['POST', '#^/admin/upload$#', 'admin', fn() => Admin::upload()],
    ['POST', '#^/admin/categories$#', 'admin', fn() => Admin::saveCategory(null)],
    ['PUT',  "#^/admin/categories/$id$#", 'admin', fn($m) => Admin::saveCategory($m[1])],
    ['DELETE', "#^/admin/categories/$id$#", 'admin', fn($m) => Admin::deleteCategory($m[1])],
    ['GET',  '#^/admin/orders$#', 'admin', fn() => Admin::orders()],
    ['GET',  "#^/admin/orders/$id$#", 'admin', fn($m) => Admin::order($m[1])],
    ['PUT',  "#^/admin/orders/$id/status$#", 'admin', fn($m) => Admin::setOrderStatus($m[1])],
    ['GET',  '#^/admin/customers$#', 'admin', fn() => Admin::customers()],
    ['GET',  "#^/admin/customers/$id$#", 'admin', fn($m) => Admin::customer($m[1])],
    ['GET',  '#^/admin/reviews$#', 'admin', fn() => Admin::reviews()],
    ['DELETE', "#^/admin/reviews/$id$#", 'admin', fn($m) => Admin::deleteReview($m[1])],
    ['GET',  '#^/admin/coupons$#', 'admin', fn() => Admin::coupons()],
    ['POST', '#^/admin/coupons$#', 'admin', fn() => Admin::saveCoupon(null)],
    ['PUT',  "#^/admin/coupons/$id$#", 'admin', fn($m) => Admin::saveCoupon($m[1])],
    ['DELETE', "#^/admin/coupons/$id$#", 'admin', fn($m) => Admin::deleteCoupon($m[1])],
    ['PUT',  '#^/admin/settings$#', 'admin', fn() => Admin::saveSettings()],
];

foreach ($routes as [$m, $re, $guard, $handler]) {
    if ($m === $method && preg_match($re, $path, $matches)) {
        if ($guard === 'admin') Auth::requireAdmin(); elseif ($guard === 'user') Auth::require();
        $handler($matches);
    }
}
Http::fail('Not found.', 404);
