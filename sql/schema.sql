CREATE TABLE IF NOT EXISTS jobs (
 id TEXT PRIMARY KEY,
 title TEXT NOT NULL,
 original_name TEXT NOT NULL,
 source_path TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
 stage TEXT NOT NULL DEFAULT 'queued',
 progress REAL NOT NULL DEFAULT 0,
 duration REAL,
 captions INTEGER NOT NULL DEFAULT 1,
 error TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at TEXT,
 finished_at TEXT,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS jobs_status_created ON jobs(status,created_at);
