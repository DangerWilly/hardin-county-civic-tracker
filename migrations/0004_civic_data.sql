-- 0004_civic_data.sql — the substantive civic data schema.
--
-- What goes in here:
--   bodies          — city council, county commissioners, school board, township trustees, etc.
--   meetings        — a specific gathering of a body on a specific date
--   agenda_items    — line items within a meeting agenda (this is what the AI summarizes)
--   officials       — elected + appointed officials at every level (fed → city)
--   official_terms  — who held a seat across time (lets us track history without overwriting)
--
-- A few schema philosophy notes:
--
-- 1. `summary` and `summary_at` columns live ON the meeting and agenda_item rows.
--    When the AI worker generates a summary, we write it once, here. Public
--    pageviews render the cached text — never call the API per request.
--
-- 2. Everything ingestible carries a `source` column (manual / scraper / api / user).
--    Same pattern as content_pages. Critical for triage: when something looks
--    wrong, "did a human or a robot put this here?" is the first question.
--
-- 3. URLs (agenda_url, video_url, etc) are stored separately from the body text
--    because they're authoritative pointers — not summaries. The AI can paraphrase
--    an agenda, but the public meeting page should always link to the actual PDF.
--
-- 4. `officials.body_id` is nullable: a state senator doesn't belong to a "body"
--    in the city-council sense, but a council member does. The FK lets us list
--    "all members of Kenton City Council" cheaply when present.

CREATE TABLE bodies (
    id              INTEGER PRIMARY KEY,
    jurisdiction_id INTEGER NOT NULL REFERENCES jurisdictions(id) ON DELETE RESTRICT,
    slug            TEXT    NOT NULL,
    name            TEXT    NOT NULL,                  -- "Kenton City Council"
    kind            TEXT    NOT NULL CHECK (kind IN (
                        'city_council','county_commission','school_board',
                        'township_trustees','planning','zoning','library_board',
                        'parks_rec','other'
                    )),
    description     TEXT,                              -- markdown OK
    website_url     TEXT,
    contact_email   TEXT,
    contact_phone   TEXT,
    meeting_info    TEXT,                              -- "2nd Monday, 7pm, City Building"
    source          TEXT    NOT NULL DEFAULT 'manual'
                            CHECK (source IN ('manual','scraper','api','user')),
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    UNIQUE (jurisdiction_id, slug)
);
CREATE INDEX idx_bodies_jur  ON bodies(jurisdiction_id);
CREATE INDEX idx_bodies_kind ON bodies(kind);


CREATE TABLE meetings (
    id              INTEGER PRIMARY KEY,
    body_id         INTEGER NOT NULL REFERENCES bodies(id) ON DELETE CASCADE,
    title           TEXT,                              -- "Regular Meeting" / "Budget Hearing"
    starts_at       INTEGER NOT NULL,                  -- unix epoch
    ends_at         INTEGER,
    location_name   TEXT,                              -- "Council Chambers"
    address         TEXT,                              -- "111 W Franklin St, Kenton, OH 43326"
    is_remote       INTEGER NOT NULL DEFAULT 0,
    remote_url      TEXT,                              -- Zoom / livestream
    agenda_url      TEXT,                              -- linked PDF of official agenda
    minutes_url     TEXT,                              -- approved minutes (after the fact)
    video_url       TEXT,                              -- recording
    notes_md        TEXT,                              -- editor's notes, markdown
    summary         TEXT,                              -- AI-generated, cached
    summary_model   TEXT,                              -- which xAI model produced it
    summary_at      INTEGER,                           -- when summary was generated
    status          TEXT    NOT NULL DEFAULT 'scheduled'
                            CHECK (status IN ('scheduled','live','completed','cancelled')),
    source          TEXT    NOT NULL DEFAULT 'manual'
                            CHECK (source IN ('manual','scraper','api','user')),
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX idx_meetings_body_time   ON meetings(body_id, starts_at);
CREATE INDEX idx_meetings_starts_at   ON meetings(starts_at);
CREATE INDEX idx_meetings_status_time ON meetings(status, starts_at);


CREATE TABLE agenda_items (
    id              INTEGER PRIMARY KEY,
    meeting_id      INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
    position        INTEGER NOT NULL DEFAULT 0,        -- display order, lower = earlier
    item_number     TEXT,                              -- "3.A" / "Item 7" / "Ordinance 2026-04"
    title           TEXT    NOT NULL,
    body_md         TEXT,                              -- full text of the item, markdown
    summary         TEXT,                              -- AI-generated, cached
    item_type       TEXT,                              -- 'discussion','vote','presentation','public_comment','consent'
    outcome         TEXT,                              -- 'passed','failed','tabled','continued',NULL
    created_at      INTEGER NOT NULL DEFAULT (unixepoch())
);
CREATE INDEX idx_agenda_meeting ON agenda_items(meeting_id, position);


CREATE TABLE officials (
    id              INTEGER PRIMARY KEY,
    jurisdiction_id INTEGER NOT NULL REFERENCES jurisdictions(id) ON DELETE RESTRICT,
    slug            TEXT    NOT NULL,
    full_name       TEXT    NOT NULL,
    title           TEXT    NOT NULL,                  -- "State Senator","Mayor","Sheriff","Council Member"
    party           TEXT,                              -- 'R','D','I','N' (nonpartisan), NULL unknown
    photo_url       TEXT,
    bio_md          TEXT,
    contact_email   TEXT,
    contact_phone   TEXT,
    office_address  TEXT,
    website_url     TEXT,
    body_id         INTEGER REFERENCES bodies(id) ON DELETE SET NULL,
    source          TEXT    NOT NULL DEFAULT 'manual'
                            CHECK (source IN ('manual','scraper','api','user')),
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    UNIQUE (jurisdiction_id, slug)
);
CREATE INDEX idx_officials_jur  ON officials(jurisdiction_id);
CREATE INDEX idx_officials_body ON officials(body_id);


-- Terms exist as separate rows so we can track succession without overwriting
-- history. When a new state senator takes office, we INSERT a row with
-- starts_at=inauguration and leave the old row's ends_at filled in.
CREATE TABLE official_terms (
    id              INTEGER PRIMARY KEY,
    official_id     INTEGER NOT NULL REFERENCES officials(id) ON DELETE CASCADE,
    starts_at       INTEGER NOT NULL,
    ends_at         INTEGER,                           -- NULL = current term, no scheduled end
    elected         INTEGER NOT NULL DEFAULT 1,        -- 1 elected, 0 appointed
    notes           TEXT
);
CREATE INDEX idx_terms_official ON official_terms(official_id);
CREATE INDEX idx_terms_starts   ON official_terms(starts_at);
