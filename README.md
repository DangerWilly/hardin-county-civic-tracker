# Hardin County Civic Tracker

Independent civic information for Hardin County and the City of Kenton, Ohio. Published on the web as **The Hardin Civic**.

Vanilla PHP + SQLite + nginx. Designed to run on a $4 Hetzner VPS behind a Cloudflare Tunnel (no public IP exposed). No Composer. No build step. No JavaScript framework.

---

## What's built

- **Front-controller routing** — every request flows through `public/index.php` → `src/router.php`
- **SQLite + a tiny migration runner** with state tracking in a `_migrations` table
- **Newspaper-aesthetic UI** — Fraunces serif headlines, Public Sans body, oxblood + cream palette, fully responsive
- **Real `/voter-info` page** rendered from the `content_pages` table via a tiny safe markdown parser
- **Working `/submit-correction` form** with anonymous moderation queue (writes to `submissions` table, `status='pending'`)
- **CSRF protection** via the double-submit cookie pattern — no server-side sessions needed
- **Sliding-window rate limiting** per IP per action, with opportunistic cleanup (no cron needed)
- **Origin / Referer check** as an independent third defense-in-depth layer
- **IP hashing** with a server-side secret — raw IPs never touch disk
- **`/healthz`** plaintext endpoint for monitoring / uptime pings
- **Production nginx config** with Cloudflare IP forwarding, Tailscale-only `/admin`, CSP headers

What's deliberately **not** built yet:

- The AI ask box is structurally present on the homepage but **the submit button is disabled** — wiring it to xAI is the next coding step
- No civic-data ingestion yet — meetings/bills/reps tables come with the Python workers
- No admin UI yet — submissions sit in the database; the moderation queue lands after the meetings work

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
cp .env.example .env
php migrations/migrate.php
php -S 127.0.0.1:8080 -t public public/index.php
```

Open <http://127.0.0.1:8080/> in your browser. You should see:

- Newspaper masthead with today's date and the locale stamp
- "Ask anything..." box (textarea disabled — xAI wiring is the next step)
- Three editorial cards
- `/voter-info` renders the seeded markdown content
- `/submit-correction` shows a working form (CSRF-protected, rate-limited)
- `/meetings`, `/bills`, `/representatives` show clean "in the works" pages
- `/anything-else` shows a typeset 404

---

## Verify the security plumbing

Once the dev server is running, walk through these to confirm each layer:

1. **CSRF cookie is set.** DevTools → Application → Cookies → `http://127.0.0.1:8080`. There should be a `civic_csrf` cookie with a 64-character hex value, HttpOnly checked, SameSite=Lax.
2. **CSRF blocks tampering.** Submit a correction successfully, then edit the cookie value in DevTools and submit again → 403 "Session expired".
3. **Origin check blocks bots.** From a separate terminal:
   ```bash
   curl -X POST http://127.0.0.1:8080/submit-correction -d "_csrf=x&where=t&what=t" -i
   ```
   → 403 "Origin check failed" (curl doesn't send Origin or Referer).
4. **Rate limit fires at 6.** Submit `/submit-correction` six times in a row → the 6th gets 429 "Slow down" with a `Retry-After` header. The limit is currently 5 / hour / IP.
5. **No PII at rest.** Open `data/civic.sqlite` in the VS Code SQLite Viewer. Inspect the `submissions` table — `ip_hash` is a SHA-256 string, never a raw IP.

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
                                      and calls view(...)
```

In dev, `php -S` handles both branches in one `cli-server` process. In production, nginx and php-fpm are truly separate processes communicating over a Unix socket.

---

## Project layout

```
hardin-county-civic-tracker/
├── public/                              # nginx web root — only PUBLIC files
│   ├── index.php                        # front controller (the only PHP nginx executes)
│   └── assets/{css,js,img}/
├── src/
│   ├── bootstrap.php                    # env loader + helpers (e, view, ip_hash, md_to_html)
│   ├── db.php                           # PDO singleton + connection pragmas (WAL, FK, busy timeout)
│   ├── router.php                       # route table + handler functions
│   ├── csrf.php                         # double-submit cookie CSRF protection
│   ├── ratelimit.php                    # sliding-window per-IP rate limiting
│   └── views/                           # one .php per view; layout.php wraps all
│       ├── layout.php                   # masthead + footer + meta
│       ├── home.php                     # the ask box + editorial cards
│       ├── voter_info.php               # renders content_pages rows
│       ├── submit_correction.php        # form (CSRF-protected)
│       ├── submit_correction_thanks.php # post-submit confirmation
│       ├── coming_soon.php              # used by /meetings, /bills, /representatives
│       ├── not_found.php                # 404
│       └── error.php                    # 403 / 429 / 422 — used by csrf_guard, rate_limit_guard
├── migrations/
│   ├── migrate.php                      # CLI runner with _migrations state tracking
│   ├── 0001_init.sql                    # jurisdictions, content_pages, submissions, ai_calls
│   ├── 0002_seed_kenton.sql             # Hardin + Kenton + Ohio + initial voter-info copy
│   └── 0003_ratelimit.sql               # rate_limit_events
├── workers/                             # Python cron scripts (added in next steps)
├── data/civic.sqlite                    # the database (git-ignored)
├── deploy/nginx/civic.conf              # production vhost config
├── .env.example                         # template — copy to .env, fill in secrets
├── .editorconfig                        # cross-editor formatting
├── .gitattributes                       # line-ending normalization
├── .gitignore                           # keeps .env and data/ out of version control
├── .vscode/settings.json                # VS Code workspace settings
└── README.md
```

---

## Deploy to Hetzner

1. **VPS:** spin up a CX22 ($4/mo) with Ubuntu 24.04
2. **Tailscale:** `curl -fsSL https://tailscale.com/install.sh | sh && sudo tailscale up`
3. **Cloudflare Tunnel:** install `cloudflared`, run `cloudflared tunnel login`, create a tunnel, point your hostname → `http://localhost:8080`
4. **Server stack:** `sudo apt install -y nginx php8.3-fpm php8.3-sqlite3 unattended-upgrades`
5. **Firewall:** `sudo ufw allow 22 && sudo ufw enable` (only SSH; Cloudflare Tunnel and Tailscale are outbound)
6. **Code:** `git clone` into `/var/www/civic`, `cp .env.example .env`, edit `IP_HASH_SECRET` (`openssl rand -hex 32`)
7. **Permissions:** `sudo chown -R www-data:www-data data/ && sudo chmod 755 data/`
8. **Migrate:** `sudo -u www-data php migrations/migrate.php`
9. **nginx:** `sudo cp deploy/nginx/civic.conf /etc/nginx/sites-available/ && sudo ln -s ../sites-available/civic.conf /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx`

Visit your domain. Done.

---

## Roadmap (in build order)

1. **Wire `/ask` to xAI** — POST handler, CSRF-guarded, rate-limited, cache lookup against `ai_calls.prompt_hash`, streaming response. The killer demo.
2. **Migration `0004_meetings.sql`** — bodies, meetings, agenda_items
3. **Python worker `ingest_meetings.py`** — scrape Kenton city site + Hardin County commissioners, write rows
4. **Python worker `summarize_pending.py`** — find agenda items without summaries, call xAI, cache to `ai_calls`
5. **Migration `0005_bills.sql` + OpenStates ingester** — Ohio bills + state reps for Hardin
6. **Admin moderation queue at `/admin/submissions`** — Tailscale-only, simple list + approve/reject
7. **Petitions** (last — anonymous + spam needs more thought)

---

## Conventions worth knowing if you contribute

- **Escape on output**, never on input. Call `e()` in every view, every echo of dynamic data.
- **Prepared statements only.** `EMULATE_PREPARES = false` is set in `src/db.php`. Never string-concatenate into SQL.
- **Timestamps are `INTEGER` Unix epoch.** Faster index, timezone-agnostic. Default with `unixepoch()`.
- **IPs are hashed** with `IP_HASH_SECRET` before storage. Raw IPs never touch disk.
- **Every state-changing handler** calls `csrf_guard()` then `rate_limit_guard(...)` before any other work. Order matters: CSRF first.
- **Database-level pragmas live in `src/db.php`**, not in migrations. (Learned the hard way: `PRAGMA journal_mode = WAL` can't run inside a transaction.)
- **One coherent change per commit.** Imperative-mood commit messages ("Add X", not "added X" or "X added").
