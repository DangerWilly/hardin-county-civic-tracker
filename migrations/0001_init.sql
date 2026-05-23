-- 0001_init.sql — initial schema for The Hardin Civic
--
-- Conventions:
--   * timestamps are unix epoch INTEGERs (faster, timezone-agnostic, sortable)
--   * every ingestible record carries a `source` column (manual/scraper/api/user)
--   * we never store raw IPs — we hash them with a server-side secret
--   * jurisdictions are hierarchical via parent_id, so "city → county → state"
--     scales without schema changes when we expand beyond Hardin


-- ---- Geography ----------------------------------------------------------

CREATE TABLE jurisdictions (
    id           INTEGER PRIMARY KEY,
    slug         TEXT    NOT NULL UNIQUE,
    name         TEXT    NOT NULL,
    kind         TEXT    NOT NULL CHECK (kind IN ('city','county','state','federal')),
    parent_id    INTEGER REFERENCES jurisdictions(id) ON DELETE RESTRICT,
    centroid_lat REAL,
    centroid_lng REAL,
    metadata     TEXT,            -- JSON: e.g. {"fips":"39065","ocd_id":"..."}
    created_at   INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX idx_jur_parent ON jurisdictions(parent_id);
CREATE INDEX idx_jur_kind   ON jurisdictions(kind);

-- ---- Editorial / static content ----------------------------------------
-- Voter info pages, FAQs, "how to register," etc. body_md is markdown that
-- we render with a tiny safe parser (see src/bootstrap.php::md_to_html).

CREATE TABLE content_pages (
    id              INTEGER PRIMARY KEY,
    jurisdiction_id INTEGER NOT NULL REFERENCES jurisdictions(id) ON DELETE CASCADE,
    slug            TEXT    NOT NULL,
    title           TEXT    NOT NULL,
    body_md         TEXT    NOT NULL,
    source          TEXT    NOT NULL DEFAULT 'manual'
                            CHECK (source IN ('manual','scraper','api','user')),
    published       INTEGER NOT NULL DEFAULT 0,
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    UNIQUE (jurisdiction_id, slug)
);
CREATE INDEX idx_content_pub ON content_pages(jurisdiction_id, published);

-- ---- User submissions (anonymous, queued for moderation) ----------------
-- Civic data correction: "the meeting time is wrong," "this rep moved on," etc.

CREATE TABLE submissions (
    id          INTEGER PRIMARY KEY,
    kind        TEXT    NOT NULL,        -- meeting / correction / event / tip
    payload     TEXT    NOT NULL,        -- JSON, schema depends on kind
    ip_hash     TEXT    NOT NULL,        -- sha256(ip + secret), never raw IP
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','approved','rejected','spam')),
    reviewer_note TEXT,
    reviewed_at INTEGER,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX idx_subs_status ON submissions(status, created_at);

-- ---- AI call log --------------------------------------------------------
-- Every xAI call (chat OR batch summarization) writes a row here. Cost
-- accounting, abuse detection, and a cache for repeated questions all
-- read from this table. Rate limiting joins against ip_hash.

CREATE TABLE ai_calls (
    id          INTEGER PRIMARY KEY,
    purpose     TEXT    NOT NULL,        -- chat / agenda_summary / bill_summary
    ip_hash     TEXT,                    -- null for batch jobs (cron)
    prompt_hash TEXT    NOT NULL,        -- sha256 of normalized prompt -> cache key
    prompt      TEXT    NOT NULL,
    response    TEXT,
    model       TEXT,
    tokens_in   INTEGER,
    tokens_out  INTEGER,
    cost_cents  INTEGER,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX idx_ai_purpose      ON ai_calls(purpose, created_at);
CREATE INDEX idx_ai_prompt_hash  ON ai_calls(prompt_hash);
CREATE INDEX idx_ai_ip_recent    ON ai_calls(ip_hash, created_at);
