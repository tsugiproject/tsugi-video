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
 -- Whisper initial prompt, and the replacement rules applied to captions.vtt.
 caption_prompt TEXT,
 caption_replacements TEXT,
 error TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at TEXT,
 finished_at TEXT,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS jobs_status_created ON jobs(status,created_at);

-- A collection plus a logical key points at one video. Uploading that pair again
-- moves the pointer to the new file. The previous file stays addressable by its hash.
CREATE TABLE IF NOT EXISTS placements (
 collection TEXT NOT NULL,
 logical_key TEXT NOT NULL,
 job_id TEXT NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (collection, logical_key)
);
CREATE INDEX IF NOT EXISTS placements_job ON placements(job_id);
