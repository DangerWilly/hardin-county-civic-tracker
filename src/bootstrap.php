<?php
declare(strict_types=1);

/**
 * src/bootstrap.php
 *
 * Single source of truth for paths, env vars, error handling, and the
 * helpers we use everywhere. Loaded by every entry point:
 *   - public/index.php   (web requests)
 *   - migrations/migrate.php   (CLI)
 *   - workers/*.php (cron, when applicable)
 */

if (defined('APP_ROOT')) return;          // idempotent — safe to require twice

define('APP_ROOT',      dirname(__DIR__));
define('APP_DATA_DIR',  APP_ROOT . '/data');
define('APP_VIEWS_DIR', APP_ROOT . '/src/views');

/* ---------- .env loader (12-factor, no Composer) ---------- */

$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'");
    }
}

function env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) return $_ENV[$key];
    $val = getenv($key);
    return $val === false ? $default : $val;
}

/* ---------- Error handling ---------- */

$debug = env('APP_DEBUG', '0') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) use ($debug): void {
    http_response_code(500);
    error_log((string) $e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        return;
    }
    if ($debug) {
        echo '<pre style="padding:1rem;background:#fee">'
           . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8')
           . '</pre>';
    } else {
        echo "<h1>500 — Something went wrong</h1>"
           . "<p>We've been notified. Please try again in a moment.</p>";
    }
});

/* ---------- Helpers used everywhere ---------- */

/** Escape on OUTPUT, never on input. Prevents XSS. */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render a view inside the site layout.
 *   view('home', ['jurisdiction' => $jur])
 * The view file's output is captured into $content, then layout.php is loaded.
 * Views may set $title via the data array.
 */
function view(string $name, array $data = []): void
{
    $title = $data['title'] ?? 'The Hardin Civic';
    extract($data, EXTR_SKIP);

    ob_start();
    require APP_VIEWS_DIR . '/' . $name . '.php';
    $content = ob_get_clean();

    require APP_VIEWS_DIR . '/layout.php';
}

/**
 * Hash an IP with a server-side secret. Lets us rate-limit and detect abuse
 * without storing PII. Salt MUST be set in production .env — the default is
 * intentionally insecure so dev failures are loud.
 */
function ip_hash(?string $ip = null): string
{
    $ip   = $ip ?? ($_SERVER['HTTP_CF_CONNECTING_IP']
                 ?? $_SERVER['HTTP_X_REAL_IP']
                 ?? $_SERVER['REMOTE_ADDR']
                 ?? '0.0.0.0');
    $salt = env('IP_HASH_SECRET', 'dev-salt-change-me');
    return hash('sha256', $ip . '|' . $salt);
}

/**
 * Tiny safe markdown -> HTML converter. Supports:
 *   - headers (## up to ####)
 *   - paragraphs
 *   - unordered lists (- or *)
 *   - links [text](https://url)
 *   - bold **x**, italic *x*
 *
 * Everything else is escaped, so untrusted markdown can't inject HTML/JS.
 * If we ever need more, swap in Parsedown — but this covers civic copy.
 */
function md_to_html(string $md): string
{
    $md = str_replace("\r\n", "\n", trim($md));
    $blocks = preg_split('/\n\s*\n/', $md);
    $out = [];

    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') continue;

        // Headers (## Header)
        if (preg_match('/^(#{2,4})\s+(.*)$/s', $b, $m)) {
            $level = strlen($m[1]);
            $out[] = "<h{$level}>" . md_inline($m[2]) . "</h{$level}>";
            continue;
        }

        // Unordered list
        if (preg_match('/^[-*]\s+/', $b)) {
            $items = preg_split('/\n[-*]\s+/', preg_replace('/^[-*]\s+/', '', $b));
            $lis = array_map(fn($i) => '<li>' . md_inline(trim($i)) . '</li>', $items);
            $out[] = '<ul>' . implode('', $lis) . '</ul>';
            continue;
        }

        // Default: paragraph (single newlines become <br>)
        $out[] = '<p>' . md_inline($b) . '</p>';
    }
    return implode("\n", $out);
}

/** Inline markdown: escape first, then re-introduce a small whitelist. */
function md_inline(string $s): string
{
    $s = e($s);
    // Links — only http(s) allowed (no javascript: smuggling)
    $s = preg_replace_callback(
        '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
        fn($m) => '<a href="' . $m[2] . '" rel="noopener noreferrer">' . $m[1] . '</a>',
        $s
    );
    // **bold** then *italic* — order matters
    $s = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*([^*\n]+)\*/',     '<em>$1</em>',         $s);
    return nl2br($s, false);
}
