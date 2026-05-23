<?php
declare(strict_types=1);

/**
 * src/ratelimit.php — per-IP per-action sliding-window rate limiting.
 *
 * USAGE
 *
 *   require_once __DIR__ . '/ratelimit.php';
 *
 *   // Hard guard: aborts with 429 if over limit. Sends Retry-After header.
 *   rate_limit_guard('submit_correction', max: 5, window: 3600);
 *
 *   // Soft check: lets you handle the response yourself.
 *   $r = rate_limit('ask', max: 10, window: 60);
 *   if (!$r['allowed']) { ... }
 *
 * BUCKETS
 *   A bucket is "action:ip_hash". Each (action, IP) combo has an independent
 *   counter. So '/submit-correction' being throttled doesn't slow '/ask'.
 *
 *   You can also rate-limit globally by passing a constant:
 *     rate_limit('ask_global', max: 1000, window: 60, ip: 'global')
 *   This is useful for budget caps on paid APIs (xAI tokens, Stripe calls).
 *
 * WINDOW SEMANTICS
 *   Sliding window — counts events in the last $window seconds, not a fixed
 *   wall-clock minute. So a user can't burst 10 requests at 11:59:59 and
 *   another 10 at 12:00:00 the way they could with fixed-minute buckets.
 *
 * CLEANUP
 *   1% of requests trigger a delete-where-older-than-24h sweep. Cheap, and
 *   we don't need a separate cron job. If the table ever grows past a few
 *   hundred MB (unlikely), add a daily VACUUM.
 */

require_once __DIR__ . '/db.php';

/**
 * Check + record an action against the rate limit.
 *
 * @param string $action   logical name, e.g. "submit_correction" or "ask"
 * @param int    $max      max events per window
 * @param int    $window   window length in seconds
 * @param ?string $ip      override IP (null = current request)
 *
 * @return array{allowed:bool,remaining:int,retry_after:int,limit:int}
 */
function rate_limit(string $action, int $max, int $window, ?string $ip = null): array
{
    $bucket = $action . ':' . ip_hash($ip);
    $now    = time();
    $since  = $now - $window;
    $pdo    = db();

    // Opportunistic cleanup — 1% of requests. Avoids needing a cron job.
    // We delete anything older than 24h; the longest window we expect.
    if (random_int(1, 100) === 1) {
        $pdo->prepare("DELETE FROM rate_limit_events WHERE created_at < ?")
            ->execute([$now - 86400]);
    }

    // How many events in the window?
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limit_events WHERE bucket = ? AND created_at > ?"
    );
    $stmt->execute([$bucket, $since]);
    $count = (int) $stmt->fetchColumn();

    if ($count >= $max) {
        // Find when the oldest event in the window will fall out
        $stmt2 = $pdo->prepare(
            "SELECT MIN(created_at) FROM rate_limit_events
              WHERE bucket = ? AND created_at > ?"
        );
        $stmt2->execute([$bucket, $since]);
        $oldest = (int) $stmt2->fetchColumn();

        return [
            'allowed'     => false,
            'remaining'   => 0,
            'retry_after' => max(1, ($oldest + $window) - $now),
            'limit'       => $max,
        ];
    }

    // Allowed — record the event
    $pdo->prepare("INSERT INTO rate_limit_events (bucket) VALUES (?)")
        ->execute([$bucket]);

    return [
        'allowed'     => true,
        'remaining'   => $max - $count - 1,
        'retry_after' => 0,
        'limit'       => $max,
    ];
}

/**
 * Hard-guard variant. Sets rate-limit headers, aborts with 429 if over.
 *
 * Headers we set:
 *   X-RateLimit-Limit       — the bucket's max
 *   X-RateLimit-Remaining   — how many you have left in this window
 *   Retry-After             — seconds until you can try again (on 429 only)
 *
 * These are advisory headers — useful for legit clients, ignored by bots.
 */
function rate_limit_guard(string $action, int $max, int $window): void
{
    $r = rate_limit($action, $max, $window);

    // Always inform the caller of their standing
    header('X-RateLimit-Limit: '     . $r['limit']);
    header('X-RateLimit-Remaining: ' . $r['remaining']);

    if (!$r['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $r['retry_after']);
        view('error', [
            'title'   => 'Too Many Requests',
            'heading' => 'Slow down',
            'message' => 'You\'ve made too many requests in a short time. Try again in '
                       . $r['retry_after'] . ' second' . ($r['retry_after'] === 1 ? '' : 's') . '.',
        ]);
        exit;
    }
}
