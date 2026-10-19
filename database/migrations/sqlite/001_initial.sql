-- DoorLock 2.0 initial schema (SQLite 3.35+, development and automated tests)
-- The MySQL schema in ../mysql/001_initial.sql is the production source of
-- truth. This variant exists so the full application and test-suite can be
-- exercised without a MySQL server. Timestamps are UTC text.

CREATE TABLE web_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'viewer' CHECK (role IN ('admin', 'operator', 'viewer')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    session_version INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL,
    password_changed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX uq_web_users_username ON web_users (username);

CREATE TABLE persons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    notes TEXT NULL,
    archived_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_persons_enabled ON persons (enabled, archived_at);

CREATE TABLE cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER NOT NULL,
    card_number TEXT NOT NULL COLLATE NOCASE,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked', 'lost')),
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX uq_cards_card_number ON cards (card_number);
CREATE INDEX idx_cards_person ON cards (person_id, status);

CREATE TABLE doors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    location TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    unlock_seconds INTEGER NOT NULL DEFAULT 5 CHECK (unlock_seconds BETWEEN 1 AND 30),
    archived_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_doors_active ON doors (is_active, archived_at);

CREATE TABLE person_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE,
    description TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    archived_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX uq_person_groups_name ON person_groups (name);

CREATE TABLE group_members (
    group_id INTEGER NOT NULL,
    person_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (group_id, person_id),
    FOREIGN KEY (group_id) REFERENCES person_groups (id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
);
CREATE INDEX idx_group_members_person ON group_members (person_id);

CREATE TABLE group_permissions (
    group_id INTEGER NOT NULL,
    door_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (group_id, door_id),
    FOREIGN KEY (group_id) REFERENCES person_groups (id) ON DELETE CASCADE,
    FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE
);
CREATE INDEX idx_group_permissions_door ON group_permissions (door_id);

CREATE TABLE permissions (
    person_id INTEGER NOT NULL,
    door_id INTEGER NOT NULL,
    effect TEXT NOT NULL DEFAULT 'allow' CHECK (effect IN ('allow', 'deny')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (person_id, door_id),
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE
);
CREATE INDEX idx_permissions_door ON permissions (door_id);

CREATE TABLE schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER NOT NULL,
    door_id INTEGER NOT NULL,
    days TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE
);
CREATE INDEX idx_schedules_person_door ON schedules (person_id, door_id, enabled);
CREATE INDEX idx_schedules_door ON schedules (door_id);

CREATE TABLE temporary_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER NOT NULL,
    door_id INTEGER NOT NULL,
    valid_from TEXT NOT NULL,
    valid_to TEXT NOT NULL,
    max_uses INTEGER NULL CHECK (max_uses IS NULL OR max_uses >= 1),
    used_count INTEGER NOT NULL DEFAULT 0 CHECK (used_count >= 0),
    note TEXT NULL,
    created_by INTEGER NULL,
    revoked_by INTEGER NULL,
    revoked_at TEXT NULL,
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES web_users (id) ON DELETE SET NULL,
    FOREIGN KEY (revoked_by) REFERENCES web_users (id) ON DELETE SET NULL,
    CHECK (valid_to > valid_from)
);
CREATE INDEX idx_grants_person_door ON temporary_grants (person_id, door_id, valid_to);
CREATE INDEX idx_grants_active ON temporary_grants (revoked_at, valid_from, valid_to);

CREATE TABLE devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    unique_id TEXT NOT NULL COLLATE NOCASE,
    door_id INTEGER NOT NULL,
    key_version INTEGER NOT NULL DEFAULT 1 CHECK (key_version >= 1),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    last_seen_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX uq_devices_unique_id ON devices (unique_id);
CREATE INDEX idx_devices_door ON devices (door_id);

CREATE TABLE device_nonces (
    device_id INTEGER NOT NULL,
    nonce TEXT NOT NULL,
    seen_at TEXT NOT NULL,
    PRIMARY KEY (device_id, nonce),
    FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
);
CREATE INDEX idx_device_nonces_seen ON device_nonces (seen_at);

CREATE TABLE access_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    person_id INTEGER NULL,
    person_name_snapshot TEXT NULL,
    card_id INTEGER NULL,
    card_number_snapshot TEXT NULL,
    door_id INTEGER NULL,
    requested_door_id INTEGER NULL,
    device_id INTEGER NULL,
    decision TEXT NOT NULL CHECK (decision IN ('granted', 'denied', 'error')),
    reason TEXT NOT NULL,
    source TEXT NULL,
    unlock_seconds INTEGER NULL,
    ip TEXT NULL,
    FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE SET NULL,
    FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE SET NULL,
    FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE SET NULL,
    FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE SET NULL
);
CREATE INDEX idx_access_logs_occurred ON access_logs (occurred_at, id);
CREATE INDEX idx_access_logs_person ON access_logs (person_id, occurred_at);
CREATE INDEX idx_access_logs_door ON access_logs (door_id, occurred_at);
CREATE INDEX idx_access_logs_decision ON access_logs (decision, occurred_at);
CREATE INDEX idx_access_logs_request ON access_logs (request_id);
CREATE INDEX idx_access_logs_device ON access_logs (device_id, occurred_at);

CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER NULL,
    actor_username_snapshot TEXT NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    request_id TEXT NOT NULL,
    ip TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (actor_user_id) REFERENCES web_users (id) ON DELETE SET NULL
);
CREATE INDEX idx_audit_logs_created ON audit_logs (created_at, id);
CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id);
CREATE INDEX idx_audit_logs_actor ON audit_logs (actor_user_id, created_at);

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    success INTEGER NOT NULL CHECK (success IN (0, 1)),
    attempted_at TEXT NOT NULL
);
CREATE INDEX idx_login_attempts_username ON login_attempts (username, attempted_at);
CREATE INDEX idx_login_attempts_ip ON login_attempts (ip, attempted_at);
CREATE INDEX idx_login_attempts_time ON login_attempts (attempted_at);
