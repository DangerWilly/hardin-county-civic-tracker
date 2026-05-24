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

    // Public meeting pages — list + single
    ['GET', '#^/meetings/?$#',           'route_meetings_index'],
    ['GET', '#^/meetings/(\d+)/?$#',     'route_meeting_show'],

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

    /* ---- Admin routes -------------------------------------------------- *
     * Network-restricted by nginx to Tailscale + localhost (see
     * deploy/nginx/civic.conf). Each handler ALSO calls admin_guard() —
     * defense in depth. Login/logout don't gate themselves (the login page
     * IS the gate).
     * ------------------------------------------------------------------- */

    ['GET',  '#^/admin/?$#',                  'route_admin_dashboard'],
    ['GET',  '#^/admin/login/?$#',            'route_admin_login_get'],
    ['POST', '#^/admin/login/?$#',            'route_admin_login_post'],
    ['POST', '#^/admin/logout/?$#',           'route_admin_logout'],

    ['GET',  '#^/admin/bodies/?$#',           'route_admin_bodies_index'],
    ['GET',  '#^/admin/bodies/new/?$#',       'route_admin_bodies_new_get'],
    ['POST', '#^/admin/bodies/new/?$#',       'route_admin_bodies_new_post'],

    ['GET',  '#^/admin/meetings/?$#',         'route_admin_meetings_index'],
    ['GET',  '#^/admin/meetings/new/?$#',     'route_admin_meetings_new_get'],
    ['POST', '#^/admin/meetings/new/?$#',     'route_admin_meetings_new_post'],

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

/* ---------- Public meeting handlers ------------------------------------ */

function route_meetings_index(): void
{
    $now = time();
    // Upcoming: status=scheduled OR live, starts_at >= now (or live regardless)
    $upcoming = db()->prepare("
        SELECT m.*, b.name AS body_name,
               (SELECT COUNT(*) FROM agenda_items WHERE meeting_id = m.id) AS item_count
          FROM meetings m
          JOIN bodies b ON b.id = m.body_id
         WHERE m.status IN ('scheduled', 'live')
           AND m.starts_at >= :cutoff
         ORDER BY m.starts_at ASC
         LIMIT 50
    ");
    $upcoming->execute([':cutoff' => $now - 7200]);   // include events that started up to 2h ago

    // Recent completed meetings
    $recent = db()->prepare("
        SELECT m.*, b.name AS body_name,
               (SELECT COUNT(*) FROM agenda_items WHERE meeting_id = m.id) AS item_count
          FROM meetings m
          JOIN bodies b ON b.id = m.body_id
         WHERE m.status = 'completed' OR m.starts_at < :cutoff
         ORDER BY m.starts_at DESC
         LIMIT 20
    ");
    $recent->execute([':cutoff' => $now - 7200]);

    view('meetings', [
        'title'    => 'Public Meetings',
        'upcoming' => $upcoming->fetchAll(),
        'recent'   => $recent->fetchAll(),
    ]);
}

function route_meeting_show(string $id): void
{
    $stmt = db()->prepare("
        SELECT m.*, b.name AS body_name, b.slug AS body_slug
          FROM meetings m
          JOIN bodies b ON b.id = m.body_id
         WHERE m.id = ?
    ");
    $stmt->execute([(int) $id]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        http_response_code(404);
        view('not_found', ['title' => 'Meeting not found']);
        return;
    }

    $items = db()->prepare("
        SELECT * FROM agenda_items
         WHERE meeting_id = ?
         ORDER BY position ASC, id ASC
    ");
    $items->execute([(int) $meeting['id']]);

    view('meeting_detail', [
        'title'   => $meeting['title'] ?? 'Meeting',
        'meeting' => $meeting,
        'items'   => $items->fetchAll(),
    ]);
}

/* ---------- Admin: auth ------------------------------------------------- *
 * The login page renders without admin_guard (it's the gate itself). Every
 * other admin handler calls admin_guard() before doing any work.
 * ----------------------------------------------------------------------- */

function route_admin_login_get(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/admin_auth.php';

    // Already signed in? Skip the form.
    if (admin_is_authenticated()) {
        header('Location: /admin');
        exit;
    }

    $next = $_GET['next'] ?? '/admin';
    if (!is_string($next) || !preg_match('#^/admin(/|$)#', $next)) $next = '/admin';

    admin_view('admin_login', ['title' => 'Sign in', 'next' => $next]);
}

function route_admin_login_post(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/ratelimit.php';
    require_once APP_ROOT . '/src/admin_auth.php';

    csrf_guard();
    // Tight rate limit on login attempts to prevent password brute-force
    rate_limit_guard('admin_login', max: 5, window: 300);   // 5 attempts / 5 min

    $password = (string) ($_POST['password'] ?? '');
    $next     = (string) ($_POST['next']     ?? '/admin');
    if (!preg_match('#^/admin(/|$)#', $next)) $next = '/admin';

    if (!admin_password_verify($password)) {
        // Don't reveal whether the password was close — just say no
        admin_view('admin_login', [
            'title' => 'Sign in',
            'next'  => $next,
            'error' => 'Incorrect password.',
        ]);
        return;
    }

    admin_login();
    header('Location: ' . $next);
    exit;
}

function route_admin_logout(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/admin_auth.php';
    csrf_guard();
    admin_logout();
    header('Location: /admin/login');
    exit;
}

/* ---------- Admin: dashboard -------------------------------------------- */

function route_admin_dashboard(): void
{
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();

    $pdo = db();
    $upcomingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE status IN ('scheduled','live') AND starts_at >= ?");
    $upcomingCountStmt->execute([time() - 7200]);
    $counts = [
        'bodies'              => (int) $pdo->query("SELECT COUNT(*) FROM bodies")->fetchColumn(),
        'meetings'            => (int) $pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn(),
        'upcoming'            => (int) $upcomingCountStmt->fetchColumn(),
        'agenda_items'        => (int) $pdo->query("SELECT COUNT(*) FROM agenda_items")->fetchColumn(),
        'pending_submissions' => (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn(),
    ];

    $upcomingStmt = $pdo->prepare("
        SELECT m.id, m.title, m.starts_at, b.name AS body_name
          FROM meetings m JOIN bodies b ON b.id = m.body_id
         WHERE m.status IN ('scheduled','live') AND m.starts_at >= ?
         ORDER BY m.starts_at ASC LIMIT 10
    ");
    $upcomingStmt->execute([time() - 7200]);

    admin_view('admin_dashboard', [
        'title'    => 'Dashboard',
        'counts'   => $counts,
        'upcoming' => $upcomingStmt->fetchAll(),
    ]);
}

/* ---------- Admin: bodies ----------------------------------------------- */

function route_admin_bodies_index(): void
{
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();

    $stmt = db()->query("
        SELECT b.*, j.name AS jurisdiction_name
          FROM bodies b
          JOIN jurisdictions j ON j.id = b.jurisdiction_id
         ORDER BY j.kind, b.name
    ");
    admin_view('admin_bodies', [
        'title'   => 'Bodies',
        'section' => 'bodies',
        'bodies'  => $stmt->fetchAll(),
    ]);
}

function route_admin_bodies_new_get(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();

    $jurisdictions = db()->query("SELECT id, name, kind FROM jurisdictions ORDER BY kind, name")->fetchAll();
    admin_view('admin_body_form', [
        'title'         => 'New body',
        'section'       => 'bodies',
        'jurisdictions' => $jurisdictions,
    ]);
}

function route_admin_bodies_new_post(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();
    csrf_guard();

    $name            = trim((string) ($_POST['name']            ?? ''));
    $jurisdiction_id = (int) ($_POST['jurisdiction_id'] ?? 0);
    $kind            = (string) ($_POST['kind']            ?? '');
    $slug            = trim((string) ($_POST['slug']            ?? ''));
    $website_url     = trim((string) ($_POST['website_url']     ?? ''));
    $contact_email   = trim((string) ($_POST['contact_email']   ?? ''));
    $contact_phone   = trim((string) ($_POST['contact_phone']   ?? ''));
    $meeting_info    = trim((string) ($_POST['meeting_info']    ?? ''));
    $description     = trim((string) ($_POST['description']     ?? ''));

    $old = compact('name','jurisdiction_id','kind','slug','website_url','contact_email','contact_phone','meeting_info','description');

    $errors = [];
    if ($name === '')          $errors[] = 'Name is required.';
    if ($jurisdiction_id <= 0) $errors[] = 'Jurisdiction is required.';
    $allowed_kinds = ['city_council','county_commission','school_board','township_trustees','planning','zoning','library_board','parks_rec','other'];
    if (!in_array($kind, $allowed_kinds, true)) $errors[] = 'Invalid kind.';

    // Auto-generate slug if blank
    if ($slug === '') $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) $errors[] = 'Slug must be lowercase alphanumeric with hyphens.';

    if ($errors !== []) {
        http_response_code(422);
        $jurisdictions = db()->query("SELECT id, name, kind FROM jurisdictions ORDER BY kind, name")->fetchAll();
        admin_view('admin_body_form', [
            'title'         => 'New body',
            'section'       => 'bodies',
            'jurisdictions' => $jurisdictions,
            'error'         => implode(' ', $errors),
            'old'           => $old,
        ]);
        return;
    }

    try {
        $stmt = db()->prepare("
            INSERT INTO bodies (jurisdiction_id, slug, name, kind, website_url, contact_email,
                                contact_phone, meeting_info, description, source)
            VALUES (:jur, :slug, :name, :kind, :url, :email, :phone, :info, :desc, 'manual')
        ");
        $stmt->execute([
            ':jur'   => $jurisdiction_id,
            ':slug'  => $slug,
            ':name'  => $name,
            ':kind'  => $kind,
            ':url'   => $website_url ?: null,
            ':email' => $contact_email ?: null,
            ':phone' => $contact_phone ?: null,
            ':info'  => $meeting_info ?: null,
            ':desc'  => $description ?: null,
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            $errors[] = 'A body with that slug already exists in this jurisdiction.';
        } else {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
        http_response_code(422);
        $jurisdictions = db()->query("SELECT id, name, kind FROM jurisdictions ORDER BY kind, name")->fetchAll();
        admin_view('admin_body_form', [
            'title'         => 'New body',
            'section'       => 'bodies',
            'jurisdictions' => $jurisdictions,
            'error'         => implode(' ', $errors),
            'old'           => $old,
        ]);
        return;
    }

    header('Location: /admin/bodies');
    exit;
}

/* ---------- Admin: meetings --------------------------------------------- */

function route_admin_meetings_index(): void
{
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();

    $stmt = db()->query("
        SELECT m.*, b.name AS body_name,
               (SELECT COUNT(*) FROM agenda_items WHERE meeting_id = m.id) AS item_count
          FROM meetings m
          JOIN bodies b ON b.id = m.body_id
         ORDER BY m.starts_at DESC
         LIMIT 100
    ");
    admin_view('admin_meetings', [
        'title'    => 'Meetings',
        'section'  => 'meetings',
        'meetings' => $stmt->fetchAll(),
    ]);
}

function route_admin_meetings_new_get(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();

    $bodies = db()->query("
        SELECT b.id, b.name, j.name AS jurisdiction_name
          FROM bodies b JOIN jurisdictions j ON j.id = b.jurisdiction_id
         ORDER BY j.kind, b.name
    ")->fetchAll();
    admin_view('admin_meeting_form', [
        'title'   => 'New meeting',
        'section' => 'meetings',
        'bodies'  => $bodies,
    ]);
}

function route_admin_meetings_new_post(): void
{
    require_once APP_ROOT . '/src/csrf.php';
    require_once APP_ROOT . '/src/admin_auth.php';
    admin_guard();
    csrf_guard();

    $body_id       = (int) ($_POST['body_id'] ?? 0);
    $title         = trim((string) ($_POST['title']         ?? ''));
    $starts_at_raw = trim((string) ($_POST['starts_at']     ?? ''));
    $status        = (string) ($_POST['status']        ?? 'scheduled');
    $location_name = trim((string) ($_POST['location_name'] ?? ''));
    $address       = trim((string) ($_POST['address']       ?? ''));
    $agenda_url    = trim((string) ($_POST['agenda_url']    ?? ''));
    $items_raw     = (string) ($_POST['agenda_items']  ?? '');
    $notes_md      = trim((string) ($_POST['notes_md']      ?? ''));

    $old = compact('body_id','title','starts_at_raw','status','location_name','address','agenda_url','notes_md');
    $old['agenda_items'] = $items_raw;
    $old['starts_at']    = $starts_at_raw;   // keep the form's datetime-local value

    $errors = [];
    if ($body_id <= 0)                                   $errors[] = 'Body is required.';
    if ($starts_at_raw === '')                           $errors[] = 'Start time is required.';
    if (!in_array($status, ['scheduled','live','completed','cancelled'], true)) $status = 'scheduled';

    // datetime-local arrives as "YYYY-MM-DDTHH:MM" without timezone. Treat as
    // local server time. Production server runs on UTC; for Kenton we'll
    // adjust display later by setting TZ in the env or using America/New_York.
    $starts_at = 0;
    if ($starts_at_raw !== '') {
        $ts = strtotime($starts_at_raw);
        if ($ts === false || $ts < 0) {
            $errors[] = 'Invalid start time.';
        } else {
            $starts_at = $ts;
        }
    }

    if ($errors !== []) {
        http_response_code(422);
        $bodies = db()->query("SELECT id, name FROM bodies ORDER BY name")->fetchAll();
        admin_view('admin_meeting_form', [
            'title'   => 'New meeting',
            'section' => 'meetings',
            'bodies'  => $bodies,
            'error'   => implode(' ', $errors),
            'old'     => $old,
        ]);
        return;
    }

    // Insert meeting + agenda items in a single transaction so a partial
    // failure leaves the DB clean.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO meetings (body_id, title, starts_at, status, location_name, address,
                                  agenda_url, notes_md, source)
            VALUES (:bid, :title, :starts, :status, :loc, :addr, :agenda, :notes, 'manual')
        ");
        $stmt->execute([
            ':bid'    => $body_id,
            ':title'  => $title !== '' ? $title : null,
            ':starts' => $starts_at,
            ':status' => $status,
            ':loc'    => $location_name !== '' ? $location_name : null,
            ':addr'   => $address !== '' ? $address : null,
            ':agenda' => $agenda_url !== '' ? $agenda_url : null,
            ':notes'  => $notes_md !== '' ? $notes_md : null,
        ]);
        $meeting_id = (int) $pdo->lastInsertId();

        $lines = preg_split('/\r?\n/', $items_raw);
        $position = 1;
        $itemStmt = $pdo->prepare("
            INSERT INTO agenda_items (meeting_id, position, title) VALUES (?, ?, ?)
        ");
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $itemStmt->execute([$meeting_id, $position++, $line]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        $bodies = db()->query("SELECT id, name FROM bodies ORDER BY name")->fetchAll();
        admin_view('admin_meeting_form', [
            'title'   => 'New meeting',
            'section' => 'meetings',
            'bodies'  => $bodies,
            'error'   => 'Database error: ' . $e->getMessage(),
            'old'     => $old,
        ]);
        return;
    }

    header('Location: /meetings/' . $meeting_id);
    exit;
}
