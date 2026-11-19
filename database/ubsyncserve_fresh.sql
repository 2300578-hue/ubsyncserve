-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 19, 2026 at 01:16 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ubsyncserve_fresh`
--

-- --------------------------------------------------------

--
-- Table structure for table `confirmed_reservation_emails`
--

CREATE TABLE `confirmed_reservation_emails` (
  `normalized_email` varchar(255) NOT NULL,
  `booking_reference` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_bookings`
--

CREATE TABLE `customer_bookings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `booking_reference` varchar(255) NOT NULL,
  `type` enum('advance-order','table-reservation') NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `adults` int(10) UNSIGNED NOT NULL,
  `children` int(10) UNSIGNED NOT NULL,
  `guests` int(10) UNSIGNED NOT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `requests` text DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `archived_at` timestamp NULL DEFAULT NULL,
  `confirmation_email_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_number` varchar(255) NOT NULL,
  `table_number` varchar(255) DEFAULT NULL,
  `waiter_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `sales_shift_id` bigint(20) UNSIGNED DEFAULT NULL,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `booking_type` varchar(255) DEFAULT NULL,
  `idempotency_key` char(36) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `add_ons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`add_ons`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_account_verifications`
--

CREATE TABLE `pending_account_verifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('manager','waiter') NOT NULL DEFAULT 'waiter',
  `password` varchar(255) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `otp_expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `print_jobs`
--

CREATE TABLE `print_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `job_type` varchar(255) NOT NULL,
  `dedupe_key` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `claim_token` varchar(255) DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `printed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(255) DEFAULT NULL,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image` longtext DEFAULT NULL,
  `stock_quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `add_ons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`add_ons`)),
  `ingredients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ingredients`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_tables`
--

CREATE TABLE `restaurant_tables` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `table_number` tinyint(3) UNSIGNED NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'available',
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `adults` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `children` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `guests` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `bill` decimal(10,2) NOT NULL DEFAULT 0.00,
  `orders` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`orders`)),
  `start_time` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `restaurant_tables`
--

INSERT INTO `restaurant_tables` (`id`, `table_number`, `status`, `is_paid`, `adults`, `children`, `guests`, `bill`, `orders`, `start_time`, `created_at`, `updated_at`) VALUES
(1, 1, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(2, 2, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(3, 3, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(4, 4, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(5, 5, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(6, 6, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(7, 7, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(8, 8, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(9, 9, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(10, 10, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(11, 11, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(12, 12, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(13, 13, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(14, 14, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16'),
(15, 15, 'available', 0, 0, 0, 0, 0.00, '[]', NULL, '2026-11-19 11:06:16', '2026-11-19 11:06:16');

-- --------------------------------------------------------

--
-- Table structure for table `sales_settings`
--

CREATE TABLE `sales_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `business_day_cutoff` time NOT NULL DEFAULT '00:00:00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_shifts`
--

CREATE TABLE `sales_shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `started_by` bigint(20) UNSIGNED NOT NULL,
  `ended_by` bigint(20) UNSIGNED DEFAULT NULL,
  `business_date` date NOT NULL,
  `cutoff_time` time DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `opening_float` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ended_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `order_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `items_sold` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `gross_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `vat_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gcash_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `z_read` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`z_read`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('manager','waiter') NOT NULL DEFAULT 'waiter',
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'JayMark Riana', '2300578@ub.edu.ph', '2026-11-19 11:06:03', '$2y$12$ePwpK/uOgYL3TZnZ5oeJDeuxjFxRY1FWL7CPAn/cIqjo0smpwU/2O', 'waiter', NULL, '2026-11-19 11:06:03', '2026-11-19 11:06:03');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `confirmed_reservation_emails`
--
ALTER TABLE `confirmed_reservation_emails`
  ADD PRIMARY KEY (`normalized_email`),
  ADD KEY `confirmed_reservation_emails_booking_reference_index` (`booking_reference`);

--
-- Indexes for table `customer_bookings`
--
ALTER TABLE `customer_bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_bookings_booking_reference_unique` (`booking_reference`),
  ADD KEY `customer_bookings_email_booking_date_index` (`email`,`booking_date`),
  ADD KEY `customer_bookings_type_status_index` (`type`,`status`),
  ADD KEY `customer_bookings_status_archived_at_index` (`status`,`archived_at`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `orders_order_number_unique` (`order_number`),
  ADD UNIQUE KEY `orders_idempotency_key_unique` (`idempotency_key`),
  ADD KEY `orders_waiter_id_foreign` (`waiter_id`),
  ADD KEY `orders_status_created_at_index` (`status`,`created_at`),
  ADD KEY `orders_sales_shift_id_foreign` (`sales_shift_id`),
  ADD KEY `orders_paid_at_index` (`paid_at`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_items_order_id_foreign` (`order_id`),
  ADD KEY `order_items_product_id_foreign` (`product_id`);

--
-- Indexes for table `pending_account_verifications`
--
ALTER TABLE `pending_account_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pending_account_verifications_email_unique` (`email`);

--
-- Indexes for table `print_jobs`
--
ALTER TABLE `print_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `print_jobs_dedupe_key_unique` (`dedupe_key`),
  ADD KEY `print_jobs_status_id_index` (`status`,`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `restaurant_tables`
--
ALTER TABLE `restaurant_tables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `restaurant_tables_table_number_unique` (`table_number`);

--
-- Indexes for table `sales_settings`
--
ALTER TABLE `sales_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales_shifts`
--
ALTER TABLE `sales_shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sales_shifts_started_by_foreign` (`started_by`),
  ADD KEY `sales_shifts_business_date_index` (`business_date`),
  ADD KEY `sales_shifts_status_index` (`status`),
  ADD KEY `sales_shifts_ended_by_foreign` (`ended_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customer_bookings`
--
ALTER TABLE `customer_bookings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_account_verifications`
--
ALTER TABLE `pending_account_verifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `print_jobs`
--
ALTER TABLE `print_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restaurant_tables`
--
ALTER TABLE `restaurant_tables`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `sales_settings`
--
ALTER TABLE `sales_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_shifts`
--
ALTER TABLE `sales_shifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_sales_shift_id_foreign` FOREIGN KEY (`sales_shift_id`) REFERENCES `sales_shifts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `orders_waiter_id_foreign` FOREIGN KEY (`waiter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_shifts`
--
ALTER TABLE `sales_shifts`
  ADD CONSTRAINT `sales_shifts_ended_by_foreign` FOREIGN KEY (`ended_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_shifts_started_by_foreign` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`);
SET FOREIGN_KEY_CHECKS=1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
