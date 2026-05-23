<?php
/**
 * public/index.php — front controller, the ONLY PHP file nginx executes.
 *
 * The "front controller" pattern means every URL on the site (except real
 * files like /assets/css/site.css) is handled by this one script. Benefits:
 *   - All security checks happen in one place
 *   - Pretty URLs without filesystem mirroring
 *   - One bootstrap, one router, one place to add middleware
 *
 * The actual routing lives in src/router.php. This file stays trivial on
 * purpose — easier to audit.
 */

// Dev-server-only: when running `php -S` with this file as a router, PHP
// sends EVERY request through here, including ones for real static files
// like /assets/css/site.css. Returning false tells the built-in server to
// serve the requested file directly instead of routing it.
//
// In production, PHP_SAPI is 'fpm-fcgi' and this branch is skipped entirely
// — nginx handles static files via its own config (see deploy/nginx/civic.conf).
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/../src/router.php';
