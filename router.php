<?php
/**
 * Dev router: php -S localhost:8080 router.php
 * Real files are served as-is (CSS, JS, images); everything else goes to the
 * front controller, mirroring the rewrites in vercel.json.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        require $file;
        return true;
    }
    return false; // let the built-in server serve the static asset
}

require __DIR__ . '/api/index.php';
return true;
