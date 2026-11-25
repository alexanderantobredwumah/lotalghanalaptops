<?php
/**
 * LOTAL GHANA — document-root entry point.
 *
 * On Vercel the functions live in /api and every public path is rewritten to
 * api/index.php (see vercel.json). This file only exists so the same folder can
 * be dropped onto Apache / LiteSpeed / PHP-FPM hosting and behave identically.
 */

declare(strict_types=1);

require __DIR__ . '/api/index.php';
