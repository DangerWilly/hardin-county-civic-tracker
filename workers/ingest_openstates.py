#!/usr/bin/env python3
"""
workers/ingest_openstates.py — pull Ohio legislators and bills from the
Open States API v3 and upsert them into our SQLite.

Run manually:
    .venv/bin/python workers/ingest_openstates.py

Run from cron (daily at 4am Eastern — see deploy/cron/civic.cron):
    0 4 * * * cd /var/www/civic && .venv/bin/python workers/ingest_openstates.py >> /var/log/civic/ingest.log 2>&1

DESIGN
------

Three tasks, each wrapped in its own `record_run()`:

  1. oh_legislators   — full sweep of OH General Assembly members.
                        Run weekly is plenty; we still run nightly because
                        the API is cheap and it's simpler not to special-case.

  2. oh_bills         — recently-updated OH bills, paginated.
                        Uses updated_since=<last successful run> so we
                        only fetch what changed. This is the file you'll
                        hit the API rate limit with if you're going to.

  3. oh_bill_actions  — for each bill touched above, fetch its full action
                        history. (OpenStates returns actions inline via
                        ?include=actions, so this is folded into task 2.)

IDEMPOTENCY
-----------

Every record from the API carries an `id` field — the OpenStates internal
identifier, looks like `ocd-person/abc-123` or `ocd-bill/def-456`. We store
that as `openstates_id` and use it as the primary join key:

    INSERT INTO X (...) VALUES (...)
    ON CONFLICT(openstates_id) DO UPDATE SET ...

This means running the worker 100 times in a row converges on the same DB
state. The first run inserts everything; subsequent runs only UPDATE rows
whose API data has actually changed. Counters in worker_runs.items_changed
track that, which is useful when debugging "did anything happen?"

RATE LIMITING
-------------

Free tier: ~500 requests/day, ~10/min. The worker:
  * Sleeps 200ms between requests (5/sec; under the per-minute limit even
    if we sustain it)
  * On HTTP 429, sleeps for the Retry-After header (or 60s default) and retries
  * On other 5xx errors, retries up to 3 times with exponential backoff
  * Logs every API call URL so you can audit volume from the log file
"""
from __future__ import annotations

import json
import sys
import time
from typing import Any, Iterator

import requests

# Allow `python workers/ingest_openstates.py` from project root OR
# `python ingest_openstates.py` from inside workers/.
sys.path.insert(0, str((__import__("pathlib").Path(__file__).resolve().parent)))
from _db import (
    connect, env, log, record_run, last_successful_run, die,
)

OPENSTATES_BASE = "https://v3.openstates.org"
OH_JURISDICTION_ID = "ocd-jurisdiction/country:us/state:oh/government"
# OpenStates free tier is ~10 requests/minute. 7 seconds between requests
# keeps us safely under that even with clock drift; we'd rather take 10
# minutes for a first run than burn the per-minute limit and have to wait.
# On subsequent (incremental) runs this delay barely matters because we
# only make 1-3 requests total.
REQUEST_DELAY_SECONDS = 7.0
USER_AGENT = "hardin-county-civic-tracker (github.com/DangerWilly/hardin-county-civic-tracker)"


def http_get(path: str, params: dict[str, Any] | None = None) -> dict:
    """
    Authenticated GET against OpenStates with retries + rate-limit handling.
    """
    api_key = env("OPENSTATES_API_KEY")
    if not api_key:
        die("OPENSTATES_API_KEY is not set in .env")

    url = f"{OPENSTATES_BASE}{path}"
    headers = {"X-API-Key": api_key, "User-Agent": USER_AGENT}
    last_err = None

    for attempt in range(1, 4):
        log(f"GET {path}  params={params or {}}")
        time.sleep(REQUEST_DELAY_SECONDS)

        try:
            r = requests.get(url, params=params, headers=headers, timeout=30)
        except requests.RequestException as e:
            last_err = e
            log(f"  network error: {e}  attempt {attempt}/3")
            time.sleep(2 ** attempt)
            continue

        if r.status_code == 200:
            return r.json()

        if r.status_code == 429:
            wait = int(r.headers.get("Retry-After", "60"))
            log(f"  429 rate-limited; sleeping {wait}s then retrying")
            time.sleep(wait)
            continue

        if 500 <= r.status_code < 600:
            log(f"  HTTP {r.status_code}; backoff and retry")
            time.sleep(2 ** attempt)
            last_err = RuntimeError(f"server {r.status_code}: {r.text[:200]}")
            continue

        # 4xx (not 429) — almost certainly our fault, don't retry
        raise RuntimeError(
            f"OpenStates API returned {r.status_code} for {path}: {r.text[:500]}"
        )

    raise RuntimeError(f"OpenStates GET {path} failed after 3 attempts: {last_err}")


def paginated(path: str, params: dict[str, Any]) -> Iterator[dict]:
    """
    Yield every item from a paginated OpenStates endpoint.
    OpenStates uses page/per_page query params and returns
    {"results": [...], "pagination": {"page": N, "max_page": M, ...}}.
    """
    params = dict(params)
    params.setdefault("per_page", 20)            # OpenStates max per page
    params["page"] = 1

    while True:
        data = http_get(path, params)
        for item in data.get("results", []):
            yield item
        page_info = data.get("pagination", {})
        if params["page"] >= page_info.get("max_page", 1):
            return
        params["page"] += 1


# ---------------------------------------------------------------------------
# Task: legislators
# ---------------------------------------------------------------------------

def task_legislators() -> None:
    """Sweep all current Ohio General Assembly members into officials."""
    with record_run("ingest_openstates", "oh_legislators") as run:
        conn = connect()
        oh_id = conn.execute(
            "SELECT id FROM jurisdictions WHERE slug='oh'"
        ).fetchone()
        if not oh_id:
            die("Ohio jurisdiction not found — has migrations/0002 been applied?")
        oh_db_id = oh_id["id"]

        items_seen = 0
        items_changed = 0

        for person in paginated("/people", {
            "jurisdiction": OH_JURISDICTION_ID,
        }):
            items_seen += 1
            changed = upsert_official(conn, person, oh_db_id)
            if changed:
                items_changed += 1

        conn.close()
        run["items_seen"] = items_seen
        run["items_changed"] = items_changed
        log(f"legislators: seen={items_seen} changed={items_changed}")


def upsert_official(conn, person: dict, oh_jur_id: int) -> bool:
    """
    Upsert one person record. Returns True if INSERT or UPDATE actually
    changed anything (lets us count meaningful churn vs. no-ops).
    """
    ocd_id        = person.get("id")
    full_name     = person.get("name") or "Unknown"
    party         = (person.get("party") or "").strip()[:1] or None
    photo_url     = person.get("image") or None

    # `current_role` is the active legislator role for this session
    role = person.get("current_role") or {}
    chamber          = role.get("org_classification")          # 'upper' / 'lower'
    title            = role.get("title")                       # 'Senator','Representative'
    current_district = str(role.get("district") or "")
    if not title:
        title = "Senator" if chamber == "upper" else (
            "Representative" if chamber == "lower" else "Legislator")

    # OpenStates returns 'links' (websites) and 'offices' (phones/emails)
    website_url = next(
        (l.get("url") for l in (person.get("links") or [])
         if l.get("note") in ("homepage", None) and l.get("url")),
        None,
    )
    contact_phone = None
    contact_email = person.get("email") or None
    office_address = None
    for office in person.get("offices") or []:
        if office.get("voice"):
            contact_phone = office["voice"]
        if office.get("address"):
            office_address = office["address"]
        if contact_phone and office_address:
            break

    slug = slugify(full_name)

    # Try INSERT; on conflict over openstates_id, UPDATE.
    row = conn.execute(
        "SELECT * FROM officials WHERE openstates_id = ?",
        (ocd_id,),
    ).fetchone()

    if row is None:
        conn.execute("""
            INSERT INTO officials
                (jurisdiction_id, slug, full_name, title, party,
                 photo_url, contact_email, contact_phone, office_address,
                 website_url, openstates_id, ocd_id, current_district,
                 chamber, source, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'api', unixepoch())
        """, (oh_jur_id, slug, full_name, title, party,
              photo_url, contact_email, contact_phone, office_address,
              website_url, ocd_id, ocd_id, current_district, chamber))
        log(f"  + official  {full_name} ({title} {current_district})")
        return True

    # UPDATE only if a tracked field changed
    fields = {
        "full_name": full_name, "title": title, "party": party,
        "photo_url": photo_url, "contact_email": contact_email,
        "contact_phone": contact_phone, "office_address": office_address,
        "website_url": website_url, "current_district": current_district,
        "chamber": chamber,
    }
    if all(row[k] == v for k, v in fields.items()):
        return False                            # unchanged, no UPDATE needed

    set_clause = ", ".join(f"{k}=?" for k in fields) + ", updated_at=unixepoch()"
    values = list(fields.values()) + [row["id"]]
    conn.execute(f"UPDATE officials SET {set_clause} WHERE id=?", values)
    log(f"  ~ official  {full_name}")
    return True


# ---------------------------------------------------------------------------
# Task: bills + actions + sponsorships
# ---------------------------------------------------------------------------

def task_bills() -> None:
    """Pull recently-updated OH bills (incremental via updated_since)."""
    with record_run("ingest_openstates", "oh_bills") as run:
        conn = connect()
        oh_id_row = conn.execute(
            "SELECT id FROM jurisdictions WHERE slug='oh'"
        ).fetchone()
        oh_db_id = oh_id_row["id"]

        # Incremental: only ask the API for bills updated since our last
        # successful run. First-ever run will fetch everything, which can
        # be a lot — see safety cap below.
        last_run = last_successful_run("ingest_openstates", "oh_bills")
        if last_run:
            since = time.strftime("%Y-%m-%dT%H:%M:%S",
                                  time.gmtime(last_run - 3600))  # 1h overlap
            log(f"bills: incremental since {since}")
        else:
            # First run: only the last 90 days, to keep within rate limits.
            since = time.strftime("%Y-%m-%dT%H:%M:%S",
                                  time.gmtime(time.time() - 86400 * 90))
            log(f"bills: FIRST RUN — fetching since {since} (last 90 days)")

        params = {
            "jurisdiction":   OH_JURISDICTION_ID,
            "updated_since":  since,
            "sort":           "updated_desc",
            # OpenStates' API wants `include` as REPEATED query params, not a
            # comma-joined string. requests serializes a list as
            # ?include=sponsorships&include=actions, which matches what the
            # API validates against its enum.
            "include":        ["sponsorships", "actions", "versions"],
        }

        items_seen = 0
        items_changed = 0
        SAFETY_CAP = 400                        # don't blow the rate limit

        for bill in paginated("/bills", params):
            items_seen += 1
            if items_seen > SAFETY_CAP:
                log(f"  hit safety cap of {SAFETY_CAP} bills; stopping early")
                run["notes"] = f"hit safety cap at {SAFETY_CAP} bills"
                break
            if upsert_bill(conn, bill, oh_db_id):
                items_changed += 1

        conn.close()
        run["items_seen"] = items_seen
        run["items_changed"] = items_changed
        log(f"bills: seen={items_seen} changed={items_changed}")


def upsert_bill(conn, bill: dict, oh_jur_id: int) -> bool:
    ocd_id        = bill["id"]
    identifier    = bill.get("identifier") or ""
    title         = bill.get("title") or "(no title)"
    session       = (bill.get("session") or "")[:20]
    classification = (bill.get("classification") or [None])[0]
    subject_json  = json.dumps(bill.get("subject") or [])
    abstract      = None
    if bill.get("abstracts"):
        abstract = bill["abstracts"][0].get("abstract")

    actions = bill.get("actions") or []
    action_dates = [parse_iso(a.get("date")) for a in actions]
    action_dates = [d for d in action_dates if d is not None]
    first_action = min(action_dates) if action_dates else None
    last_action  = max(action_dates) if action_dates else None

    openstates_url = bill.get("openstates_url") or None
    source_url = next((s.get("url") for s in bill.get("sources") or [] if s.get("url")), None)

    row = conn.execute("SELECT id FROM bills WHERE openstates_id=?", (ocd_id,)).fetchone()
    if row is None:
        cur = conn.execute("""
            INSERT INTO bills
                (jurisdiction_id, openstates_id, identifier, title, classification,
                 session, subject, abstract, first_action_at, last_action_at,
                 openstates_url, source_url, source, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'api', unixepoch())
        """, (oh_jur_id, ocd_id, identifier, title, classification, session,
              subject_json, abstract, first_action, last_action,
              openstates_url, source_url))
        bill_id = cur.lastrowid
        log(f"  + bill  {identifier}  {title[:70]}")
    else:
        bill_id = row["id"]
        conn.execute("""
            UPDATE bills
               SET identifier=?, title=?, classification=?, session=?, subject=?,
                   abstract=?, first_action_at=?, last_action_at=?,
                   openstates_url=?, source_url=?, updated_at=unixepoch()
             WHERE id=?
        """, (identifier, title, classification, session, subject_json,
              abstract, first_action, last_action,
              openstates_url, source_url, bill_id))
        log(f"  ~ bill  {identifier}")

    # Replace actions + sponsorships wholesale. They're cheap to rewrite and
    # this guarantees the DB matches the source of truth.
    conn.execute("DELETE FROM bill_actions WHERE bill_id=?", (bill_id,))
    for i, a in enumerate(actions):
        when = parse_iso(a.get("date"))
        if when is None: continue
        conn.execute("""
            INSERT INTO bill_actions
                (bill_id, acted_on, organization, description, classification, "order")
            VALUES (?, ?, ?, ?, ?, ?)
        """, (bill_id, when,
              (a.get("organization") or {}).get("name"),
              a.get("description") or "",
              (a.get("classification") or [None])[0],
              a.get("order", i)))

    conn.execute("DELETE FROM bill_sponsorships WHERE bill_id=?", (bill_id,))
    for s in bill.get("sponsorships") or []:
        sponsor_name = s.get("name") or ""
        if not sponsor_name: continue
        classification = "primary" if s.get("primary") else "cosponsor"
        # Try to link to a known official by OCD ID
        official_id = None
        if s.get("person") and s["person"].get("id"):
            link = conn.execute(
                "SELECT id FROM officials WHERE openstates_id=?",
                (s["person"]["id"],),
            ).fetchone()
            if link: official_id = link["id"]
        try:
            conn.execute("""
                INSERT INTO bill_sponsorships (bill_id, official_id, sponsor_name, classification)
                VALUES (?, ?, ?, ?)
            """, (bill_id, official_id, sponsor_name, classification))
        except Exception:
            pass                                # duplicate sponsor_name on same bill — ignore

    # Versions: delete-and-replace, same pattern as actions/sponsorships.
    # OpenStates emits each version with one or more links; we pick the
    # best link and store one row per version. Preferred link order is
    # PDF first (most stable, what people actually read), HTML second,
    # whatever's left third.
    conn.execute("DELETE FROM bill_versions WHERE bill_id=?", (bill_id,))
    for i, v in enumerate(bill.get("versions") or []):
        note = (v.get("note") or "").strip()
        if not note:
            continue
        issued_at = parse_iso(v.get("date"))
        # Pick the best link from the version's `links` array
        links = v.get("links") or []
        chosen_url = None
        chosen_type = None
        for media_pref in ("application/pdf", "text/html"):
            for link in links:
                if link.get("media_type") == media_pref and link.get("url"):
                    chosen_url, chosen_type = link["url"], media_pref
                    break
            if chosen_url:
                break
        # Fallback: first link with any URL
        if not chosen_url and links:
            chosen_url = links[0].get("url")
            chosen_type = links[0].get("media_type")
        try:
            conn.execute("""
                INSERT INTO bill_versions
                    (bill_id, note, issued_at, url, media_type, "order")
                VALUES (?, ?, ?, ?, ?, ?)
            """, (bill_id, note, issued_at, chosen_url, chosen_type, i))
        except Exception:
            pass                                # UNIQUE conflict — already replaced above

    return True                                 # we touched the row


# ---------------------------------------------------------------------------
# Utilities
# ---------------------------------------------------------------------------

def parse_iso(s: str | None) -> int | None:
    """
    Parse an ISO-ish date string into unix epoch.
    OpenStates emits a mix of formats: "2026-01-15T10:00:00",
    "2026-01-15T10:00:00Z", "2026-01-15", sometimes with timezone offset.
    We try the most common forms and return None if all fail.
    """
    if not s or not isinstance(s, str):
        return None
    # Strip trailing Z (UTC marker — treat as UTC)
    s_clean = s.rstrip("Z")
    # Strip timezone offset if present ("+00:00", "-05:00")
    if len(s_clean) >= 6 and s_clean[-6] in "+-" and s_clean[-3] == ":":
        s_clean = s_clean[:-6]
    for fmt in ("%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M:%S", "%Y-%m-%d"):
        try:
            t = time.strptime(s_clean, fmt)
            return int(time.mktime(t))
        except ValueError:
            continue
    return None


def slugify(name: str) -> str:
    import re
    s = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
    return s[:80] or "official"


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    log("ingest_openstates start")
    if not env("OPENSTATES_API_KEY"):
        die("OPENSTATES_API_KEY missing from .env")

    try:
        task_legislators()
    except Exception as e:
        log(f"legislators task failed: {e}")

    try:
        task_bills()
    except Exception as e:
        log(f"bills task failed: {e}")

    log("ingest_openstates done")
    return 0


if __name__ == "__main__":
    sys.exit(main())
