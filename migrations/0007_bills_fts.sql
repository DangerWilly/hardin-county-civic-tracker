-- 0007_bills_fts.sql — full-text search index for bills.
--
-- SQLite ships with FTS5, a virtual-table extension that's been
-- production-grade since 2015. It supports:
--   * Tokenization (we use the default unicode61, which handles punctuation
--     and case-folding sensibly)
--   * Phrase queries: "school funding"
--   * Boolean: school AND funding
--   * Prefix matching: school*
--
-- Why an FTS5 table instead of LIKE queries on `bills` directly?
--
--   At 400 bills, LIKE works fine. At 5,000 (a typical biennial session),
--   it's noticeably slower. At 50,000 (multi-session history), it's a
--   disaster. Setting up FTS5 now means search performance never becomes
--   a problem we have to scramble to fix later.
--
-- DESIGN
--
-- The FTS5 virtual table is "external content" — it doesn't store the
-- bill data itself, just an index that points at rows in `bills`. Three
-- triggers keep it in sync:
--
--   INSERT on bills  → insert into FTS
--   UPDATE on bills  → delete then insert into FTS (FTS5 supports this idiom)
--   DELETE on bills  → delete from FTS
--
-- We populate FTS for existing rows after creating the triggers, so the
-- 400 bills we've already ingested become searchable immediately.

CREATE VIRTUAL TABLE bills_fts USING fts5 (
    identifier,
    title,
    abstract,
    content       = 'bills',
    content_rowid = 'id',
    tokenize      = 'unicode61 remove_diacritics 2'
);

-- INSERT trigger: when a new bill row appears, mirror it into FTS
CREATE TRIGGER bills_ai AFTER INSERT ON bills BEGIN
    INSERT INTO bills_fts (rowid, identifier, title, abstract)
    VALUES (new.id, new.identifier, new.title, new.abstract);
END;

-- DELETE trigger: when a bill row is removed, drop its FTS entry
CREATE TRIGGER bills_ad AFTER DELETE ON bills BEGIN
    INSERT INTO bills_fts (bills_fts, rowid, identifier, title, abstract)
    VALUES ('delete', old.id, old.identifier, old.title, old.abstract);
END;

-- UPDATE trigger: drop old, insert new
CREATE TRIGGER bills_au AFTER UPDATE ON bills BEGIN
    INSERT INTO bills_fts (bills_fts, rowid, identifier, title, abstract)
    VALUES ('delete', old.id, old.identifier, old.title, old.abstract);
    INSERT INTO bills_fts (rowid, identifier, title, abstract)
    VALUES (new.id, new.identifier, new.title, new.abstract);
END;

-- Backfill: index every bill row that already exists
INSERT INTO bills_fts (rowid, identifier, title, abstract)
SELECT id, identifier, title, abstract FROM bills;
