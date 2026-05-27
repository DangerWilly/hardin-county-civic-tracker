-- 0006_legislation.sql — legislative bill tracking, populated by the
-- OpenStates ingester worker.
--
-- DESIGN NOTES
--
-- 1. We add `openstates_id` (text) and `ocd_id` (text) to the existing
--    `officials` table. These are the OpenStates and Open Civic Data unique
--    identifiers respectively. They're the "join key" that makes the
--    worker idempotent: if a record already exists with the same
--    openstates_id, we UPDATE it. Otherwise we INSERT.
--
-- 2. `bills` lives outside `jurisdictions` for now — we associate via
--    `jurisdiction_id` directly. Future: if we ingest from multiple
--    sources (Congress.gov for federal, etc.) we might add a `source`
--    column. For now everything coming through OpenStates is implicitly
--    OH state legislature.
--
-- 3. `bill_actions` is a child table — chronological list of what happened
--    to each bill (introduced, referred to committee, passed Senate, etc.).
--    Sorted by `acted_on` for display.
--
-- 4. `bill_sponsorships` is a junction between bills and officials, with
--    extra columns for primary vs. cosponsor and the sponsor's name as it
--    appeared on the bill (which may not exactly match the official's
--    canonical name — divorces, marriages, hyphens, etc.).
--
-- 5. We keep `summary_md` on `bills` ready for AI summaries the same way
--    `meetings.summary` works. Empty until the summarize worker fills it.

-- ---- OpenStates ID columns on existing officials -----------------------
-- SQLite ALTER TABLE only allows ADD COLUMN (no DROP/MODIFY pre-3.35).
-- These are nullable since we may also enter officials manually via admin
-- UI without an OpenStates record.

ALTER TABLE officials ADD COLUMN openstates_id TEXT;
ALTER TABLE officials ADD COLUMN ocd_id        TEXT;
ALTER TABLE officials ADD COLUMN current_district TEXT;
ALTER TABLE officials ADD COLUMN chamber       TEXT;          -- 'upper', 'lower', or NULL

CREATE INDEX idx_officials_openstates ON officials(openstates_id);
CREATE INDEX idx_officials_ocd        ON officials(ocd_id);


-- ---- Bills --------------------------------------------------------------

CREATE TABLE bills (
    id              INTEGER PRIMARY KEY,
    jurisdiction_id INTEGER NOT NULL REFERENCES jurisdictions(id) ON DELETE RESTRICT,
    openstates_id   TEXT    NOT NULL UNIQUE,           -- the join key (e.g. ocd-bill/abc-123)
    identifier      TEXT    NOT NULL,                  -- "HB 14", "SB 200" — how Ohioans refer to it
    title           TEXT    NOT NULL,
    classification  TEXT,                              -- 'bill', 'resolution', 'joint resolution', etc.
    session         TEXT    NOT NULL,                  -- "135", "136" — Ohio General Assembly session
    subject         TEXT,                              -- JSON array of subject tags
    abstract        TEXT,                              -- short summary if OpenStates provides one

    -- AI-cached, populated later by summarize worker
    summary_md      TEXT,
    summary_model   TEXT,
    summary_at      INTEGER,

    -- Tracking
    first_action_at INTEGER,                           -- unix epoch of first action (introduction)
    last_action_at  INTEGER,                           -- unix epoch of most recent action
    openstates_url  TEXT,                              -- public OpenStates.org page
    source_url      TEXT,                              -- the legislature's own page for this bill
    source          TEXT    NOT NULL DEFAULT 'api'
                            CHECK (source IN ('manual','scraper','api','user')),

    -- Audit
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX idx_bills_jur_session ON bills(jurisdiction_id, session);
CREATE INDEX idx_bills_last_action ON bills(last_action_at DESC);
CREATE INDEX idx_bills_identifier  ON bills(identifier);


-- ---- Bill actions -------------------------------------------------------
-- Chronological log per bill: introduced, referred, passed, signed, etc.

CREATE TABLE bill_actions (
    id           INTEGER PRIMARY KEY,
    bill_id      INTEGER NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
    acted_on     INTEGER NOT NULL,                     -- unix epoch
    organization TEXT,                                 -- "Ohio Senate", "House Finance Committee"
    description  TEXT    NOT NULL,                     -- "Introduced", "Referred to committee", etc.
    classification TEXT,                               -- 'introduction','referral','passage', etc.
    "order"      INTEGER NOT NULL DEFAULT 0            -- OpenStates' display order within the bill
);
CREATE INDEX idx_actions_bill_time ON bill_actions(bill_id, acted_on);


-- ---- Bill sponsorships --------------------------------------------------
-- Junction table linking bills to officials. We DON'T require official_id
-- to be non-null because OpenStates may name a sponsor we haven't yet
-- ingested as an official, in which case we still keep the sponsor's name
-- as a string and try to backfill the linkage later.

CREATE TABLE bill_sponsorships (
    id           INTEGER PRIMARY KEY,
    bill_id      INTEGER NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
    official_id  INTEGER REFERENCES officials(id) ON DELETE SET NULL,
    sponsor_name TEXT    NOT NULL,                     -- as printed on the bill
    classification TEXT  NOT NULL DEFAULT 'cosponsor', -- 'primary','cosponsor'
    UNIQUE (bill_id, sponsor_name)
);
CREATE INDEX idx_sponsorships_official ON bill_sponsorships(official_id);


-- ---- Worker state log ---------------------------------------------------
-- Every worker run inserts a row here at start and updates it at end.
-- Used by the worker to find "what's the last successful run for this
-- task?" so we can pass updated_since to the API.

CREATE TABLE worker_runs (
    id           INTEGER PRIMARY KEY,
    worker       TEXT    NOT NULL,                     -- 'ingest_openstates','summarize_pending', etc.
    task         TEXT    NOT NULL,                     -- 'oh_legislators','oh_bills', etc.
    started_at   INTEGER NOT NULL,
    finished_at  INTEGER,
    status       TEXT    NOT NULL DEFAULT 'running'
                         CHECK (status IN ('running','success','error')),
    items_seen   INTEGER NOT NULL DEFAULT 0,
    items_changed INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    notes        TEXT
);
CREATE INDEX idx_runs_worker_task_time ON worker_runs(worker, task, started_at DESC);
