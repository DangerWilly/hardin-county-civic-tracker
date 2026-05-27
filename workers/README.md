# Workers

Python jobs that pull civic data from external sources into the project's SQLite. Each worker is a self-contained script in this directory.

The PHP web app and the Python workers share **one file** — `data/civic.sqlite` — and nothing else. No shared code, no shared process, no shared HTTP. This is the Unix philosophy applied to a small civic site: small programs, communicating via files.

## Current workers

| Script | What it does | Schedule |
|---|---|---|
| `ingest_openstates.py` | Pulls OH General Assembly members + recently-updated OH bills + their actions/sponsorships | Daily at 4:00 AM Eastern |

## One-time setup

From the project root (not from `workers/`):

```bash
# Create a virtual environment so deps don't pollute your system Python
python -m venv .venv

# Activate it
source .venv/Scripts/activate     # Windows Git Bash
# OR
source .venv/bin/activate         # macOS / Linux

# Install dependencies
pip install -r workers/requirements.txt
```

You'll know it worked when `which python` reports a path inside `.venv/`. The activation persists for the current shell; close the terminal and you'll need to re-activate next time.

## Run a worker manually

```bash
python workers/ingest_openstates.py
```

Output goes to stdout, one line per significant event. Save it to a file for review:

```bash
python workers/ingest_openstates.py | tee /tmp/ingest-$(date +%Y%m%d).log
```

## How the workers stay safe

- **Idempotent.** Running a worker twice in a row produces the same database state. Each external record has a stable identifier (OpenStates' `id` field, stored as `openstates_id`) that we use as the join key for INSERT-or-UPDATE.
- **Incremental.** The bills task asks the API only for records updated since our last successful run, recorded in the `worker_runs` table. First-ever run pulls the last 90 days as a safety cap.
- **Rate-aware.** 200ms delay between requests, polite handling of HTTP 429 (sleeps for the `Retry-After` header), exponential backoff on 5xx.
- **Audited.** Every run writes a row to `worker_runs` with start/end times, item counts, and any error message. Check the most recent runs:
  ```bash
  sqlite3 data/civic.sqlite "SELECT * FROM worker_runs ORDER BY id DESC LIMIT 10;"
  ```

## Deploying to the Hetzner VPS

Once the workers are live in your repo:

```bash
# On the server, in /var/www/civic
sudo apt install -y python3-venv
sudo -u www-data python3 -m venv .venv
sudo -u www-data .venv/bin/pip install -r workers/requirements.txt
sudo mkdir -p /var/log/civic
sudo chown www-data:www-data /var/log/civic

# Install the cron schedule
sudo cp deploy/cron/civic.cron /etc/cron.d/civic
sudo chmod 644 /etc/cron.d/civic
sudo chown root:root /etc/cron.d/civic
```

You can verify the cron syntax with:

```bash
sudo crontab -u www-data -l           # not used — we use /etc/cron.d
ls -la /etc/cron.d/civic
```

To run the worker manually as the production user (good first-deploy smoke test):

```bash
sudo -u www-data /var/www/civic/.venv/bin/python /var/www/civic/workers/ingest_openstates.py
```

## When something breaks

1. **Check the log:** `tail -100 /var/log/civic/ingest.log`
2. **Check the audit table:** the last few rows of `worker_runs` will show `status='error'` and `error_message`
3. **Re-run manually:** workers are idempotent, so running them by hand is safe
