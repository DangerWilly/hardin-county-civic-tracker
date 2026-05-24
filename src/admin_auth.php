<?php
declare(strict_types=1);

/**
 * src/admin_auth.php — single-password admin authentication.
 *
 * APPROACH
 *
 *   * One admin password is stored as a bcrypt hash in .env (ADMIN_PASSWORD_HASH).
 *     To set or rotate it: `php tools/set_admin_password.php`.
 *   * Successful login sets a signed cookie `civic_admin`. The cookie value
 *     is `base64(payload).base64(hmac_sha256(payload, secret))`.
 *     Payload = compact JSON `{"exp":<unix_ts>}`.
 *   * Every admin route calls `admin_guard()` first. The guard verifies the
 *     HMAC (constant-time) and checks expiry. Any failure → redirect to login.
 *
 * WHY THIS DESIGN
 *
 *   * No server-side session table. Logout = delete cookie. Rotating the
 *     ADMIN_COOKIE_SECRET invalidates every issued session at once — useful
 *     if a session token is ever compromised.
 *   * Defense in depth: nginx ALREADY blocks /admin from non-Tailscale IPs
 *     (see deploy/nginx/civic.conf). This layer is the *second* line. If
 *     either fails, the other holds.
 *   * CSRF on the login form uses our normal csrf_field() — same machinery
 *     as everywhere else.
 *
 * COOKIE FLAGS
 *
 *   HttpOnly        — not readable by JS, can't be stolen via XSS
 *   SameSite=Strict — never sent on any cross-site request, ever
 *   Secure          — only over HTTPS in production
 *   path=/admin     — only sent to admin routes, never leaks to public
 */

require_once __DIR__ . '/bootstrap.php';

const ADMIN_COOKIE_NAME    = 'civic_admin';
const ADMIN_SESSION_TTL    = 28800;   // 8 hours of admin work per login

/**
 * Sign a payload with the configured secret. Returns "b64(payload).b64(hmac)".
 */
function admin_session_encode(array $payload): string
{
    $secret = env('ADMIN_COOKIE_SECRET', '');
    if ($secret === '' || strlen($secret) < 32) {
        throw new RuntimeException('ADMIN_COOKIE_SECRET not set or too short. Run: openssl rand -hex 32');
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig  = hash_hmac('sha256', $b64, $secret, true);
    $sb64 = rtrim(strtr(base64_encode($sig),  '+/', '-_'), '=');
    return $b64 . '.' . $sb64;
}

/**
 * Verify + decode a cookie value. Returns the payload array on success or
 * null on any failure (bad shape, bad signature, expired).
 */
function admin_session_decode(string $cookie): ?array
{
    $secret = env('ADMIN_COOKIE_SECRET', '');
    if ($secret === '' || strlen($secret) < 32) return null;

    if (substr_count($cookie, '.') !== 1) return null;
    [$b64, $sb64] = explode('.', $cookie, 2);

    // Recompute the expected signature, compare in constant time
    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $b64, $secret, true)
    ), '+/', '-_'), '=');
    if (!hash_equals($expected, $sb64)) return null;

    // Decode payload
    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) return null;
    try {
        $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($payload) || !isset($payload['exp'])) return null;
    if ((int) $payload['exp'] < time()) return null;

    return $payload;
}

/**
 * Returns true if the current request has a valid admin session cookie.
 * Memoized for the lifetime of the request.
 */
function admin_is_authenticated(): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = $_COOKIE[ADMIN_COOKIE_NAME] ?? '';
    if (!is_string($raw) || $raw === '') return $cached = false;

    return $cached = admin_session_decode($raw) !== null;
}

/**
 * Verify the user-submitted password against the stored hash.
 * Returns true on match. Uses PHP's password_verify (constant time).
 */
function admin_password_verify(string $password): bool
{
    $hash = env('ADMIN_PASSWORD_HASH', '');
    if ($hash === '') return false;        // no password configured = no login possible
    return password_verify($password, $hash);
}

/**
 * Set the admin session cookie. Call after a successful login.
 */
function admin_login(): void
{
    $exp    = time() + ADMIN_SESSION_TTL;
    $value  = admin_session_encode(['exp' => $exp, 'iat' => time()]);
    $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(ADMIN_COOKIE_NAME, $value, [
        'expires'  => $exp,
        'path'     => '/admin',          // only sent to admin routes
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',          // tighter than the public CSRF cookie
    ]);
}

/**
 * Clear the admin session cookie. Idempotent.
 */
function admin_logout(): void
{
    setcookie(ADMIN_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/admin',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Gate for every admin handler. If not authenticated, redirect to /admin/login
 * with the originally-requested URL captured in ?next= so we can return to it.
 */
function admin_guard(): void
{
    if (admin_is_authenticated()) return;

    $next = $_SERVER['REQUEST_URI'] ?? '/admin';
    // Only allow relative redirect targets — prevents open-redirect attacks
    // where ?next=https://evil.com would otherwise smuggle users away.
    if (!preg_match('#^/admin(/|$)#', $next)) $next = '/admin';

    header('Location: /admin/login?next=' . urlencode($next));
    exit;
}
