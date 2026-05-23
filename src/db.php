<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Returns a singleton PDO connection to the SQLite database.
 *
 * Why these PRAGMAs:
 *   - foreign_keys ON: SQLite ignores FK constraints unless you ask. Always ask.
 *   - busy_timeout 5000: when cron is writing and a web request wants to write,
 *     PDO normally throws "database is locked" instantly. With WAL + a 5s
 *     timeout, conflicts are silent and rare.
 *   - EMULATE_PREPARES false: real prepared statements, no string interpolation
 *     fallback. Matters for security AND for type-correct binding.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(APP_DATA_DIR)) {
        mkdir(APP_DATA_DIR, 0755, true);
    }
    $path = APP_DATA_DIR . '/civic.sqlite';

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    return $pdo;
}
