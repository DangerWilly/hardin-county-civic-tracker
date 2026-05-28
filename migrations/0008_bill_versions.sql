-- 0008_bill_versions.sql — store each version of a bill's text as a row.
--
-- WHY A SEPARATE TABLE
--
-- A single bill goes through revisions as it moves through the legislature.
-- OpenStates emits these as a `versions` array on each bill, each with:
--   - a note ("As Introduced", "As Passed Senate", "As Re-Reported", ...)
--   - a date
--   - one or more links to the actual text (HTML, PDF)
--
-- We could cram them into the bills row as a JSON column. We don't,
-- because:
--   1. We want to display them as a timeline on the detail page —
--      individual rows are far easier to ORDER BY than parsed JSON
--   2. Future: each version becomes summarizable independently. When the
--      AI worker eventually summarizes bills, it'll want to summarize
--      "the latest version" or "the changes between introduced and passed"
--   3. Eventual full-text search of bill TEXT (not just titles) wants
--      one row per indexable document
--
-- IDEMPOTENCY KEY
--
-- We use (bill_id, note) as a unique constraint. Same version (note) ingested
-- twice → update, not duplicate. The OpenStates API doesn't give versions
-- their own stable IDs, so (bill_id, note) is the most reliable key.

CREATE TABLE bill_versions (
    id           INTEGER PRIMARY KEY,
    bill_id      INTEGER NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
    note         TEXT    NOT NULL,                   -- "As Introduced", "As Passed Senate"
    issued_at    INTEGER,                            -- unix epoch from OpenStates `date`
    url          TEXT,                               -- primary link (we prefer PDF if multiple)
    media_type   TEXT,                               -- "application/pdf", "text/html"
    "order"      INTEGER NOT NULL DEFAULT 0,         -- display order (oldest first)
    created_at   INTEGER NOT NULL DEFAULT (unixepoch()),

    UNIQUE (bill_id, note)
);

CREATE INDEX idx_versions_bill_time ON bill_versions(bill_id, "order", issued_at);
