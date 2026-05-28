# Hardin County Civic Tracker

Independent civic information for Hardin County and the City of Kenton, Ohio. Published on the web as **The Hardin Civic**.

Vanilla PHP + SQLite + nginx. Designed to run on a $4 Hetzner VPS behind a Cloudflare Tunnel (no public IP exposed). No Composer. No build step. No JavaScript framework.

---

## What's built

**Public site**

- **Front-controller routing** — every request flows through `public/index.php` → `src/router.php`
- **Newspaper-aesthetic UI** — Fraunces serif headlines, Public Sans body, oxblood + cream palette, fully responsive
- **Homepage** with the "ask anything" hero (textarea disabled until xAI is wired)
- **`/voter-info`** page rendered from `content_pages` via a tiny safe markdown parser
- **`/meetings`** — list of upcoming + recent meetings, grouped by body
- **`/meetings/{id}`** — single-meeting detail page with agenda items and links to official agenda/minutes/video
- **`/bills`** — list of recent Ohio General Assembly bills with filter chips (House/Senate/Resolution) and full-text search, sorted by most recent action
- **`/bills/{id}`** — single bill page with "About" section (abstract or AI summary when available), subject tags, bill text versions across revisions, sponsors (linked to officials), and full action timeline
- **`/submit-correction`** — anonymous correction form with moderation queue (writes to `submissions` table, `status='pending'`)
- **`/healthz`** — plaintext OK for monitoring / uptime pings

**Admin** (`/admin`, password-protected)

- **Password login** with HMAC-signed session cookie — no server-side session table needed
- **Dashboard** showing counts (bodies, meetings, upcoming, agenda items, pending submissions) and a list of upcoming meetings
- **Bodies** management — list + create. Pre-seeded with Kenton City Council, Hardin County Commissioners, Kenton City Schools BoE, Pleasant Township Trustees
- **Meetings** management — list + create with inline agenda-items textarea

**Security plumbing**

- **CSRF protection** via the double-submit cookie pattern — applied to every POST handler (public + admin)
- **Sliding-window rate limiting** per IP per action, with opportunistic cleanup (no cron needed). Login attempts limited to 5 / 5 min
- **Origin / Referer check** as an independent third defense-in-depth layer
- **IP hashing** with a server-side secret — raw IPs never touch disk
- **Admin defense-in-depth** — nginx restricts `/admin/*` to Tailscale + localhost CIDR; PHP also runs `admin_guard()` on every admin handler. Both layers must fail for an unauthorized request to land
- **Production nginx config** with Cloudflare IP forwarding and CSP headers

**Data schema**

- `jurisdictions`, `content_pages`, `submissions`, `ai_calls`, `rate_limit_events`
- `bodies` (city council, county commission, school board, township trustees, planning, zoning, library, parks&rec, other)
- `meetings` with `summary` + `summary_at` columns ready for AI-cached summaries
- `agenda_items` — line items within a meeting; each can carry its own AI-cached summary
- `officials` + `official_terms` — federal/state/county/city officials
- `bills`, `bill_actions`, `bill_sponsorships`, `bill_versions` — Ohio legislation ingested from OpenStates, with one row per revision of each bill's text
- `bills_fts` — SQLite FTS5 virtual table for fast full-text search; auto-synced via triggers
- `worker_runs` — audit log of every Python worker run, including item counts and errors

**Python workers**

- `workers/ingest_openstates.py` — pulls OH General Assembly members + recently-updated OH bills + their actions/sponsorships from the OpenStates API. Idempotent, incremental (only fetches what changed since last run), rate-limit aware
- Scheduled via `deploy/cron/civic.cron` — daily at 4 AM Eastern
- See `workers/README.md` for the Python venv setup and the manual-run command

**What's deliberately not built yet**

- The AI ask box is structurally present on the homepage but **the submit button is disabled** — that's the next coding step once enough data is in the DB to ground it
- Public `/representatives` views — schema and data are ready, view comes next
- Officials admin UI — admin can already see them in the DB, no UI for create/edit yet
- Editing & deleting bodies / meetings — currently create-only via admin
- Submissions moderation UI — submissions sit in the DB; admin queue page is the next admin feature
- Agenda item editing post-creation — items are entered via the meeting textarea; not yet individually editable
- Federal-level ingestion (Congress.gov) — Ohio state is in, federal comes later

---

## Run it locally

### Windows

1. Install PHP from [windows.php.net/download](https://windows.php.net/download/) — pick the latest 8.3+ **VS16 x64 Non Thread Safe** ZIP.
2. Extract to `C:\php`. Inside that folder, copy `php.ini-development` → `php.ini`.
3. Open `C:\php\php.ini` and uncomment these lines (remove the leading `;`):
   ```ini
   extension=pdo_sqlite
   extension=sqlite3
   extension_dir = "C:\php\ext"
   ```
4. Add `C:\php` to your system PATH. Restart your terminal entirely.
5. Verify: `php -v` shows a version, `php -m` lists `pdo_sqlite` and `sqlite3`.

### macOS

```bash
brew install php
```

### Ubuntu / Debian

```bash
sudo apt install -y php8.3-cli php8.3-sqlite3
```

### Then on any OS

```bash
# 1. PHP config — copy template and generate secrets
cp .env.example .env
php tools/set_admin_password.php
# Paste the two lines it prints (ADMIN_PASSWORD_HASH + ADMIN_COOKIE_SECRET) into .env
# Default APP_TIMEZONE is America/New_York; change in .env if you're elsewhere.

# 2. Database
php migrations/migrate.php

# 3. (Optional) Python workers
python -m venv .venv
source .venv/Scripts/activate     # Windows Git Bash; or source .venv/bin/activate elsewhere
pip install -r workers/requirements.txt
# Then add OPENSTATES_API_KEY to .env, get one from https://openstates.org/accounts/signup/

# 4. Dev server
php -S 127.0.0.1:8080 -t public public/index.php
```

Open <http://127.0.0.1:8080/> in your browser. You should see:

- Newspaper masthead with today's date and the locale stamp
- "Ask anything..." box (textarea disabled — xAI wiring is the next step)
- Three editorial cards
- `/voter-info` renders the seeded markdown content
- `/meetings` shows an empty-state until you add some via admin
- `/submit-correction` shows a working form (CSRF-protected, rate-limited)
- `/bills`, `/representatives` show clean "in the works" pages
- `/anything-else` shows a typeset 404

Then go to <http://127.0.0.1:8080/admin>, sign in with the password you just set, and start entering meeting data. The four bodies are pre-seeded so you can create a meeting immediately.

---

## Verify the security plumbing

Once the dev server is running, walk through these to confirm each layer:

1. **CSRF cookie is set.** DevTools → Application → Cookies → `http://127.0.0.1:8080`. There should be a `civic_csrf` cookie with a 64-character hex value, HttpOnly, SameSite=Lax.
2. **CSRF blocks tampering.** Submit a correction successfully, then edit the cookie value in DevTools and submit again → 403 "Session expired".
3. **Origin check blocks bots.** From a separate terminal:
   ```bash
   curl -X POST http://127.0.0.1:8080/submit-correction -d "_csrf=x&where=t&what=t" -i
   ```
   → 403 "Origin check failed" (curl doesn't send Origin or Referer headers).
4. **Rate limit fires at 6.** Submit `/submit-correction` six times in a row → the 6th gets 429 "Slow down" with a `Retry-After` header. The public limit is 5 per hour per IP.
5. **Admin gate works.** Open an incognito window → `/admin` → redirects to `/admin/login`. After login, `civic_admin` cookie appears (Path=`/admin`, so it never leaks to public pages).
6. **Admin brute-force protection.** Type wrong admin passwords 5 times → 6th attempt gets a 429. Login bucket: 5 / 5 min / IP.
7. **Admin logout is CSRF-protected.** `curl -X POST http://127.0.0.1:8080/admin/logout` → 403.
8. **No PII at rest.** Open `data/civic.sqlite` in the VS Code SQLite Viewer. Inspect the `submissions` and `rate_limit_events` tables — every `ip_hash` is a SHA-256 string, never a raw IP.

---

## How requests flow

```
Browser → Cloudflare Tunnel → nginx (127.0.0.1:8080)
                                │
                        ┌───────┴───────┐
                        │               │
              /assets/css/site.css    /any-other-url
              served directly         try_files → /index.php
                                                 │
                                            php-fpm runs
                                            public/index.php
                                                 │
                                            src/router.php
                                                 │
                                      matches a regex,
                                      calls a handler,
                                      handler queries db()
                                      and calls view(...) or admin_view(...)
```

In dev (`php -S`), `cli-server` handles both branches in one process. In production, nginx and php-fpm are separate processes communicating over a Unix socket. The `/admin/*` location block ALSO restricts to Tailscale CIDR before PHP ever runs.

---

## Project layout

```
hardin-county-civic-tracker/
├── public/                                  # nginx web root — only PUBLIC files
│   ├── index.php                            # front controller (only PHP nginx executes)
│   └── assets/
│       ├── css/site.css                     # public newspaper styling
│       ├── css/admin.css                    # admin-only styling
│       ├── js/site.js                       # placeholder (vanilla JS, no framework)
│       └── img/
├── src/
│   ├── bootstrap.php                        # env loader + helpers (e, view, admin_view, ip_hash, md_to_html)
│   ├── db.php                               # PDO singleton + connection pragmas (WAL, FK, busy timeout)
│   ├── router.php                           # route table + all handler functions
│   ├── csrf.php                             # double-submit cookie CSRF protection
│   ├── ratelimit.php                        # sliding-window per-IP rate limiting
│   ├── admin_auth.php                       # admin login, logout, guard, HMAC sessions
│   └── views/
│       ├── layout.php                       # public masthead + footer
│       ├── home.php                         # homepage with ask box
│       ├── voter_info.php                   # /voter-info content
│       ├── meetings.php                     # public meetings list
│       ├── meeting_detail.php               # single meeting + agenda items
│       ├── bills.php                        # public bills list with filter chips + search
│       ├── bill_detail.php                  # single bill: about, text versions, sponsors, timeline
│       ├── submit_correction.php            # public correction form
│       ├── submit_correction_thanks.php     # post-submit confirmation
│       ├── coming_soon.php                  # used by /bills, /representatives
│       ├── not_found.php                    # 404
│       ├── error.php                        # 403 / 429 / 422 — used by guards
│       ├── admin_layout.php                 # admin top-bar wrapper
│       ├── admin_login.php                  # admin sign-in form
│       ├── admin_dashboard.php              # stats + upcoming meetings
│       ├── admin_bodies.php                 # admin: bodies list
│       ├── admin_body_form.php              # admin: create body
│       ├── admin_meetings.php               # admin: meetings list
│       └── admin_meeting_form.php           # admin: create meeting + agenda items
├── migrations/
│   ├── migrate.php                          # CLI runner with _migrations state tracking
│   ├── 0001_init.sql                        # jurisdictions, content_pages, submissions, ai_calls
│   ├── 0002_seed_kenton.sql                 # Hardin + Kenton + Ohio + initial voter-info copy
│   ├── 0003_ratelimit.sql                   # rate_limit_events
│   ├── 0004_civic_data.sql                  # bodies, meetings, agenda_items, officials, official_terms
│   ├── 0005_seed_bodies.sql                 # seeds the 4 main bodies for Kenton + Hardin
│   ├── 0006_legislation.sql                 # bills, bill_actions, bill_sponsorships, worker_runs
│   ├── 0007_bills_fts.sql                   # FTS5 search index for bills + triggers to keep it in sync
│   └── 0008_bill_versions.sql               # bill_versions table — each revision of a bill's text
├── tools/
│   └── set_admin_password.php               # CLI: generate ADMIN_PASSWORD_HASH + ADMIN_COOKIE_SECRET
├── workers/                                 # Python cron jobs (independent of PHP, share only the DB)
│   ├── README.md                            # venv setup, manual run, deploy
│   ├── requirements.txt                     # pinned Python deps
│   ├── _db.py                               # shared: connect to SQLite with project pragmas
│   └── ingest_openstates.py                 # OH legislators + bills daily
├── data/civic.sqlite                        # the database (git-ignored)
├── deploy/
│   ├── nginx/civic.conf                     # production vhost config
│   └── cron/civic.cron                      # /etc/cron.d/ schedule for workers
├── .env.example                             # template — copy to .env, fill in secrets
├── .editorconfig                            # cross-editor formatting
├── .gitattributes                           # line-ending normalization
├── .gitignore                               # keeps .env and data/ out of version control
├── .vscode/settings.json                    # VS Code workspace settings
└── README.md
```

---

## Deploy to Hetzner

1. **VPS:** spin up a CX22 ($4/mo) with Ubuntu 24.04
2. **Tailscale:** `curl -fsSL https://tailscale.com/install.sh | sh && sudo tailscale up`
3. **Cloudflare Tunnel:** install `cloudflared`, run `cloudflared tunnel login`, create a tunnel, point your hostname → `http://localhost:8080`
4. **Server stack:** `sudo apt install -y nginx php8.3-fpm php8.3-sqlite3 unattended-upgrades`
5. **Firewall:** `sudo ufw allow 22 && sudo ufw enable` (only SSH; Cloudflare Tunnel and Tailscale are outbound)
6. **Code:** `git clone` into `/var/www/civic`, `cp .env.example .env`
7. **Secrets:** generate everything fresh on the server:
   ```bash
   # IP hash secret
   echo "IP_HASH_SECRET=$(openssl rand -hex 32)" >> .env
   # Admin password + cookie secret
   sudo -u www-data php tools/set_admin_password.php
   # ...paste output into .env
   ```
8. **Permissions:** `sudo chown -R www-data:www-data data/ && sudo chmod 755 data/`
9. **Migrate:** `sudo -u www-data php migrations/migrate.php`
10. **nginx:** `sudo cp deploy/nginx/civic.conf /etc/nginx/sites-available/ && sudo ln -s ../sites-available/civic.conf /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx`

Visit your domain. Sign into `/admin` over Tailscale. Done.

---

## Roadmap (in build order)

1. **Public `/representatives` views** — surface the OpenStates-ingested officials data
2. **Officials admin** — manual CRUD for officials OpenStates doesn't cover (sheriff, county auditor, mayor, council members, etc.)
3. **Edit & delete** for bodies and meetings (currently create-only)
4. **Agenda item editing** — re-order, edit, delete individual items on a meeting
5. **Submissions moderation queue** at `/admin/submissions` — approve / reject / spam
6. **Python worker `ingest_congress.py`** — your federal reps + OH-04 bills (Congress.gov API)
7. **Python worker `scrape_kenton_meetings.py`** — Kenton city site, agenda PDFs (the hard one)
8. **Wire `/ask` to xAI** — at this point the DB has enough context for the AI to give grounded local answers
9. **Python worker `summarize_pending.py`** — find agenda items / meetings / bills without summaries, call xAI, cache to `ai_calls`
10. **Petitions** (last — anonymous + spam needs more thought)

---

## Conventions worth knowing if you contribute

- **Escape on output**, never on input. Call `e()` in every view, every echo of dynamic data.
- **Prepared statements only.** `EMULATE_PREPARES = false` is set in `src/db.php`. Never string-concatenate into SQL.
- **Timestamps are `INTEGER` Unix epoch.** Faster index, timezone-agnostic. Default with `unixepoch()`.
- **IPs are hashed** with `IP_HASH_SECRET` before storage. Raw IPs never touch disk.
- **Every state-changing handler** calls `csrf_guard()` first, then `rate_limit_guard(...)`. Admin handlers also call `admin_guard()`. Order matters: auth → CSRF → rate limit → work.
- **Database-level pragmas live in `src/db.php`**, not in migrations. (Learned the hard way: `PRAGMA journal_mode = WAL` can't run inside a transaction.)
- **Admin views use `admin_view()`** (admin layout); public views use `view()` (newspaper layout). The two are deliberately visually distinct so you always know which side you're on.
- **Server time is always Eastern.** `bootstrap.php` calls `date_default_timezone_set(env('APP_TIMEZONE', 'America/New_York'))`. Stored timestamps are timezone-agnostic unix epoch ints; the TZ only affects parse/format at the edges.
- **Forms with `class="js-once"`** auto-disable their submit button on click (via `public/assets/js/site.js`). Add the class + an optional `data-busy-text` attribute on the button to opt any new form in. Server-side validation still runs — JS is just polish.
- **One coherent change per commit.** Imperative-mood commit messages ("Add X", not "added X" or "X added").
