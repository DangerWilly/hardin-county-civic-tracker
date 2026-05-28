# Personal notes

This file is git-ignored on purpose. It's your scratch pad — commands you use often, things you want to remember, bugs you noticed but didn't fix yet. Don't worry about polishing it; nobody else will see it.

To get started: `cp notes.example.md notes.md` and edit from there.

---

## Run the dev server

```bash
# Activate Python venv (only needed if you'll run workers in this terminal)
source .venv/Scripts/activate

# Start PHP dev server — leave this running in its own terminal
php -S 127.0.0.1:8080 -t public public/index.php
```

Open <http://127.0.0.1:8080> in the browser.

## Apply pending migrations

```bash
php migrations/migrate.php
```

Idempotent — safe to run any time. Tracks applied migrations in the `_migrations` table.

## Run a worker manually

```bash
source .venv/Scripts/activate   # if not already active
python workers/ingest_openstates.py
```

Output to stdout. To save it for review:
```bash
python workers/ingest_openstates.py | tee /tmp/ingest-$(date +%Y%m%d).log
```

## Force a full re-ingest of all OpenStates bills (with versions)

If you change the worker logic and want previously-ingested bills to be re-fetched (e.g. to backfill `bill_versions` for the 400 bills already in the DB), wipe the last successful-run marker so the worker uses its 90-day first-run window:

```bash
python -c "import sqlite3; c=sqlite3.connect('data/civic.sqlite'); c.execute('DELETE FROM worker_runs WHERE worker=\"ingest_openstates\" AND task=\"oh_bills\"'); c.commit()"
python workers/ingest_openstates.py
```

Heads up: takes 10-15 minutes due to the 7-second delay between API requests.

## Inspect the database

Quick row counts:
```bash
php -r "\$pdo = new PDO('sqlite:data/civic.sqlite'); foreach (['jurisdictions','bodies','meetings','officials','bills','bill_actions','bill_versions','submissions'] as \$t) { echo str_pad(\$t, 20) . \$pdo->query(\"SELECT COUNT(*) FROM \$t\")->fetchColumn() . PHP_EOL; }"
```

Most recent worker runs (success/error, item counts):
```bash
php -r "\$pdo = new PDO('sqlite:data/civic.sqlite'); foreach (\$pdo->query('SELECT * FROM worker_runs ORDER BY id DESC LIMIT 10') as \$r) { print_r(\$r); }"
```

Open in SQLite3 Editor extension (VS Code): right-click `data/civic.sqlite` → Open With → SQLite3 Editor.

## Delete a single row (when admin UI doesn't have it yet)

```bash
# List rows first
php -r "\$pdo = new PDO('sqlite:data/civic.sqlite'); foreach (\$pdo->query('SELECT id, title FROM meetings ORDER BY id') as \$r) print_r(\$r);"

# Delete by ID (FK cascade takes care of children)
php -r "(new PDO('sqlite:data/civic.sqlite'))->exec('DELETE FROM meetings WHERE id = 2');"
```

## Reset the admin password

```bash
php tools/set_admin_password.php
# Paste the new ADMIN_PASSWORD_HASH line into .env
# (Keep the existing ADMIN_COOKIE_SECRET unless you want to log out all sessions)
```

## Git workflow

```bash
git status                    # what changed?
git diff                      # what specifically?
git add -A                    # stage everything
git commit -m "message"       # imperative mood: "Add X" not "Added X"
git push                      # to GitHub
git log --oneline             # see history
```

If `git push` rejects (someone else pushed first):
```bash
git pull --rebase
git push
```

## Reset the database (DESTRUCTIVE — only in dev)

If you want to start completely fresh — schema, seed data, but no bills or officials:

```bash
rm -f data/civic.sqlite data/civic.sqlite-wal data/civic.sqlite-shm
php migrations/migrate.php
php tools/set_admin_password.php   # reset admin password too
# Paste hash into .env, then re-ingest:
source .venv/Scripts/activate
python workers/ingest_openstates.py
```

## Common gotchas

- **PHP dev server caches files in memory.** After editing a PHP file, the change applies immediately, but if you touch `bootstrap.php` or change autoloading, restart with Ctrl+C and re-run.
- **Hard refresh after CSS changes** (`Ctrl+Shift+R`) — regular refresh may serve cached old CSS.
- **`source .venv/Scripts/activate`** (forward slashes, no `c:\` prefix) — Windows-style paths in Git Bash get eaten.
- **`.env` should never appear in `git status`.** If it does, stop and check `.gitignore`.

---

## My personal scratch (add things here as you go)

- [ ] thing I noticed:
- [ ] command I want to remember:
- [ ] bug to investigate later:
