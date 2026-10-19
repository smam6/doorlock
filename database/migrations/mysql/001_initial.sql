-- DoorLock 2.0 initial schema (MySQL 8.0+ / MariaDB 10.6+)
-- All timestamps are stored in UTC with millisecond precision and are supplied
-- by the application. The application also sets the session time zone to UTC.

CREATE TABLE web_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'viewer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    last_login_at DATETIME(3) NULL,
    password_changed_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_web_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE persons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    archived_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_persons_enabled (enabled, archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    person_id INT UNSIGNED NOT NULL,
    card_number VARCHAR(64) NOT NULL,
    status ENUM('active', 'revoked', 'lost') NOT NULL DEFAULT 'active',
    last_used_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cards_card_number (card_number),
    KEY idx_cards_person (person_id, status),
    CONSTRAINT fk_cards_person FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(150) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    unlock_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    archived_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_doors_active (is_active, archived_at),
    CONSTRAINT chk_doors_unlock_seconds CHECK (unlock_seconds BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE person_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_person_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_members (
    group_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (group_id, person_id),
    KEY idx_group_members_person (person_id),
    CONSTRAINT fk_group_members_group FOREIGN KEY (group_id) REFERENCES person_groups (id) ON DELETE CASCADE,
    CONSTRAINT fk_group_members_person FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_permissions (
    group_id INT UNSIGNED NOT NULL,
    door_id INT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (group_id, door_id),
    KEY idx_group_permissions_door (door_id),
    CONSTRAINT fk_group_permissions_group FOREIGN KEY (group_id) REFERENCES person_groups (id) ON DELETE CASCADE,
    CONSTRAINT fk_group_permissions_door FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    person_id INT UNSIGNED NOT NULL,
    door_id INT UNSIGNED NOT NULL,
    effect ENUM('allow', 'deny') NOT NULL DEFAULT 'allow',
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (person_id, door_id),
    KEY idx_permissions_door (door_id),
    CONSTRAINT fk_permissions_person FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    CONSTRAINT fk_permissions_door FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    person_id INT UNSIGNED NOT NULL,
    door_id INT UNSIGNED NOT NULL,
    days VARCHAR(13) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_schedules_person_door (person_id, door_id, enabled),
    KEY idx_schedules_door (door_id),
    CONSTRAINT fk_schedules_person FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    CONSTRAINT fk_schedules_door FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE temporary_grants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    person_id INT UNSIGNED NOT NULL,
    door_id INT UNSIGNED NOT NULL,
    valid_from DATETIME(3) NOT NULL,
    valid_to DATETIME(3) NOT NULL,
    max_uses INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    revoked_by INT UNSIGNED NULL,
    revoked_at DATETIME(3) NULL,
    last_used_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_grants_person_door (person_id, door_id, valid_to),
    KEY idx_grants_active (revoked_at, valid_from, valid_to),
    CONSTRAINT fk_grants_person FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE CASCADE,
    CONSTRAINT fk_grants_door FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE CASCADE,
    CONSTRAINT fk_grants_created_by FOREIGN KEY (created_by) REFERENCES web_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_grants_revoked_by FOREIGN KEY (revoked_by) REFERENCES web_users (id) ON DELETE SET NULL,
    CONSTRAINT chk_grants_window CHECK (valid_to > valid_from),
    CONSTRAINT chk_grants_max_uses CHECK (max_uses IS NULL OR max_uses >= 1),
    CONSTRAINT chk_grants_used_count CHECK (used_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    unique_id CHAR(32) NOT NULL,
    door_id INT UNSIGNED NOT NULL,
    key_version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_unique_id (unique_id),
    KEY idx_devices_door (door_id),
    CONSTRAINT fk_devices_door FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE RESTRICT,
    CONSTRAINT chk_devices_key_version CHECK (key_version >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE device_nonces (
    device_id INT UNSIGNED NOT NULL,
    nonce CHAR(64) NOT NULL,
    seen_at DATETIME(3) NOT NULL,
    PRIMARY KEY (device_id, nonce),
    KEY idx_device_nonces_seen (seen_at),
    CONSTRAINT fk_device_nonces_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE access_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id VARCHAR(64) NOT NULL,
    occurred_at DATETIME(3) NOT NULL,
    person_id INT UNSIGNED NULL,
    person_name_snapshot VARCHAR(100) NULL,
    card_id INT UNSIGNED NULL,
    card_number_snapshot VARCHAR(64) NULL,
    door_id INT UNSIGNED NULL,
    requested_door_id INT UNSIGNED NULL,
    device_id INT UNSIGNED NULL,
    decision ENUM('granted', 'denied', 'error') NOT NULL,
    reason VARCHAR(48) NOT NULL,
    source VARCHAR(16) NULL,
    unlock_seconds TINYINT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    KEY idx_access_logs_occurred (occurred_at, id),
    KEY idx_access_logs_person (person_id, occurred_at),
    KEY idx_access_logs_door (door_id, occurred_at),
    KEY idx_access_logs_decision (decision, occurred_at),
    KEY idx_access_logs_request (request_id),
    KEY idx_access_logs_device (device_id, occurred_at),
    CONSTRAINT fk_access_logs_person FOREIGN KEY (person_id) REFERENCES persons (id) ON DELETE SET NULL,
    CONSTRAINT fk_access_logs_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE SET NULL,
    CONSTRAINT fk_access_logs_door FOREIGN KEY (door_id) REFERENCES doors (id) ON DELETE SET NULL,
    CONSTRAINT fk_access_logs_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED NULL,
    actor_username_snapshot VARCHAR(64) NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id VARCHAR(64) NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    request_id VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_logs_created (created_at, id),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_actor (actor_user_id, created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES web_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL,
    attempted_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_username (username, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at),
    KEY idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
