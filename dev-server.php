<?php
// Local development router:  php -S localhost:3000 dev-server.php
// Serves /public as the website and sends /api/* to the same PHP front controller Vercel runs.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/') || $path === '/api') { require __DIR__ . '/api/index.php'; return true; }
$file = realpath(__DIR__ . '/public' . $path);
if ($file && str_starts_with($file, realpath(__DIR__ . '/public')) && is_file($file)) return serve($file);
if ($path === '/') return serve(__DIR__ . '/public/index.html');
http_response_code(404); echo 'Not found';
return true;

function serve(string $file): bool {
    if (!is_file($file)) return false;
    $types = ['html'=>'text/html; charset=utf-8','css'=>'text/css','js'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','json'=>'application/json','ico'=>'image/x-icon'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file); return true;
}
