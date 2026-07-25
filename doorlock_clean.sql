-- ============================================================
-- doorlock_clean.sql
-- Clean RBAC Schema — Group-Based Model
-- Generated: 2026-07-25
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- جداول پایه
-- ------------------------------------------------------------

CREATE TABLE `doors` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `location`   varchar(255) DEFAULT NULL,
  `is_active`  tinyint(1)   DEFAULT 1,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `doors` VALUES
(1, 'درب ورودی اصلی', 'one',   1, '2026-02-26 16:14:52'),
(2, 'درب طبقه اول',   'two',   1, '2026-02-26 16:14:52'),
(3, 'درب سرور',       'three', 1, '2026-02-26 16:14:52');


CREATE TABLE `persons` (
  `id`          int(11)                  NOT NULL AUTO_INCREMENT,
  `name`        varchar(100)             NOT NULL,
  `card_number` varchar(50)              NOT NULL,
  `role`        enum('admin','user')     NOT NULL DEFAULT 'user',
  `enabled`     tinyint(1)              NOT NULL DEFAULT 1,
  `created_at`  timestamp               NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_card` (`card_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `persons` VALUES
(1, 'علی احمدی',  '3394AAF',    'user',  1, '2026-02-26 16:14:52'),
(2, 'مریم کریمی', '0987654321', 'user',  1, '2026-02-26 16:14:52'),
(3, 'مدیر ارشد',  '1234567',    'admin', 1, '2026-02-27 18:29:30'),
(4, 'مدیر ارشد',  '123456774',  'user',  1, '2026-02-28 21:13:04'),
(5, 'manager',    '1515463',    'user',  0, '2026-02-28 21:41:58'),
(6, 'klnsvnsl',   '1531513',    'user',  1, '2026-02-28 22:16:53'),
(7, 'مدیرارشد',   '123544846',  'admin', 1, '2026-02-28 22:21:27');


CREATE TABLE `web_users` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `username`      varchar(50)  NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at`    timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رمز: Admin@1234 — قبل از production عوض کن
INSERT INTO `web_users` VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-02-26 16:14:52');


-- ------------------------------------------------------------
-- مدل گروهی
-- ------------------------------------------------------------

CREATE TABLE `groups` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `groups` VALUES
(1, 'Admin',      'Full system access',                       '2026-07-25 08:43:41'),
(2, 'Employee',   'Standard employee access',                 '2026-07-25 08:43:41'),
(3, 'Student',    'Limited student access',                   '2026-07-25 08:43:41'),
(4, 'Guest',      'Temporary/visitor access',                 '2026-07-25 08:43:41'),
(5, 'personal_1', 'Auto-migrated personal group for: علی احمدی',  '2026-07-25 08:51:36'),
(6, 'personal_4', 'Auto-migrated personal group for: مدیر ارشد', '2026-07-25 08:51:36');


CREATE TABLE `person_groups` (
  `person_id` int(11) NOT NULL,
  `group_id`  int(11) NOT NULL,
  PRIMARY KEY (`person_id`, `group_id`),
  CONSTRAINT `fk_pg_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pg_group`  FOREIGN KEY (`group_id`)  REFERENCES `groups`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `person_groups` VALUES
(1, 2),  -- علی احمدی → Employee
(1, 5),  -- علی احمدی → personal_1
(2, 2),  -- مریم کریمی → Employee
(3, 1),  -- مدیر ارشد  → Admin
(4, 2),  -- مدیر ارشد  → Employee
(4, 6),  -- مدیر ارشد  → personal_4
(5, 2),  -- manager     → Employee
(6, 2),  -- klnsvnsl    → Employee
(7, 1);  -- مدیرارشد   → Admin


CREATE TABLE `group_door_permissions` (
  `group_id` int(11) NOT NULL,
  `door_id`  int(11) NOT NULL,
  PRIMARY KEY (`group_id`, `door_id`),
  CONSTRAINT `fk_gdp_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gdp_door`  FOREIGN KEY (`door_id`)  REFERENCES `doors`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `group_door_permissions` VALUES
(1, 1),  -- Admin → درب ورودی اصلی   ← FIX: اضافه شد
(1, 2),  -- Admin → درب طبقه اول     ← FIX: اضافه شد
(1, 3),  -- Admin → درب سرور         ← FIX: اضافه شد
(2, 1),  -- Employee → درب ورودی اصلی
(2, 2),  -- Employee → درب طبقه اول
(5, 1),  -- personal_1 → درب ورودی اصلی
(5, 2);  -- personal_1 → درب طبقه اول


CREATE TABLE `group_schedules` (
  `id`         int(11)     NOT NULL AUTO_INCREMENT,
  `group_id`   int(11)     NOT NULL,
  `door_id`    int(11)     NOT NULL,
  `days`       varchar(50) DEFAULT NULL,
  `start_time` time        NOT NULL,
  `end_time`   time        NOT NULL,
  `enabled`    tinyint(1)  NOT NULL DEFAULT 1,
  `created_at` timestamp   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_gs_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gs_door`  FOREIGN KEY (`door_id`)  REFERENCES `doors`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `group_schedules` VALUES
(1, 5, 1, '6',             '21:01:00', '23:00:00', 1, '2026-03-26 17:48:02'),
(2, 5, 1, '1,2,3,4,5,6,7','00:00:00', '23:59:00', 1, '2026-03-26 20:32:25'),
(3, 6, 1, '2,4',           '04:25:00', '05:25:00', 1, '2026-04-07 07:55:20');


-- ------------------------------------------------------------
-- جداول امنیتی و لاگ
-- ------------------------------------------------------------

CREATE TABLE `blacklist` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `person_id`  int(11)      NOT NULL,
  `reason`     varchar(255) DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bl_person` (`person_id`),
  CONSTRAINT `fk_bl_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `temp_access` (
  `id`          int(11)    NOT NULL AUTO_INCREMENT,
  `person_id`   int(11)    NOT NULL,
  `door_id`     int(11)    NOT NULL,
  `valid_from`  datetime   NOT NULL,
  `valid_until` datetime   NOT NULL,
  `single_use`  tinyint(1) NOT NULL DEFAULT 0,
  `used`        tinyint(1) NOT NULL DEFAULT 0,
  `created_at`  timestamp  NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ta_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_door`   FOREIGN KEY (`door_id`)   REFERENCES `doors`   (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `access_logs` (
  `id`          int(11)                  NOT NULL AUTO_INCREMENT,
  `person_id`   int(11)                  DEFAULT NULL,
  `door_id`     int(11)                  DEFAULT NULL,
  `status`      enum('granted','denied') NOT NULL,
  `access_time` timestamp               NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_al_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_al_door`   FOREIGN KEY (`door_id`)   REFERENCES `doors`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `access_logs` VALUES
(1, 1, 1, 'granted', '2026-02-26 16:14:52'),
(2, 2, 1, 'granted', '2026-02-26 16:14:52'),
(3, 1, 2, 'denied',  '2026-02-26 16:14:52'),
(4, 1, 1, 'granted', '2026-03-26 20:44:34');


SET FOREIGN_KEY_CHECKS = 1;
