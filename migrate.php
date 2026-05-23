<?php
declare(strict_types=1);

/**
 * migrations/migrate.php
 *
 * Tiny SQLite migration runner. Run with:
 *
 *     php migrations/migrate.php           # apply all pending
 *     php migrations/migrate.php --status  # list applied + pending
 *
 * - Looks at every *.sql file in this directory (sorted)
 * - Tracks applied filenames in `_migrations`
 * - Each migration runs inside a transaction; partial failure rolls back
 * - Idempotent: running again is a no-op
 *
 * Why a migration runner at all? Because at some point in month 2 you'll
 * change a schema, and you don't want to remember which laptop has run
 * which CREATE TABLE. The DB itself remembers.
 */

require_once __DIR__ . '/../src/db.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
    filename   TEXT PRIMARY KEY,
    applied_at INTEGER NOT NULL DEFAULT (unixepoch())
)");

$applied = $pdo->query("SELECT filename FROM _migrations")
               ->fetchAll(PDO::FETCH_COLUMN);
$applied_set = array_flip($applied);

$files = glob(__DIR__ . '/*.sql') ?: [];
sort($files);

if (in_array('--status', $argv ?? [], true)) {
    fwrite(STDOUT, "Migrations:\n");
    foreach ($files as $f) {
        $name = basename($f);
        $mark = isset($applied_set[$name]) ? '[x]' : '[ ]';
        fwrite(STDOUT, "  $mark $name\n");
    }
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied_set[$name])) continue;

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read $name\n");
        exit(1);
    }

    fwrite(STDOUT, "→ Applying $name ... ");
    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)");
        $stmt->execute([$name]);
        $pdo->commit();
        fwrite(STDOUT, "ok\n");
        $ran++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, $ran === 0
    ? "Database up to date. Nothing to apply.\n"
    : "Applied $ran migration(s).\n");
