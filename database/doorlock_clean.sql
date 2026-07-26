-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 26, 2026 at 10:17 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `doorlock_clean`
--

-- --------------------------------------------------------

--
-- Table structure for table `access_logs`
--

CREATE TABLE `access_logs` (
  `id` int(11) NOT NULL,
  `person_id` int(11) DEFAULT NULL,
  `door_id` int(11) DEFAULT NULL,
  `status` enum('granted','denied') NOT NULL,
  `access_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `access_logs`
--

INSERT INTO `access_logs` (`id`, `person_id`, `door_id`, `status`, `access_time`) VALUES
(1, 1, 1, 'granted', '2026-02-26 12:44:52'),
(2, 2, 1, 'granted', '2026-02-26 12:44:52'),
(3, 1, 2, 'denied', '2026-02-26 12:44:52'),
(4, 1, 1, 'granted', '2026-03-26 17:14:34');

-- --------------------------------------------------------

--
-- Table structure for table `blacklist`
--

CREATE TABLE `blacklist` (
  `id` int(11) NOT NULL,
  `person_id` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doors`
--

CREATE TABLE `doors` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doors`
--

INSERT INTO `doors` (`id`, `name`, `location`, `is_active`, `created_at`) VALUES
(1, 'درب ورودی اصلی', 'one', 1, '2026-02-26 12:44:52'),
(2, 'درب طبقه اول', 'two', 1, '2026-02-26 12:44:52'),
(3, 'درب سرور', 'three', 1, '2026-02-26 12:44:52');

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `groups`
--

INSERT INTO `groups` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'Admin', 'Full system access', '2026-07-25 05:13:41'),
(2, 'Employee', 'Standard employee access', '2026-07-25 05:13:41'),
(3, 'Student', 'Limited student access', '2026-07-25 05:13:41'),
(4, 'Guest', 'Temporary/visitor access', '2026-07-25 05:13:41'),
(5, 'personal_1', 'Auto-migrated personal group for: علی احمدی', '2026-07-25 05:21:36'),
(6, 'personal_4', 'Auto-migrated personal group for: مدیر ارشد', '2026-07-25 05:21:36');

-- --------------------------------------------------------

--
-- Table structure for table `group_door_permissions`
--

CREATE TABLE `group_door_permissions` (
  `group_id` int(11) NOT NULL,
  `door_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `group_door_permissions`
--

INSERT INTO `group_door_permissions` (`group_id`, `door_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(2, 1),
(2, 2),
(5, 1),
(5, 2);

-- --------------------------------------------------------

--
-- Table structure for table `group_schedules`
--

CREATE TABLE `group_schedules` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `door_id` int(11) NOT NULL,
  `days` varchar(50) DEFAULT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `group_schedules`
--

INSERT INTO `group_schedules` (`id`, `group_id`, `door_id`, `days`, `start_time`, `end_time`, `enabled`, `created_at`) VALUES
(1, 5, 1, '6', '21:01:00', '23:00:00', 1, '2026-03-26 14:18:02'),
(2, 5, 1, '1,2,3,4,5,6,7', '00:00:00', '23:59:00', 1, '2026-03-26 17:02:25'),
(3, 6, 1, '2,4', '04:25:00', '05:25:00', 1, '2026-04-07 04:25:20');

-- --------------------------------------------------------

--
-- Table structure for table `persons`
--

CREATE TABLE `persons` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `card_number` varchar(50) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `persons`
--

INSERT INTO `persons` (`id`, `name`, `card_number`, `role`, `enabled`, `created_at`) VALUES
(1, 'علی احمدی', '3394AAF', 'user', 1, '2026-02-26 12:44:52'),
(2, 'مریم کریمی', '0987654321', 'user', 1, '2026-02-26 12:44:52'),
(3, 'مدیر ارشد', '1234567', 'admin', 1, '2026-02-27 14:59:30'),
(4, 'مدیر ارشد', '123456774', 'user', 1, '2026-02-28 17:43:04'),
(5, 'manager', '1515463', 'user', 0, '2026-02-28 18:11:58'),
(6, 'klnsvnsl', '1531513', 'user', 1, '2026-02-28 18:46:53'),
(7, 'مدیرارشد', '123544846', 'admin', 1, '2026-02-28 18:51:27');

-- --------------------------------------------------------

--
-- Table structure for table `person_groups`
--

CREATE TABLE `person_groups` (
  `person_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `person_groups`
--

INSERT INTO `person_groups` (`person_id`, `group_id`) VALUES
(1, 2),
(1, 5),
(2, 2),
(3, 1),
(4, 2),
(4, 6),
(5, 2),
(6, 2),
(7, 1);

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
-- (Personal, per-user schedules — mirrors `group_schedules` but for an
--  individual person instead of a group. This table was referenced by
--  api/schedules.php and api/check-access.php but was missing from the
--  original dump, so every personal-schedule request failed with a SQL
--  error. Added to match what the application code actually expects.)
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `person_id` int(11) NOT NULL,
  `door_id` int(11) NOT NULL,
  `days` varchar(50) DEFAULT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `temp_access`
--

CREATE TABLE `temp_access` (
  `id` int(11) NOT NULL,
  `person_id` int(11) NOT NULL,
  `door_id` int(11) NOT NULL,
  `valid_from` datetime NOT NULL,
  `valid_until` datetime NOT NULL,
  `single_use` tinyint(1) NOT NULL DEFAULT 0,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `web_users`
--

CREATE TABLE `web_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `web_users`
--

INSERT INTO `web_users` (`id`, `username`, `password_hash`, `created_at`) VALUES
(1, 'admin', '$2b$12$iFhRnHJgb9v3RY2BrH1wX./m0ydkiMeAVVFG0YlgsI1fz0PVcGk4i', '2026-02-26 12:44:52');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `access_logs`
--
ALTER TABLE `access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_al_person` (`person_id`),
  ADD KEY `fk_al_door` (`door_id`),
  ADD KEY `idx_al_access_time` (`access_time`);

--
-- Indexes for table `blacklist`
--
ALTER TABLE `blacklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bl_person` (`person_id`);

--
-- Indexes for table `doors`
--
ALTER TABLE `doors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_group_name` (`name`);

--
-- Indexes for table `group_door_permissions`
--
ALTER TABLE `group_door_permissions`
  ADD PRIMARY KEY (`group_id`,`door_id`),
  ADD KEY `fk_gdp_door` (`door_id`);

--
-- Indexes for table `group_schedules`
--
ALTER TABLE `group_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_gs_group` (`group_id`),
  ADD KEY `fk_gs_door` (`door_id`),
  ADD KEY `idx_gs_lookup` (`group_id`,`door_id`,`enabled`);

--
-- Indexes for table `persons`
--
ALTER TABLE `persons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_card` (`card_number`);

--
-- Indexes for table `person_groups`
--
ALTER TABLE `person_groups`
  ADD PRIMARY KEY (`person_id`,`group_id`),
  ADD KEY `fk_pg_group` (`group_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sch_person` (`person_id`),
  ADD KEY `fk_sch_door` (`door_id`),
  ADD KEY `idx_sch_lookup` (`person_id`,`door_id`,`enabled`);

--
-- Indexes for table `temp_access`
--
ALTER TABLE `temp_access`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ta_person` (`person_id`),
  ADD KEY `fk_ta_door` (`door_id`),
  ADD KEY `idx_ta_lookup` (`person_id`,`door_id`,`valid_from`,`valid_until`);

--
-- Indexes for table `web_users`
--
ALTER TABLE `web_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `access_logs`
--
ALTER TABLE `access_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `blacklist`
--
ALTER TABLE `blacklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `doors`
--
ALTER TABLE `doors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `group_schedules`
--
ALTER TABLE `group_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `persons`
--
ALTER TABLE `persons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `temp_access`
--
ALTER TABLE `temp_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `web_users`
--
ALTER TABLE `web_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `access_logs`
--
ALTER TABLE `access_logs`
  ADD CONSTRAINT `fk_al_door` FOREIGN KEY (`door_id`) REFERENCES `doors` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_al_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `blacklist`
--
ALTER TABLE `blacklist`
  ADD CONSTRAINT `fk_bl_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_door_permissions`
--
ALTER TABLE `group_door_permissions`
  ADD CONSTRAINT `fk_gdp_door` FOREIGN KEY (`door_id`) REFERENCES `doors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gdp_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_schedules`
--
ALTER TABLE `group_schedules`
  ADD CONSTRAINT `fk_gs_door` FOREIGN KEY (`door_id`) REFERENCES `doors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gs_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `person_groups`
--
ALTER TABLE `person_groups`
  ADD CONSTRAINT `fk_pg_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pg_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `fk_sch_door` FOREIGN KEY (`door_id`) REFERENCES `doors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sch_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `temp_access`
--
ALTER TABLE `temp_access`
  ADD CONSTRAINT `fk_ta_door` FOREIGN KEY (`door_id`) REFERENCES `doors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ta_person` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
