# The Hardin Civic — v1

Independent civic information for Hardin County and the City of Kenton, Ohio.

Vanilla PHP + SQLite + nginx. Designed to run on a $4 Hetzner VPS behind a
Cloudflare Tunnel (no public IP exposed). No Composer. No build step. No
JavaScript framework.

---

## What's in v1

- **Front-controller routing** — every request flows through `public/index.php`
- **SQLite + a tiny migration runner** with state tracking
- **Newspaper-aesthetic homepage** (Fraunces serif + Public Sans, oxblood + cream)
- **Real `/voter-info` page** rendered from the `content_pages` table via a tiny safe markdown parser
- **Stub views** for `/meetings`, `/bills`, `/representatives` so the masthead nav already works end-to-end
- **`/healthz`** for monitoring
- **Production-grade nginx config** with Cloudflare IP forwarding, Tailscale-only `/admin`, CSP headers

What's deliberately **not** in v1:

- The AI ask box is wired into the UI but **submit is disabled** — that's the next coding step
- No data ingestion yet — the meeting/bill/rep tables don't exist yet (added in step 4)
- No CSRF middleware yet — added when the first POST endpoint lands

---

## Run it locally (5 minutes)

```bash
# Ubuntu / Debian
sudo apt install -y php8.3-cli php8.3-sqlite3

# In the project root:
cp .env.example .env
php migrations/migrate.php

# PHP's built-in dev server (DEV ONLY — never expose to the internet)
php -S 127.0.0.1:8080 -t public public/index.php
```

Open http://127.0.0.1:8080/ in a phone-sized browser window. You should see:

- A newspaper masthead with today's date
- A big "Ask anything..." box (textarea disabled — wiring is step 3)
- Three editorial cards
- `/voter-info` renders the seeded markdown content
- `/meetings`, `/bills`, `/representatives` render clean "in the works" pages
- `/anything-else` renders a typeset 404

The router function in `src/router.php` handles all of these — that's the
core pattern to internalize.

---

## How requests flow

```
Browser  →  Cloudflare Tunnel  →  nginx (127.0.0.1:8080)
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

## Deploy to Hetzner

1. **VPS:** spin up a CX22 ($4/mo) with Ubuntu 24.04
2. **Tailscale:** `curl -fsSL https://tailscale.com/install.sh | sh && sudo tailscale up`
3. **Cloudflare Tunnel:** install `cloudflared`, run `cloudflared tunnel login`, create a tunnel, point hostname → `http://localhost:8080`
4. **Server:** `sudo apt install -y nginx php8.3-fpm php8.3-sqlite3 unattended-upgrades`
5. **Firewall:** `sudo ufw allow 22 && sudo ufw enable` (ONLY ssh — Cloudflare Tunnel and Tailscale are outbound, no inbound ports needed)
6. **Code:** `git clone` into `/var/www/civic`, `cp .env.example .env`, edit `IP_HASH_SECRET` (`openssl rand -hex 32`)
7. **Permissions:** `sudo chown -R www-data:www-data data/ && sudo chmod 755 data/`
8. **Migrate:** `sudo -u www-data php migrations/migrate.php`
9. **nginx:** `sudo cp deploy/nginx/civic.conf /etc/nginx/sites-available/ && sudo ln -s ../sites-available/civic.conf /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx`

Visit your domain. Done.

---

## Next steps (in the order I'd do them)

1. **Wire `/ask` to xAI** — add a POST handler, call the xAI completions endpoint, stream tokens back. Cache by `prompt_hash` against `ai_calls`. **This is the killer demo.**
2. **CSRF middleware** — needed before any other POST endpoint
3. **Migration `0003_meetings.sql`** — bodies, meetings, agenda_items
4. **Python ingester** — `workers/ingest_meetings.py` scrapes Kenton city site, writes to SQLite
5. **`workers/summarize_pending.py`** — finds agenda items without summaries, calls xAI, caches
6. **OpenStates ingester** — bills + reps for Ohio
7. **Submission moderation queue** at `/admin/submissions` (Tailscale-only)
8. **Petitions** (last — needs more anti-spam thought when anonymous)

---

## Project layout

```
civic/
├── public/                     # nginx web root — only PUBLIC files live here
│   ├── index.php               # the only PHP file nginx executes directly
│   └── assets/{css,js,img}/
├── src/
│   ├── bootstrap.php           # env, helpers (e, view, ip_hash, md_to_html)
│   ├── db.php                  # PDO singleton with the right pragmas
│   ├── router.php              # route table + handlers
│   └── views/                  # one .php per view, layout.php wraps all
├── migrations/
│   ├── migrate.php             # CLI runner
│   ├── 0001_init.sql           # schema
│   └── 0002_seed_kenton.sql    # initial jurisdictions + voter-info copy
├── workers/                    # Python cron scripts (added in step 4)
├── data/civic.sqlite           # the database (git-ignored)
├── deploy/nginx/civic.conf
├── .env.example
└── README.md
```
