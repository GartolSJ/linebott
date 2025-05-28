-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 27, 2025 at 05:26 PM
-- Server version: 10.5.28-MariaDB
-- PHP Version: 8.1.32

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sjgripco_line_reports`
--

-- --------------------------------------------------------

--
-- Table structure for table `bad_words`
--

CREATE TABLE `bad_words` (
  `id` int(11) NOT NULL,
  `word` varchar(100) NOT NULL,
  `language` enum('th','en') NOT NULL DEFAULT 'th',
  `added_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bad_words`
--

INSERT INTO `bad_words` (`id`, `word`, `language`, `added_by`, `created_at`) VALUES
(1, 'ควย', 'th', 1, '2025-05-24 10:27:46'),
(2, 'ควาย', 'th', 1, '2025-05-24 10:27:46'),
(3, 'โง่', 'th', 1, '2025-05-24 10:27:46'),
(4, 'fuck', 'en', 1, '2025-05-24 10:27:46'),
(5, 'shit', 'en', 1, '2025-05-24 10:27:46'),
(6, 'bitch', 'en', 1, '2025-05-24 10:27:46'),
(8, 'การ์ต้อล', 'th', 1, '2025-05-26 02:26:20'),
(9, 'ค ว ย', 'th', 1, '2025-05-26 02:32:37'),
(10, 'หี', 'th', 1, '2025-05-26 02:32:46'),
(11, 'หีี', 'th', 1, '2025-05-26 02:32:50'),
(12, 'hee', 'en', 1, '2025-05-26 02:33:00'),
(13, 'เย็ด', 'en', 1, '2025-05-26 05:30:27');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `location_text` varchar(255) NOT NULL,
  `image_url` text NOT NULL,
  `updated_image_url` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'รอรับเรื่อง',
  `completed_at` datetime DEFAULT NULL,
  `department` varchar(100) NOT NULL DEFAULT '',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_reason` text DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reports`
--

INSERT INTO `reports` (`id`, `user_id`, `message`, `location_text`, `image_url`, `updated_image_url`, `status`, `completed_at`, `department`, `deleted_at`, `deleted_reason`, `deleted_by`, `updated_at`, `created_at`) VALUES
(121, 'U1567d9e371cf0a5bde2f1edf9228f5fa', '5255252', 'ตึก 1 ชั้น 4 ห้อง 404', 'https://sjgrip.com/backend/uploads/img_68357e1743ef60.83259317.jpg', 'https://sjgrip.com/backend/uploads/done_68357e986bf67.jpg', 'เสร็จสิ้น', NULL, 'สารสนเทศ (MIS)', NULL, NULL, NULL, '2025-05-27 08:58:00', '2025-05-27 08:55:51'),
(122, 'U1567d9e371cf0a5bde2f1edf9228f5fa', '55555555', 'ตึก 2 ชั้น 4 ห้อง 404', 'https://sjgrip.com/backend/uploads/img_683582979d6891.99824346.jpg', NULL, 'รอรับเรื่อง', NULL, 'สารสนเทศ (MIS)', NULL, NULL, NULL, '2025-05-27 09:15:03', '2025-05-27 09:15:03');

-- --------------------------------------------------------

--
-- Table structure for table `report_status_logs`
--

CREATE TABLE `report_status_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `report_id` int(10) UNSIGNED NOT NULL,
  `admin_id` int(10) UNSIGNED NOT NULL,
  `admin_name` varchar(100) NOT NULL,
  `old_status` enum('รอรับเรื่อง','กำลังดำเนินการ','เสร็จสิ้น','ยกเลิก') NOT NULL,
  `new_status` enum('รอรับเรื่อง','กำลังดำเนินการ','เสร็จสิ้น','ยกเลิก') NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `report_status_logs`
--

INSERT INTO `report_status_logs` (`id`, `report_id`, `admin_id`, `admin_name`, `old_status`, `new_status`, `changed_at`) VALUES
(28, 121, 1, 'Thirapat Tiyawatwittaya', 'รอรับเรื่อง', 'กำลังดำเนินการ', '2025-05-27 08:57:45'),
(29, 121, 1, 'Thirapat Tiyawatwittaya', 'กำลังดำเนินการ', 'เสร็จสิ้น', '2025-05-27 08:58:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `pwd_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` enum('EXEC','ADMIN','MIS','BUILDING') NOT NULL DEFAULT 'MIS',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `force_password_reset` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `pwd_hash`, `name`, `role`, `created_at`, `force_password_reset`) VALUES
(1, 'thirapat', '$2y$10$7eT4IHcIRb3Dn9WfnIjtIuhrVnqXj4FrVu2d/210TUjpMA/xQ/KRi', 'Thirapat Tiyawatwittaya', 'ADMIN', '2025-05-13 07:08:35', 0),
(5, 'rapeepat', '$2y$10$R4tHVwCp.575VWK5QEzpHOwhj1oBaKW/IbBx9yWGFw7Tw9uiN1pWC', 'rapeepat', 'ADMIN', '2025-05-26 07:22:40', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `user_id` varchar(50) NOT NULL,
  `step` varchar(20) NOT NULL,
  `message` text DEFAULT NULL,
  `location_text` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`user_id`, `step`, `message`, `location_text`, `department`, `updated_at`) VALUES
('U1567d9e371cf0a5bde2f1edf9228f5fa', 'completed', '55555555', 'ตึก 2 ชั้น 4 ห้อง 404', 'สารสนเทศ (MIS)', '2025-05-27 09:15:05');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bad_words`
--
ALTER TABLE `bad_words`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `word` (`word`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `report_status_logs`
--
ALTER TABLE `report_status_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `report_id` (`report_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bad_words`
--
ALTER TABLE `bad_words`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

--
-- AUTO_INCREMENT for table `report_status_logs`
--
ALTER TABLE `report_status_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bad_words`
--
ALTER TABLE `bad_words`
  ADD CONSTRAINT `bad_words_ibfk_1` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
