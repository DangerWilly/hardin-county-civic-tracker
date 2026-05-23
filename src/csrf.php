<?php
declare(strict_types=1);

/**
 * src/csrf.php — CSRF protection for an anonymous, sessionless site.
 *
 * Pattern: "double-submit cookie."
 *
 *   1. On first form render, we generate a 32-byte random token.
 *   2. We set it as an HttpOnly, SameSite=Lax cookie ("civic_csrf").
 *   3. We embed the same token in the form as <input name="_csrf">.
 *   4. On POST, the server compares the cookie value vs the form value.
 *      If they don't match (or either is missing/malformed), 403.
 *
 * Why this works WITHOUT sessions:
 *   - Same-origin policy means evil.com cannot read your civic_csrf cookie
 *     from the user's browser. So an attacker cannot craft a forged form
 *     whose _csrf field matches the victim's cookie. Their submit fails.
 *   - We don't need to store the token server-side — the cookie IS the
 *     server's record. As long as it round-trips back unchanged in the form,
 *     we know the form was rendered by us, in this browser, recently.
 *
 * Defense in depth:
 *   - SameSite=Lax: even if double-submit fails, the cookie wouldn't have
 *     been sent on most cross-site POSTs anyway.
 *   - Origin/Referer header check: a third independent layer (csrf_guard).
 *   - hash_equals: constant-time comparison defeats timing attacks.
 *
 * Why not HMAC-signed stateless tokens? Two reasons:
 *   1. Token replay would still be possible across the validity window.
 *      With double-submit, the cookie scope (one browser) already limits
 *      replay to within a single victim — adding signing buys nothing.
 *   2. Simpler code = fewer bugs in security-critical paths.
 */

require_once __DIR__ . '/bootstrap.php';

const CSRF_COOKIE_NAME    = 'civic_csrf';
const CSRF_TOKEN_LIFETIME = 3600;     // 1 hour. Long enough that mid-form
                                      // rotation never bites legit users;
                                      // short enough to bound replay window.

/**
 * Return the current request's CSRF token, generating + setting the cookie
 * if necessary. Memoized so repeat calls in the same request are cheap.
 *
 * IMPORTANT: must be called before any output is sent, because setting
 * a cookie sends an HTTP header. In our architecture, views are buffered
 * by ob_start() inside view(), so calling csrf_field() from inside a view
 * is safe — the cookie header gets flushed at the same time as the layout.
 */
function csrf_token(): string
{
    static $token = null;
    if ($token !== null) return $token;

    // Reuse an existing valid cookie if present
    $existing = $_COOKIE[CSRF_COOKIE_NAME] ?? '';
    if (preg_match('/^[a-f0-9]{64}$/', $existing) === 1) {
        $token = $existing;
        return $token;
    }

    // Generate new
    $token = bin2hex(random_bytes(32));   // 64 hex chars

    // Detect HTTPS even when behind Cloudflare Tunnel / reverse proxy.
    // (Direct: $_SERVER['HTTPS'] is 'on'. Behind proxy: X-Forwarded-Proto or
    // Cloudflare's CF-Visitor header tells us the real client scheme.)
    $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
           || (str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', '"https"'));

    if (!headers_sent()) {
        setcookie(CSRF_COOKIE_NAME, $token, [
            'expires'  => time() + CSRF_TOKEN_LIFETIME,
            'path'     => '/',
            'domain'   => '',          // current host only
            'secure'   => $secure,
            'httponly' => true,        // not readable by JS
            'samesite' => 'Lax',       // not sent on most cross-site requests
        ]);
        // Make it available to the rest of this request, not just the next one
        $_COOKIE[CSRF_COOKIE_NAME] = $token;
    }
    return $token;
}

/** A ready-to-paste hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * For JS clients (fetch from /ask, etc) that send JSON, expose the token via
 * a <meta> tag in the page <head>. JS reads `document.querySelector(
 * 'meta[name=csrf-token]').content` and sends it back as X-CSRF-Token.
 */
function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

/**
 * Compare the cookie value to the submitted token. Returns true on match.
 * The token may arrive via _csrf form field OR X-CSRF-Token header — the
 * header is used by fetch/JSON clients.
 */
function csrf_verify(): bool
{
    $cookie = $_COOKIE[CSRF_COOKIE_NAME] ?? '';
    $sent   = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    // Type + shape check before hash_equals. Defends against null bytes
    // and length-leak side channels.
    if (!is_string($cookie) || !is_string($sent))         return false;
    if (preg_match('/^[a-f0-9]{64}$/', $cookie) !== 1)    return false;
    if (preg_match('/^[a-f0-9]{64}$/', $sent)   !== 1)    return false;

    return hash_equals($cookie, $sent);
}

/**
 * Belt-and-suspenders Origin check. Browsers send Origin on every cross-site
 * POST and on most same-site POSTs. If neither Origin nor Referer is present,
 * we reject — a normal browser wouldn't omit both. Curl + bots will fail
 * this check, which is the point.
 */
function csrf_check_origin(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return false;

    // Compare bare hostname only (strip port)
    $host_only = strtolower(explode(':', $host)[0]);

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value === '') continue;
        $parts = parse_url($value);
        $origin_host = strtolower($parts['host'] ?? '');
        if ($origin_host === '') continue;
        return $origin_host === $host_only;
    }

    return false;   // Neither Origin nor Referer? Suspicious. Reject.
}

/**
 * One-line guard for POST handlers. Aborts with 403 if anything looks off.
 * Use it at the top of every state-changing handler:
 *
 *   function route_submit_correction_post(): void {
 *       csrf_guard();
 *       // ... rest of the handler ...
 *   }
 */
function csrf_guard(): void
{
    if (!csrf_check_origin()) {
        http_response_code(403);
        view('error', [
            'title'   => 'Forbidden',
            'heading' => 'Origin check failed',
            'message' => 'This request did not appear to come from this site. If you got here via a normal click, please try reloading the page.',
        ]);
        exit;
    }
    if (!csrf_verify()) {
        http_response_code(403);
        view('error', [
            'title'   => 'Forbidden',
            'heading' => 'Session expired',
            'message' => 'Your form token is missing or expired. Please reload the page and try again.',
        ]);
        exit;
    }
}
