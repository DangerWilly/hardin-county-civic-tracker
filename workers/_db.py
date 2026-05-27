"""
workers/_db.py — shared helpers used by every Python worker.

The Python and PHP halves of this project are completely independent
programs that happen to share one SQLite file. For them to coexist without
weird locking issues or data corruption, they must agree on a few things:

  * WAL journal mode (set once on the file; persists)
  * Foreign keys on (per-connection, both sides set it)
  * A busy timeout long enough that a write from one side waits politely
    while the other commits

This module centralizes that. Every worker uses `connect()` instead of
calling `sqlite3.connect` directly.

Also lives here: env loading (reads the project .env so workers find the
API key), and `record_run()` for writing into the `worker_runs` table.
"""
from __future__ import annotations

import contextlib
import os
import sqlite3
import sys
import time
from pathlib import Path
from typing import Iterator, Any

from dotenv import load_dotenv

# Project layout:  <root>/workers/_db.py  ->  <root>/data/civic.sqlite
PROJECT_ROOT = Path(__file__).resolve().parent.parent
DB_PATH = PROJECT_ROOT / "data" / "civic.sqlite"
ENV_PATH = PROJECT_ROOT / ".env"

# Load .env. Workers run from cron with a minimal environment, so we can't
# rely on the shell having sourced it for us.
if ENV_PATH.is_file():
    load_dotenv(ENV_PATH)


def connect() -> sqlite3.Connection:
    """Return a SQLite connection with the project's standard pragmas applied."""
    if not DB_PATH.parent.is_dir():
        DB_PATH.parent.mkdir(parents=True, exist_ok=True)

    conn = sqlite3.connect(
        DB_PATH,
        isolation_level=None,          # autocommit; we manage transactions manually
        timeout=30,                    # connection-level busy timeout, in seconds
    )
    conn.row_factory = sqlite3.Row     # access columns by name: row["title"]
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA busy_timeout = 5000")
    # WAL is a database-level setting set by PHP's db.php on first connect.
    # We don't try to set it here — that would either be a no-op (already
    # WAL) or fail (mid-transaction). Just rely on PHP having set it.
    return conn


def env(key: str, default: str | None = None) -> str | None:
    """Read an env var. Strips matching quotes, like our PHP env() does."""
    val = os.environ.get(key, default)
    if isinstance(val, str):
        val = val.strip().strip('"').strip("'")
    return val


def log(*parts: Any) -> None:
    """
    Worker logging. One line per call, prefixed with ISO timestamp. Goes to
    stdout — cron redirects to a log file, you read it with `tail`.
    """
    ts = time.strftime("%Y-%m-%dT%H:%M:%S")
    line = " ".join(str(p) for p in parts)
    print(f"[{ts}] {line}", flush=True)


@contextlib.contextmanager
def record_run(worker: str, task: str) -> Iterator[dict]:
    """
    Wrap a worker task in a `worker_runs` row.

    Usage:
        with record_run('ingest_openstates', 'oh_legislators') as run:
            ...do work...
            run['items_seen'] = 132
            run['items_changed'] = 3

    On exception, the row is updated to status='error' with the message.
    On clean exit, status='success' and the counters get persisted.
    """
    conn = connect()
    cur = conn.execute(
        "INSERT INTO worker_runs (worker, task, started_at, status) "
        "VALUES (?, ?, ?, 'running')",
        (worker, task, int(time.time())),
    )
    run_id = cur.lastrowid
    state = {"items_seen": 0, "items_changed": 0, "notes": None}

    try:
        yield state
    except Exception as e:
        conn.execute(
            "UPDATE worker_runs SET status='error', error_message=?, "
            "finished_at=?, items_seen=?, items_changed=?, notes=? "
            "WHERE id=?",
            (str(e)[:2000], int(time.time()),
             state["items_seen"], state["items_changed"], state["notes"], run_id),
        )
        conn.close()
        raise
    else:
        conn.execute(
            "UPDATE worker_runs SET status='success', finished_at=?, "
            "items_seen=?, items_changed=?, notes=? WHERE id=?",
            (int(time.time()), state["items_seen"],
             state["items_changed"], state["notes"], run_id),
        )
        conn.close()


def last_successful_run(worker: str, task: str) -> int | None:
    """
    Return the unix epoch of the last successful finish for (worker, task),
    or None if there's never been one. Used to compute `updated_since` for
    incremental API fetches.
    """
    conn = connect()
    try:
        row = conn.execute(
            "SELECT finished_at FROM worker_runs "
            "WHERE worker=? AND task=? AND status='success' "
            "ORDER BY finished_at DESC LIMIT 1",
            (worker, task),
        ).fetchone()
        return int(row["finished_at"]) if row else None
    finally:
        conn.close()


def die(msg: str, code: int = 1) -> None:
    """Print to stderr and exit. For fatal config errors."""
    print(f"FATAL: {msg}", file=sys.stderr)
    sys.exit(code)
