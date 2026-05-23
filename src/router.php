<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * src/router.php
 *
 * Tiny dispatcher. Every request from nginx lands here via public/index.php.
 * Routes are defined as [METHOD, regex, handler]. Handlers receive the regex
 * match groups as args.
 *
 * Why a switch isn't enough: we want regex segments (slugs, ids) and route
 * groups eventually. This is ~20 lines and does both.
 */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path   = '/' . trim(rawurldecode($uri), '/');

/* ---------- Route table ---------- */

$routes = [
    ['GET', '#^/$#', 'route_home'],

    ['GET', '#^/voter-info/?$#', 'route_voter_info'],

    // Routes we'll wire up in later steps — render a clean "coming soon"
    // instead of 404 so the masthead nav is fully functional from day one.
    ['GET', '#^/meetings/?$#',        fn() => route_coming_soon(
        'Meetings',
        'A live calendar of Kenton city council, Hardin County commissioners, and school-board meetings — with AI-summarized agendas — is the next thing we ship.'
    )],
    ['GET', '#^/bills/?$#',           fn() => route_coming_soon(
        'Bills',
        'Recent bills from the Ohio General Assembly affecting Hardin County, in plain English. Sourced via OpenStates.'
    )],
    ['GET', '#^/representatives/?$#', fn() => route_coming_soon(
        'Representatives',
        'Your federal, state, and county officials — with contact info, voting records, and the bills they have championed.'
    )],
    ['GET',  '#^/submit-correction/?$#', 'route_submit_correction_get'],
    ['POST', '#^/submit-correction/?$#', 'route_submit_correction_post'],

    // Health check for monitoring (Cloudflare, uptime pings, etc.)
    ['GET', '#^/healthz/?$#', function (): void {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ok\n";
    }],
];

/* ---------- Dispatch ---------- */

foreach ($routes as [$m, $re, $handler]) {
    if ($m !== $method)              continue;
    if (!preg_match($re, $path, $args)) continue;
    array_shift($args);               // drop full match
    $handler(...$args);
    return;
}

http_response_code(404);
view('not_found', ['title' => 'Not Found']);


/* ---------- Handlers ---------- */
// Kept in this file because v1 only has a handful. When we cross ~10
// handlers we'll split each into src/handlers/<name>.php.

function route_home(): void
{
    $county = db()->prepare("SELECT * FROM jurisdictions WHERE slug = ?");
    $county->execute(['oh-hardin']);
    $county = $county->fetch();

    $city = db()->prepare("SELECT * FROM jurisdictions WHERE slug = ?");
    $city->execute(['oh-hardin-kenton']);
    $city = $city->fetch();

    view('home', [
        'title'  => 'The Hardin Civic',
        'county' => $county,
        'city'   => $city,
    ]);
}

function route_voter_info(): void
{
    $stmt = db()->prepare("
        SELECT cp.*, j.name AS jurisdiction_name
          FROM content_pages cp
          JOIN jurisdictions j ON j.id = cp.jurisdiction_id
         WHERE j.slug = ?  AND cp.slug = ?  AND cp.published = 1
    ");
    $stmt->execute(['oh-hardin', 'voter-info']);
    $page = $stmt->fetch();

    if (!$page) {
        http_response_code(404);
        view('not_found', ['title' => 'Not Found']);
        return;
    }

    view('voter_info', [
        'title' => $page['title'],
        'page'  => $page,
    ]);
}

function route_coming_soon(string $title, string $blurb): void
{
    view('coming_soon', [
        'title' => $title,
        'blurb' => $blurb,
    ]);
}

/* ---- /submit-correction ----------------------------------------------- *
 * GET renders the form. POST validates, rate-limits, CSRF-guards, then
 * writes a row to `submissions` with status='pending'. Moderation queue
 * (admin UI) comes in a later step — for now the row just sits there.
 * ----------------------------------------------------------------------- */

function route_submit_correction_get(): void
{
    require_once APP_ROOT . '/src/csrf.php';   // calls csrf_field() in the view
    view('submit_correction', ['title' => 'Submit a Correction']);
}

function route_submit_correction_post(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/ratelimit.php';

    // ORDER MATTERS:
    //  1. CSRF first — cheap to verify, and we don't want to even THINK
    //     about request bodies from cross-site forgeries.
    //  2. Rate limit second — once CSRF is good, we know it's a real form
    //     submit, so this IP "earned" a slot in their bucket.
    csrf_guard();
    rate_limit_guard('submit_correction', max: 5, window: 3600);   // 5/hour

    // Server-side validation. NEVER trust the client; maxlength is a hint.
    $what  = trim((string) ($_POST['what']  ?? ''));
    $where = trim((string) ($_POST['where'] ?? ''));

    $errors = [];
    if ($where === '')              $errors[] = 'Please tell us where on the site.';
    if (strlen($where) > 200)       $errors[] = 'The "where" field is too long.';
    if ($what === '')               $errors[] = 'Please describe what needs correcting.';
    if (strlen($what) > 2000)       $errors[] = 'The correction text is too long (2000 chars max).';

    if ($errors !== []) {
        http_response_code(422);   // Unprocessable Entity
        view('submit_correction', [
            'title' => 'Submit a Correction',
            'error' => implode(' ', $errors),
            'old'   => ['what' => $what, 'where' => $where],
        ]);
        return;
    }

    db()->prepare("
        INSERT INTO submissions (kind, payload, ip_hash)
        VALUES ('correction', ?, ?)
    ")->execute([
        json_encode(
            ['what' => $what, 'where' => $where],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        ip_hash(),
    ]);

    view('submit_correction_thanks', ['title' => 'Thank You']);
}
