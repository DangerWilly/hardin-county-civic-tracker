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
    ['GET', '#^/submit-correction/?$#', fn() => route_coming_soon(
        'Submit a Correction',
        'A form for neighbors to flag bad data. Submissions go to a moderation queue, never live unattended.'
    )],

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
