<?php
/**
 * LOTAL GHANA — operations console entry point.
 * Not linked from the storefront. On Vercel the rewrite in vercel.json handles
 * /admin.php and /admin.html; this file covers Apache-style hosting.
 */

declare(strict_types=1);

$_GET['r'] = '/admin';
require __DIR__ . '/api/index.php';
