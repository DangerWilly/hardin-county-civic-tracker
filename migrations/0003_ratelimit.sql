-- 0003_ratelimit.sql — per-IP per-action throttling support.
--
-- We track every rate-limited action as a row. To check "has this IP done
-- $action more than N times in the last $window seconds?" we just
-- COUNT(*) WHERE bucket = ? AND created_at > ?.
--
-- Trade-offs vs. alternatives:
--   * Redis would be faster, but adds an infrastructure dependency. SQLite
--     can handle 10k+ requests/sec on its own; we're not there.
--   * A bucket-counter column (one row per IP+action) would use less disk,
--     but you lose the ability to do sliding-window queries. Event rows
--     give us a true sliding window for the cost of a single index.
--
-- The bucket column is the composite "action:ip_hash" string. Examples:
--   "submit_correction:9d4ef7..."   (form submissions per IP)
--   "ask:9d4ef7..."                  (AI questions per IP, used next step)
--   "ask:global"                     (sitewide AI quota, future)
--
-- Cleanup is opportunistic (see src/ratelimit.php) — 1% of requests sweep
-- rows older than 24h. Cheap, no cron required.

CREATE TABLE rate_limit_events (
    id         INTEGER PRIMARY KEY,
    bucket     TEXT    NOT NULL,                       -- "action:ip_hash"
    created_at INTEGER NOT NULL DEFAULT (unixepoch())
);

-- Compound index serves both the COUNT query and the MIN(created_at)
-- "when can they retry?" query without a second index.
CREATE INDEX idx_rl_bucket_time ON rate_limit_events(bucket, created_at);

-- Standalone created_at index makes the opportunistic cleanup sweep fast.
CREATE INDEX idx_rl_created_at ON rate_limit_events(created_at);
