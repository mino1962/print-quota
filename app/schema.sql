CREATE TABLE IF NOT EXISTS organization (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL DEFAULT 'Organization',
    org_type TEXT NOT NULL DEFAULT 'azienda',
    address TEXT,
    city TEXT,
    tax_id TEXT
);

CREATE TABLE IF NOT EXISTS setting (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS operator (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('ADMIN','OPERATOR')),
    must_change_password TEXT NOT NULL DEFAULT 'S',
    surname TEXT NOT NULL DEFAULT '',
    given_name TEXT NOT NULL DEFAULT '',
    email TEXT,
    person_id INTEGER,
    active TEXT NOT NULL DEFAULT 'S'
);

CREATE TABLE IF NOT EXISTS person (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    surname TEXT NOT NULL,
    given_name TEXT NOT NULL,
    email TEXT,
    code TEXT UNIQUE,
    group_name TEXT,
    phone TEXT,
    notes TEXT,
    external_id TEXT UNIQUE,
    active TEXT NOT NULL DEFAULT 'S'
);

CREATE TABLE IF NOT EXISTS printer (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    name TEXT NOT NULL,
    location TEXT,
    ip TEXT NOT NULL,
    login TEXT,
    password TEXT,
    active TEXT NOT NULL DEFAULT 'S'
);

CREATE TABLE IF NOT EXISTS account_state (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER NOT NULL,
    printer_id INTEGER NOT NULL,
    code TEXT,
    ceiling INTEGER,
    copies INTEGER,
    present TEXT NOT NULL DEFAULT 'N',
    synced_at TEXT,
    error TEXT,
    UNIQUE (person_id, printer_id)
);

CREATE TABLE IF NOT EXISTS action_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    printer_id INTEGER,
    person_id INTEGER,
    operator_id INTEGER,
    code TEXT,
    action TEXT NOT NULL,
    ceiling INTEGER,
    copies INTEGER,
    ok TEXT NOT NULL,
    error TEXT
);

CREATE TABLE IF NOT EXISTS job_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    action TEXT NOT NULL,
    person_id INTEGER,
    printer_id INTEGER,
    step INTEGER,
    status TEXT NOT NULL DEFAULT 'P',
    log TEXT,
    operator_id INTEGER,
    started_at TEXT,
    finished_at TEXT
);
