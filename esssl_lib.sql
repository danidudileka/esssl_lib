-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 22, 2026 at 09:24 PM
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
-- Database: `esssl_lib`
--

-- --------------------------------------------------------

--
-- Stand-in structure for view `active_loans`
-- (See below for the actual view)
--
CREATE TABLE `active_loans` (
`loan_id` int(11)
,`member_id` int(11)
,`book_id` int(11)
,`loan_date` date
,`due_date` date
,`status` enum('active','returned','overdue','lost')
,`approval_status` enum('pending','approved','rejected')
,`fine_amount` decimal(10,2)
,`title` varchar(255)
,`author` varchar(255)
,`rack_number` varchar(20)
,`dewey_decimal_number` varchar(20)
,`first_name` varchar(100)
,`last_name` varchar(100)
,`member_code` varchar(20)
,`days_overdue` int(7)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `active_notifications`
-- (See below for the actual view)
--
CREATE TABLE `active_notifications` (
`notification_id` int(11)
,`member_id` int(11)
,`book_id` int(11)
,`loan_id` int(11)
,`reservation_id` int(11)
,`title` varchar(255)
,`message` text
,`type` enum('info','success','warning','danger')
,`is_read` tinyint(1)
,`rack_number` varchar(20)
,`additional_data` longtext
,`read_at` timestamp
,`created_at` timestamp
,`updated_at` timestamp
,`member_name` varchar(201)
,`member_email` varchar(255)
,`membership_type` enum('student','faculty','staff','public')
);

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','librarian','assistant') DEFAULT 'librarian',
  `status` enum('active','inactive') DEFAULT 'active',
  `remember_token` varchar(255) DEFAULT NULL,
  `token_expires` timestamp NULL DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `username`, `email`, `password_hash`, `full_name`, `role`, `status`, `remember_token`, `token_expires`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@esssl.com', '$2a$12$jtXkADVZsWVlx8PDzODN9utD66xVXegnHt0JHuL5BwRjpDH5HclI.', 'Library Administrator', 'super_admin', 'active', NULL, NULL, '2026-02-22 20:15:49', '2026-02-21 12:24:43', '2026-02-22 20:15:49'),
(2, 'librarian1', 'librarian@esssl.com', '$2y$10$aGc3suf3viabepPUEOnxCO9Npl3ueauOoRZthzdv6GIujEmF7IzMm', 'Sarah Johnson', 'librarian', 'active', NULL, NULL, '2026-02-22 19:30:09', '2026-02-21 12:25:28', '2026-02-22 19:30:09'),
(3, 'assistant1', 'assistant@esssl.com', '$2y$10$Ch.jZqhvlmzyBLopcvdnFef.vfZjL/OS8L75i893.q/rbKHxJRsRu', 'Mike Wilson', 'assistant', 'active', NULL, NULL, NULL, '2026-02-21 12:24:10', '2026-02-21 12:26:52');

-- --------------------------------------------------------

--
-- Table structure for table `admin_messages`
--

CREATE TABLE `admin_messages` (
  `message_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `target_type` enum('all','membership_type','specific_members','filter') DEFAULT 'all',
  `target_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Store filter criteria or member IDs' CHECK (json_valid(`target_data`)),
  `sent_to_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_messages`
--

INSERT INTO `admin_messages` (`message_id`, `admin_id`, `title`, `message`, `target_type`, `target_data`, `sent_to_count`, `created_at`) VALUES
(2, 2, 'test', 'test', 'all', '{\"type\":\"all_members\"}', 9, '2026-02-20 06:30:00'),
(3, 2, 'Sample Message', 'Sample Message', 'all', '{\"type\":\"all_members\"}', 9, '2026-02-20 06:30:00'),
(4, 2, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'all', '{\"type\":\"all_members\"}', 9, '2026-02-20 06:30:00');

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `book_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `isbn` varchar(20) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `publication_year` int(11) DEFAULT NULL,
  `genre` varchar(100) DEFAULT NULL,
  `dewey_decimal_number` varchar(20) DEFAULT NULL,
  `dewey_classification` varchar(200) DEFAULT NULL,
  `rack_number` varchar(20) DEFAULT NULL,
  `shelf_position` enum('Top','Middle','Bottom') DEFAULT 'Middle',
  `floor_level` int(11) DEFAULT 1,
  `total_copies` int(11) DEFAULT 1,
  `available_copies` int(11) DEFAULT 1,
  `description` text DEFAULT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `rating` decimal(2,1) DEFAULT 0.0,
  `pages` int(11) DEFAULT NULL,
  `language` varchar(50) DEFAULT 'English',
  `status` enum('active','inactive','damaged','lost') DEFAULT 'active',
  `added_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`book_id`, `title`, `author`, `isbn`, `publisher`, `publication_year`, `genre`, `dewey_decimal_number`, `dewey_classification`, `rack_number`, `shelf_position`, `floor_level`, `total_copies`, `available_copies`, `description`, `cover_image`, `rating`, `pages`, `language`, `status`, `added_date`, `updated_date`) VALUES
(83, '1984', 'George Orwell', '9780451524935', 'Signet Classic', 1950, 'Dystopian Fiction', '823.912', 'English Fiction', 'MUS-A-64', 'Middle', 1, 1, 0, 'A dystopian novel set in a totalitarian society ruled by Big Brother.', 'https://images-na.ssl-images-amazon.com/images/P/0451524934.01.L.jpg', 4.7, 328, 'English', 'inactive', '2025-08-28 15:23:14', '2026-02-22 14:52:22'),
(81, 'The Great Gatsby', 'F. Scott Fitzgerald', '9780743273565', 'Scribner', 2004, 'Classic Literature', '813.52', 'American Fiction', 'HIST-A-02', 'Middle', 1, 2, 2, 'A portrait of the Jazz Age in all of its decadence and excess.', 'https://images-na.ssl-images-amazon.com/images/P/0743273567.01.L.jpg', 4.6, 180, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(80, 'Brave New World', 'Aldous Huxley', '9780060850524', 'Harper Perennial', 2006, 'Science Fiction', '823.912', 'English Fiction', 'HIST-A-01', 'Top', 1, 2, 0, 'A futuristic society controlled by technology and conditioning.', 'https://images-na.ssl-images-amazon.com/images/P/0060850523.01.L.jpg', 4.5, 288, 'English', 'inactive', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(78, 'The Catcher in the Rye', 'J.D. Salinger', '9780316769488', 'Little, Brown and Company', 2001, 'Classic Literature', '813.54', 'American Fiction', 'LIT-A-02', 'Middle', 2, 2, 0, 'A story about teenage alienation and rebellion.', 'https://images-na.ssl-images-amazon.com/images/P/0316769487.01.L.jpg', 4.3, 277, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(77, 'Moby-Dick', 'Herman Melville', '9781503280786', 'CreateSpace Independent Publishing', 2014, 'Adventure Fiction', '813.3', 'American Fiction', 'LIT-A-01', 'Top', 2, 3, 0, 'The epic tale of Captain Ahab and the white whale.', 'https://images-na.ssl-images-amazon.com/images/P/1503280780.01.L.jpg', 4.2, 720, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 14:34:27'),
(75, 'War and Peace', 'Leo Tolstoy', '9781400079988', 'Vintage', 2007, 'Historical Fiction', '891.73', 'Russian Literature', 'MUS-A-01', 'Middle', 3, 2, 0, 'A sweeping narrative of Russian society during the Napoleonic Era.', 'https://m.media-amazon.com/images/I/41wJA+zum7L._SY445_SX342_FMwebp_.jpg', 4.6, 1296, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 14:34:32'),
(74, 'The Hobbit', 'J.R.R. Tolkien', '9780547928227', 'Mariner Books', 2012, 'Fantasy', '823.912', 'English Fantasy Fiction', 'ART-A-01', 'Top', 3, 2, 2, 'Bilbo Baggins embarks on an unexpected journey.', 'https://images-na.ssl-images-amazon.com/images/P/054792822X.01.L.jpg', 4.8, 300, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(73, 'The Lord of the Rings', 'J.R.R. Tolkien', '9780544003415', 'Mariner Books', 2012, 'Fantasy', '823.912', 'English Fantasy Fiction', 'ENV-A-01', 'Bottom', 3, 2, 0, 'An epic quest to destroy the One Ring.', 'https://images-na.ssl-images-amazon.com/images/P/0544003411.01.L.jpg', 4.9, 1216, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 14:27:41'),
(70, 'The Alchemist', 'Paulo Coelho', '9780062315007', 'HarperOne', 2014, 'Philosophical Fiction', '869.3', 'Brazilian Literature', 'MAT-A-01', 'Bottom', 1, 2, 1, 'A shepherd travels in search of treasure and destiny.', 'https://images-na.ssl-images-amazon.com/images/P/0062315005.01.L.jpg', 4.4, 208, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(69, 'The Da Vinci Code', 'Dan Brown', '9780307474278', 'Anchor', 2009, 'Thriller', '813.54', 'American Fiction', 'BIO-A-01', 'Middle', 1, 2, 0, 'A symbologist uncovers secrets hidden in art and history.', 'https://images-na.ssl-images-amazon.com/images/P/0307474275.01.L.jpg', 4.3, 489, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(68, 'The Shining', 'Stephen King', '9780307743657', 'Anchor', 2012, 'Horror', '813.54', 'American Fiction', 'SCI-A-01', 'Top', 1, 3, 2, 'A family heads to an isolated hotel for the winter.', 'https://images-na.ssl-images-amazon.com/images/P/0307743659.01.L.jpg', 4.7, 447, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(66, 'The Road', 'Cormac McCarthy', '9780307387899', 'Vintage', 2006, 'Post-Apocalyptic Fiction', '813.54', 'American Fiction', 'LAN-A-02', 'Middle', 2, 2, 2, 'A father and son journey through a devastated America.', 'https://images-na.ssl-images-amazon.com/images/P/0307387895.01.L.jpg', 4.5, 287, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 14:50:13'),
(64, 'The Girl with the Dragon Tattoo', 'Stieg Larsson', '9780307454546', 'Vintage Crime', 2009, 'Crime Thriller', '839.73', 'Swedish Fiction', 'ECO-A-01', 'Bottom', 2, 2, 1, 'A journalist and hacker investigate a disappearance.', 'https://images-na.ssl-images-amazon.com/images/P/0307454541.01.L.jpg', 4.6, 672, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(63, 'Gone Girl', 'Gillian Flynn', '9780307588371', 'Crown', 2012, 'Psychological Thriller', '813.6', 'American Fiction', 'SOC-A-02', 'Middle', 2, 2, 2, 'A twisted tale of marriage and deception.', 'https://images-na.ssl-images-amazon.com/images/P/0307588378.01.L.jpg', 4.4, 432, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 18:57:41'),
(62, 'The Hunger Games', 'Suzanne Collins', '9780439023528', 'Scholastic Press', 2008, 'Young Adult Dystopian', '813.6', 'American Fiction', 'SOC-A-01', 'Top', 2, 3, 0, 'A televised fight to the death in a dystopian society.', 'https://images-na.ssl-images-amazon.com/images/P/0439023521.01.L.jpg', 4.7, 374, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(61, 'The Fault in Our Stars', 'John Green', '9780525478812', 'Dutton Books', 2012, 'Young Adult Romance', '813.6', 'American Fiction', 'REL-C-01', 'Bottom', 2, 3, 2, 'Two teens fall in love while battling illness.', 'https://images-na.ssl-images-amazon.com/images/P/0525478817.01.L.jpg', 4.6, 313, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(59, 'The Chronicles of Narnia', 'C.S. Lewis', '9780066238500', 'HarperCollins', 2002, 'Fantasy', '823.912', 'English Fantasy Fiction', 'REL-A-01', 'Top', 2, 2, 2, 'Children discover a magical world called Narnia.', 'https://images-na.ssl-images-amazon.com/images/P/0066238501.01.L.jpg', 4.8, 768, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 19:01:19'),
(58, 'Life of Pi', 'Yann Martel', '9780156027328', 'Mariner Books', 2003, 'Adventure Fiction', '813.6', 'Canadian Fiction', 'PSY-A-02', 'Bottom', 1, 2, 0, 'A boy survives a shipwreck with a Bengal tiger.', 'https://images-na.ssl-images-amazon.com/images/P/0156027321.01.L.jpg', 4.4, 460, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(56, 'The Kite Runner', 'Khaled Hosseini', '9781594631931', 'Riverhead Books', 2013, 'Historical Fiction', '813.6', 'American Fiction', 'PSY-A-01', 'Top', 1, 2, 0, 'A story of friendship and redemption set in Afghanistan.', 'https://images-na.ssl-images-amazon.com/images/P/159463193X.01.L.jpg', 4.7, 372, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(54, 'The Book Thief', 'Markus Zusak', '9780375842207', 'Knopf Books', 2007, 'Historical Fiction', '823.92', 'Australian Fiction', 'CS-A-02', 'Middle', 1, 2, 0, 'A young girl steals books in Nazi Germany.', 'https://images-na.ssl-images-amazon.com/images/P/0375842209.01.L.jpg', 4.8, 584, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(55, 'Harry Potter and the Sorcerer\'s Stone', 'J.K. Rowling', '9780590353427', 'Scholastic', 1998, 'Fantasy', '823.914', 'English Fantasy Fiction', 'CS-A-03', 'Bottom', 1, 2, 1, 'A young boy discovers he is a wizard.', 'https://images-na.ssl-images-amazon.com/images/P/059035342X.01.L.jpg', 4.9, 320, 'English', 'active', '2025-08-28 15:18:21', '2026-02-18 17:57:11'),
(53, 'The Martian', 'Andy Weir', '9780553418026', 'Broadway Books', 2014, 'Science Fiction', '813.6', 'American Fiction', 'CS-A-01', 'Top', 1, 3, 3, 'An astronaut struggles to survive on Mars.', 'https://images-na.ssl-images-amazon.com/images/P/0553418025.01.L.jpg', 4.8, 369, 'English', 'active', '2025-08-28 15:18:21', '2026-02-22 14:46:50'),
(84, 'Percy Jackson and the Sea of Monsters', 'Rick Riordan', '978-0-141-34684-7', 'PUFFIN BOOKS', 2006, 'Fiction', '813.6', 'American Juvenile Literature', 'LIT-A-02', 'Middle', 1, 3, 2, 'The sea of monsters is an american fantasy-adventure novel based on greek mythology written by rick riordan and published in 2006. It is the second novel in the percy jackson & the olympians series and the sequel to the lightning thief', 'book_6999c02cd4486.jpg', 0.0, 265, 'English', 'active', '2026-02-21 14:24:44', '2026-02-22 14:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `book_loans`
--

CREATE TABLE `book_loans` (
  `loan_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `loan_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('active','returned','overdue','lost') DEFAULT 'active',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_date` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `fine_amount` decimal(10,2) DEFAULT 0.00,
  `renewal_count` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `book_loans`
--

INSERT INTO `book_loans` (`loan_id`, `member_id`, `book_id`, `loan_date`, `due_date`, `return_date`, `status`, `approval_status`, `approved_date`, `approved_by`, `rejection_reason`, `fine_amount`, `renewal_count`, `notes`, `created_at`, `updated_at`) VALUES
(48, 7, 81, '2025-08-28', '2025-09-11', NULL, 'returned', 'approved', '2025-08-28 15:35:28', 1, NULL, 0.00, 0, '', '2025-08-28 15:35:19', '2026-02-11 10:00:38'),
(50, 7, 66, '2025-08-28', '2025-09-11', NULL, 'active', 'pending', NULL, NULL, NULL, 0.00, 0, NULL, '2025-08-28 16:25:56', '2025-08-28 16:25:56'),
(51, 7, 55, '2025-08-28', '2025-09-11', NULL, 'active', 'pending', NULL, NULL, NULL, 0.00, 0, NULL, '2025-08-28 16:25:58', '2025-08-28 16:25:58'),
(52, 7, 58, '2025-08-28', '2025-09-11', NULL, 'active', 'approved', '2026-02-22 14:21:32', 1, NULL, 0.00, 0, NULL, '2025-08-28 16:26:00', '2026-02-22 14:21:32'),
(53, 7, 70, '2025-08-28', '2025-09-11', NULL, 'active', 'approved', '2026-02-22 14:35:27', 1, NULL, 0.00, 0, NULL, '2025-08-28 17:45:43', '2026-02-22 14:35:27'),
(47, 7, 80, '2025-08-28', '2025-09-11', NULL, 'active', 'pending', NULL, NULL, NULL, 0.00, 0, NULL, '2025-08-28 15:34:34', '2025-08-28 15:34:34'),
(46, 7, 68, '2025-08-28', '2025-09-11', NULL, 'returned', 'approved', '2025-08-28 15:33:45', 1, NULL, 0.00, 0, '', '2025-08-28 15:33:36', '2025-08-28 15:35:40'),
(45, 11, 66, '2025-08-27', '2025-09-27', NULL, '', 'rejected', NULL, NULL, '', 0.00, 0, 'Awaiting librarian approval', '2025-08-28 15:18:21', '2026-02-22 14:50:05'),
(43, 16, 74, '2025-07-20', '2025-08-20', '2025-08-18', 'returned', 'approved', '2025-07-20 04:30:00', 2, NULL, 0.00, 0, 'Returned on time', '2025-07-20 04:15:00', '2025-08-28 15:18:21'),
(42, 15, 68, '2025-08-01', '2025-09-01', '2025-08-25', 'returned', 'approved', '2025-08-01 10:30:00', 2, NULL, 0.00, 0, 'Returned early', '2025-08-01 10:00:00', '2025-08-28 15:18:21'),
(41, 14, 69, '2025-07-05', '2025-08-05', NULL, 'overdue', 'approved', '2025-07-05 06:30:00', 2, NULL, 98.50, 0, 'Final notice sent', '2025-07-05 06:00:00', '2026-02-18 18:14:49'),
(49, 7, 78, '2025-08-28', '2025-09-11', NULL, 'active', 'approved', '2025-08-28 15:59:10', 1, NULL, 80.00, 0, '', '2025-08-28 15:59:01', '2026-02-22 14:45:39'),
(40, 13, 56, '2025-07-10', '2025-08-10', NULL, 'overdue', 'approved', '2025-07-10 09:30:00', 2, NULL, 96.00, 0, 'Member contacted', '2025-07-10 09:15:00', '2026-02-18 18:14:49'),
(38, 11, 77, '2025-08-22', '2025-09-22', NULL, 'overdue', 'approved', '2025-08-22 05:30:00', 2, NULL, 74.50, 1, 'Renewed once', '2025-08-22 05:00:00', '2026-02-18 18:14:49'),
(37, 10, 62, '2025-08-20', '2025-09-20', NULL, 'overdue', 'approved', '2025-08-20 08:30:00', 2, NULL, 75.50, 0, NULL, '2025-08-20 08:15:00', '2026-02-18 18:14:49'),
(36, 9, 54, '2025-08-15', '2025-09-15', NULL, 'overdue', 'approved', '2025-08-15 04:30:00', 2, NULL, 78.00, 0, NULL, '2025-08-15 04:00:00', '2026-02-18 18:14:49'),
(54, 19, 83, '2026-02-18', '2026-03-04', NULL, 'active', 'approved', '2026-02-22 14:21:29', 1, NULL, 0.00, 0, NULL, '2026-02-18 18:20:01', '2026-02-22 14:21:29'),
(55, 19, 59, '2026-02-22', '2026-03-08', NULL, 'returned', 'approved', '2026-02-22 14:35:24', 1, NULL, 0.00, 0, '', '2026-02-22 14:27:36', '2026-02-22 19:01:19'),
(56, 19, 73, '2026-02-22', '2026-03-08', NULL, 'active', 'approved', '2026-02-22 14:35:22', 1, NULL, 0.00, 0, NULL, '2026-02-22 14:27:41', '2026-02-22 14:35:22'),
(57, 20, 84, '2026-02-22', '2026-03-08', NULL, 'active', 'approved', '2026-02-22 14:35:17', 1, NULL, 0.00, 0, NULL, '2026-02-22 14:34:24', '2026-02-22 14:35:17'),
(58, 20, 77, '2026-02-22', '2026-03-08', NULL, 'active', 'approved', '2026-02-22 14:35:19', 1, NULL, 0.00, 0, NULL, '2026-02-22 14:34:27', '2026-02-22 14:35:19'),
(59, 20, 75, '2026-02-22', '2026-03-08', NULL, 'active', 'approved', '2026-02-22 14:35:15', 1, NULL, 0.00, 0, NULL, '2026-02-22 14:34:32', '2026-02-22 14:35:15'),
(60, 19, 63, '2026-02-22', '2026-03-08', NULL, 'returned', 'approved', '2026-02-22 14:48:29', 1, NULL, 0.00, 0, '', '2026-02-22 14:39:33', '2026-02-22 18:57:41');

-- --------------------------------------------------------

--
-- Table structure for table `book_reservations`
--

CREATE TABLE `book_reservations` (
  `reservation_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `reservation_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_date` date NOT NULL,
  `status` enum('active','fulfilled','expired','cancelled') DEFAULT 'active',
  `priority_number` int(11) DEFAULT 1,
  `notification_sent` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `book_reservations`
--

INSERT INTO `book_reservations` (`reservation_id`, `member_id`, `book_id`, `reservation_date`, `expiry_date`, `status`, `priority_number`, `notification_sent`, `notes`, `created_at`, `updated_at`) VALUES
(8, 15, 54, '2025-08-28 15:18:21', '2025-09-04', 'active', 1, 0, 'High priority member', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(7, 13, 56, '2025-08-28 15:18:21', '2025-09-04', 'active', 1, 0, 'Waiting for return', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(9, 16, 62, '2025-08-28 15:18:21', '2025-09-04', 'active', 1, 0, 'Faculty reservation', '2025-08-28 15:18:21', '2025-08-28 15:18:21');

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `member_id` int(11) NOT NULL,
  `member_code` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `membership_type` enum('student','faculty','staff','public') DEFAULT 'student',
  `membership_expiry` date NOT NULL,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','suspended','expired') DEFAULT 'active',
  `profile_image` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`member_id`, `member_code`, `first_name`, `last_name`, `email`, `phone`, `address`, `membership_type`, `membership_expiry`, `registration_date`, `status`, `profile_image`, `password_hash`, `created_at`, `updated_at`) VALUES
(12, 'MEM104', 'Dilshan', 'Rajapaksa', 'dilshan.rajapaksa@email.lk', '0781234567', 'No 56, Negombo Road, Wattala', 'staff', '2026-03-31', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(11, 'MEM103', 'Chamari', 'Fernando', 'chamari.fernando@email.lk', '0701234567', 'No 12, Temple Road, Mount Lavinia', 'student', '2025-11-30', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(10, 'MEM102', 'Nuwan', 'Silva', 'nuwan.silva@email.lk', '0771234567', 'No 78, Kandy Road, Peradeniya', 'faculty', '2026-06-30', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(8, 'MEM002', 'test', 'test', 'test@test.lk', '0702906039', 'rter', 'faculty', '2026-08-27', '2025-08-27 06:23:23', 'suspended', NULL, '$2y$10$wQuqVsCUr.BP4cVdSne94OEblnWXUJ.KqHy29eYbMzIJkmDEOhkhy', '2025-08-27 06:23:23', '2025-08-27 09:00:28'),
(9, 'MEM101', 'Kasun', 'Perera', 'kasun.perera@email.lk', '0712345678', 'No 45, Galle Road, Colombo 03', 'student', '2025-12-31', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(7, 'MEM001', 'Sanura', 'Reshan', 'sanura@email.com', '0123456777', 'Kandy', 'student', '2025-09-18', '2025-08-25 07:49:49', 'active', NULL, '$2y$10$TsdqDIpYtfERlMqbh9L/iO9Tm/.UuvW.njUhRaXyOFpS0CExw9ATq', '2025-08-25 07:49:49', '2026-02-22 19:59:02'),
(13, 'MEM105', 'Madhavi', 'Gunasekara', 'madhavi.gunasekara@email.lk', '0751234567', 'No 23, Hill Street, Dehiwala', 'public', '2025-10-31', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(14, 'MEM106', 'Rohan', 'Wickramasinghe', 'rohan.wickrama@email.lk', '0761234567', 'No 89, Main Street, Gampaha', 'student', '2025-09-30', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(15, 'MEM107', 'Thilini', 'Abeywardena', 'thilini.abey@email.lk', '0791234567', 'No 34, Lake Road, Colombo 02', 'faculty', '2026-05-31', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(16, 'MEM108', 'Samantha', 'Jayasinghe', 'samantha.jaya@email.lk', '0721234567', 'No 67, Baseline Road, Colombo 09', 'staff', '2026-02-28', '2025-08-28 15:18:21', 'active', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(17, 'MEM109', 'Nadeeka', 'Rathnayake', 'nadeeka.rath@email.lk', '0741234567', 'No 15, School Lane, Kotte', 'student', '2025-08-31', '2025-08-28 15:18:21', 'suspended', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(18, 'MEM110', 'Lahiru', 'Mendis', 'lahiru.mendis@email.lk', '0731234567', 'No 91, New Road, Maharagama', 'public', '2025-07-31', '2025-08-28 15:18:21', 'expired', NULL, '$2y$10$SBMq.KCSOhB9.06ESsa.yuGIzewiJr56rv99bSTSS3GXAA70hQm/q', '2025-08-28 15:18:21', '2025-08-28 15:18:21'),
(19, 'MEM013', 'Danidu Dileka', 'Perera', 'danidu@email.com', '0123456789', 'Panadura', 'student', '2027-02-11', '2026-02-11 10:02:33', 'active', NULL, '$2y$10$Y29lPt/stPT.re/9Pb4PPO6yfv09svOlIcIp4/y4jYaU2W8A3VMHe', '2026-02-11 10:02:33', '2026-02-11 10:02:33'),
(20, 'MEM014', 'Dulmin', 'Theekshana', 'dulmint@email.com', '0112456789', 'No.20, Galle Road, Colombo 3', 'student', '2027-02-28', '2026-02-21 14:33:30', 'active', NULL, '$2y$10$tXQFH7DD7PeLRfdno3LdwOrAQMXoZKsZoDIm/y1T.OTHwRNWl9OFe', '2026-02-21 14:33:30', '2026-02-22 15:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `member_favorites`
--

CREATE TABLE `member_favorites` (
  `favorite_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `added_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `member_favorites`
--

INSERT INTO `member_favorites` (`favorite_id`, `member_id`, `book_id`, `added_date`) VALUES
(62, 7, 78, '2025-08-28 15:35:53'),
(61, 7, 53, '2025-08-28 15:35:52'),
(60, 7, 83, '2025-08-28 15:35:50'),
(59, 7, 68, '2025-08-28 15:35:48'),
(57, 15, 74, '2025-08-28 15:18:21'),
(56, 14, 69, '2025-08-28 15:18:21'),
(55, 13, 58, '2025-08-28 15:18:21'),
(52, 11, 77, '2025-08-28 15:18:21'),
(51, 10, 68, '2025-08-28 15:18:21'),
(50, 10, 62, '2025-08-28 15:18:21'),
(63, 7, 81, '2025-08-28 15:56:41'),
(48, 9, 53, '2025-08-28 15:18:21'),
(47, 9, 54, '2025-08-28 15:18:21'),
(67, 19, 55, '2026-02-22 18:34:35'),
(66, 19, 84, '2026-02-22 18:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `member_notifications`
--

CREATE TABLE `member_notifications` (
  `notification_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) DEFAULT NULL,
  `loan_id` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `type` enum('info','success','warning','danger') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `rack_number` varchar(20) DEFAULT NULL,
  `additional_data` longtext DEFAULT NULL CHECK (json_valid(`additional_data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `member_notifications`
--

INSERT INTO `member_notifications` (`notification_id`, `member_id`, `book_id`, `loan_id`, `reservation_id`, `title`, `message`, `type`, `is_read`, `rack_number`, `additional_data`, `read_at`, `created_at`, `updated_at`) VALUES
(193, 13, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(194, 14, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(195, 15, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(196, 16, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(190, 10, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(191, 9, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(192, 7, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(164, 7, 66, 50, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'On Writing\' by Stephen King. Waiting for librarian approval.', 'info', 0, 'LAN-A-02', '{\"rack_number\":\"LAN-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":\"2\",\"dewey_decimal\":\"808.3\",\"dewey_classification\":\"Creative Writing\"}', NULL, '2025-08-28 16:25:56', '2025-08-28 16:25:56'),
(165, 7, 55, 51, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'The Information\' by James Gleick. Waiting for librarian approval.', 'info', 0, 'CS-A-03', '{\"rack_number\":\"CS-A-03\",\"shelf_position\":\"Bottom\",\"floor_level\":\"1\",\"dewey_decimal\":\"003\",\"dewey_classification\":\"Information Systems\"}', NULL, '2025-08-28 16:25:58', '2025-08-28 16:25:58'),
(166, 7, 58, 52, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'The Art of Happiness\' by Dalai Lama XIV. Waiting for librarian approval.', 'info', 0, 'PSY-A-02', '{\"rack_number\":\"PSY-A-02\",\"shelf_position\":\"Bottom\",\"floor_level\":\"1\",\"dewey_decimal\":\"158\",\"dewey_classification\":\"Applied Psychology\"}', NULL, '2025-08-28 16:26:00', '2025-08-28 16:26:00'),
(167, 14, 69, 41, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: The Selfish Gene\nAuthor: Richard Dawkins\nDue Date: Aug 5, 2025\nDays Overdue: 23 days\nFine Amount: $11.50\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2025-08-28 17:09:11', '2025-08-28 17:09:11'),
(168, 13, 56, 40, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: Thinking, Fast and Slow\nAuthor: Daniel Kahneman\nDue Date: Aug 10, 2025\nDays Overdue: 18 days\nFine Amount: $9.00\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2025-08-28 17:09:11', '2025-08-28 17:09:11'),
(169, 7, 70, 53, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'The Man Who Loved Only Numbers\' by Paul Hoffman. Waiting for librarian approval.', 'info', 0, 'MAT-A-01', '{\"rack_number\":\"MAT-A-01\",\"shelf_position\":\"Bottom\",\"floor_level\":\"1\",\"dewey_decimal\":\"510\",\"dewey_classification\":\"Mathematics Biography\"}', NULL, '2025-08-28 17:45:43', '2025-08-28 17:45:43'),
(170, 12, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(171, 11, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(172, 10, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(173, 9, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(174, 7, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(175, 13, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(176, 14, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(177, 15, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(178, 16, NULL, NULL, NULL, 'test', 'test', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:06:57', '2025-08-29 07:06:57'),
(179, 12, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(180, 11, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(181, 10, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(182, 9, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(183, 7, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(184, 13, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(185, 14, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(186, 15, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(187, 16, NULL, NULL, NULL, 'Sample Message', 'Sample Message', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:15', '2025-08-29 07:07:15'),
(188, 12, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(189, 11, NULL, NULL, NULL, 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds) Live Data Refresh: Bulletproof auto-refresh system (every 2 seconds)', 'info', 0, NULL, NULL, NULL, '2025-08-29 07:07:37', '2025-08-29 07:07:37'),
(155, 7, 68, 46, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'A Brief History of Time\' by Stephen Hawking. Waiting for librarian approval.', 'info', 0, 'SCI-A-01', '{\"rack_number\":\"SCI-A-01\",\"shelf_position\":\"Top\",\"floor_level\":\"1\",\"dewey_decimal\":\"523.1\",\"dewey_classification\":\"Cosmology\"}', NULL, '2025-08-28 15:33:36', '2025-08-28 15:33:36'),
(163, 7, NULL, NULL, NULL, 'Book Status Updated', 'Your book reservation status has been updated to: overdue', 'info', 0, NULL, NULL, NULL, '2025-08-28 15:59:24', '2025-08-28 15:59:24'),
(160, 7, NULL, NULL, NULL, 'Book Status Updated', 'Your book reservation status has been updated to: returned', 'info', 0, NULL, NULL, NULL, '2025-08-28 15:35:40', '2025-08-28 15:35:40'),
(159, 7, 81, 48, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: Guns, Germs, and Steel\nAuthor: Jared Diamond\nBook ID: 81\nDue Date: Sep 11, 2025\n\nLocation Details:\nDewey Decimal: 930\nClassification: Ancient History\nRack Number: HIST-A-02\nShelf Position: Middle\nFloor Level: 1\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 1\n• Find Rack HIST-A-02\n• Look for the book on Middle shelf\n• Present your member ID at the counter', 'success', 0, 'HIST-A-02', '{\"rack_number\":\"HIST-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":\"1\",\"dewey_decimal\":\"930\",\"dewey_classification\":\"Ancient History\"}', NULL, '2025-08-28 15:35:28', '2025-08-28 15:35:28'),
(158, 7, 81, 48, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'Guns, Germs, and Steel\' by Jared Diamond. Waiting for librarian approval.', 'info', 0, 'HIST-A-02', '{\"rack_number\":\"HIST-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":\"1\",\"dewey_decimal\":\"930\",\"dewey_classification\":\"Ancient History\"}', NULL, '2025-08-28 15:35:19', '2025-08-28 15:35:19'),
(157, 7, 80, 47, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'A People\'s History of the World\' by Chris Harman. Waiting for librarian approval.', 'info', 0, 'HIST-A-01', '{\"rack_number\":\"HIST-A-01\",\"shelf_position\":\"Top\",\"floor_level\":\"1\",\"dewey_decimal\":\"909\",\"dewey_classification\":\"World History\"}', NULL, '2025-08-28 15:34:34', '2025-08-28 15:34:34'),
(156, 7, 68, 46, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: A Brief History of Time\nAuthor: Stephen Hawking\nBook ID: 68\nDue Date: Sep 11, 2025\n\nLocation Details:\nDewey Decimal: 523.1\nClassification: Cosmology\nRack Number: SCI-A-01\nShelf Position: Top\nFloor Level: 1\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 1\n• Find Rack SCI-A-01\n• Look for the book on Top shelf\n• Present your member ID at the counter', 'success', 0, 'SCI-A-01', '{\"rack_number\":\"SCI-A-01\",\"shelf_position\":\"Top\",\"floor_level\":\"1\",\"dewey_decimal\":\"523.1\",\"dewey_classification\":\"Cosmology\"}', NULL, '2025-08-28 15:33:45', '2025-08-28 15:33:45'),
(154, 12, NULL, NULL, NULL, 'Payment Received', 'Your fine payment of LKR 6.50 has been received. Thank you!', 'success', 1, NULL, '{\"amount\":6.50,\"payment_method\":\"cash\"}', NULL, '2025-08-26 10:35:00', '2025-08-28 15:18:22'),
(161, 7, 78, 49, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'One Hundred Years of Solitude\' by Gabriel García Márquez. Waiting for librarian approval.', 'info', 0, 'LIT-A-02', '{\"rack_number\":\"LIT-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":\"2\",\"dewey_decimal\":\"863\",\"dewey_classification\":\"Spanish Literature\"}', NULL, '2025-08-28 15:59:01', '2025-08-28 15:59:01'),
(152, 9, 54, 36, NULL, 'Due Date Reminder', 'Your book \"Clean Code\" is due for return on September 15, 2025. Please return or renew before the due date.', 'warning', 0, 'CS-A-02', '{\"due_date\":\"2025-09-15\"}', NULL, '2025-08-27 04:30:00', '2025-08-28 15:18:22'),
(153, 17, NULL, NULL, NULL, 'Membership Expiring Soon', 'Your membership expires on August 31, 2025. Please renew to continue using library services.', 'warning', 0, NULL, '{\"expiry_date\":\"2025-08-31\"}', NULL, '2025-08-28 15:18:22', '2025-08-28 15:18:22'),
(162, 7, 78, 49, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: One Hundred Years of Solitude\nAuthor: Gabriel García Márquez\nBook ID: 78\nDue Date: Sep 11, 2025\n\nLocation Details:\nDewey Decimal: 863\nClassification: Spanish Literature\nRack Number: LIT-A-02\nShelf Position: Middle\nFloor Level: 2\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 2\n• Find Rack LIT-A-02\n• Look for the book on Middle shelf\n• Present your member ID at the counter', 'success', 0, 'LIT-A-02', '{\"rack_number\":\"LIT-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":\"2\",\"dewey_decimal\":\"863\",\"dewey_classification\":\"Spanish Literature\"}', NULL, '2025-08-28 15:59:10', '2025-08-28 15:59:10'),
(150, 13, 56, 40, NULL, 'Book Overdue - Action Required', 'Your book \"Thinking, Fast and Slow\" is overdue. Please return immediately. Current fine: LKR 9.00', 'danger', 0, 'PSY-A-01', '{\"days_overdue\":18,\"fine_amount\":9.00}', NULL, '2025-08-27 02:30:00', '2025-08-28 15:18:22'),
(148, 9, 54, 36, NULL, 'Book Approved - Ready for Collection', 'Your book reservation for \"Clean Code\" has been approved and is ready for collection.', 'success', 0, 'CS-A-02', '{\"rack_number\":\"CS-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":\"1\"}', NULL, '2025-08-15 04:30:00', '2025-08-28 15:18:22'),
(146, 9, NULL, NULL, NULL, 'Welcome to ABC Library', 'Welcome to ABC Library! Your account has been created successfully. Your member code is MEM101.', 'success', 1, NULL, NULL, NULL, '2025-01-15 04:35:00', '2025-08-28 15:18:22'),
(147, 10, NULL, NULL, NULL, 'Welcome to ABC Library', 'Welcome to ABC Library! Your account has been created successfully. Your member code is MEM102.', 'success', 1, NULL, NULL, NULL, '2025-01-20 08:35:00', '2025-08-28 15:18:22'),
(197, 7, NULL, NULL, NULL, 'Book Status Updated', 'Your book reservation status has been updated to: returned', 'info', 0, NULL, NULL, NULL, '2026-02-11 10:00:38', '2026-02-11 10:00:38'),
(198, 19, NULL, NULL, NULL, 'Welcome to ABC Library', 'Welcome to ABC Library!\n\nYour account has been created successfully.\n\nLogin Details:\nEmail: danidu@email.com\nPassword: crv96rDe\nMember Code: MEM013\n\nPlease change your password after first login.\nYour membership expires on: Feb 11, 2027', 'success', 1, NULL, NULL, '2026-02-11 10:04:32', '2026-02-11 10:02:33', '2026-02-11 10:04:32'),
(199, 14, 69, 41, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: The Da Vinci Code\nAuthor: Dan Brown\nDue Date: Aug 5, 2025\nDays Overdue: 197 days\nFine Amount: $98.50\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2026-02-18 18:14:49', '2026-02-18 18:14:49'),
(200, 7, 78, 49, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: The Catcher in the Rye\nAuthor: J.D. Salinger\nDue Date: Sep 11, 2025\nDays Overdue: 160 days\nFine Amount: $80.00\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2026-02-18 18:14:49', '2026-02-18 18:14:49'),
(201, 13, 56, 40, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: The Kite Runner\nAuthor: Khaled Hosseini\nDue Date: Aug 10, 2025\nDays Overdue: 192 days\nFine Amount: $96.00\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2026-02-18 18:14:49', '2026-02-18 18:14:49'),
(202, 11, 77, 38, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: Moby-Dick\nAuthor: Herman Melville\nDue Date: Sep 22, 2025\nDays Overdue: 149 days\nFine Amount: $74.50\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2026-02-18 18:14:49', '2026-02-18 18:14:49'),
(203, 10, 62, 37, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: The Hunger Games\nAuthor: Suzanne Collins\nDue Date: Sep 20, 2025\nDays Overdue: 151 days\nFine Amount: $75.50\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2026-02-18 18:14:49', '2026-02-18 18:14:49'),
(204, 9, 54, 36, NULL, 'Book Overdue - Action Required', 'BOOK OVERDUE NOTICE\n\nBook: The Book Thief\nAuthor: Markus Zusak\nDue Date: Sep 15, 2025\nDays Overdue: 156 days\nFine Amount: $78.00\n\nPlease return this book immediately to avoid additional fines.\nContact the library if you need assistance.', 'danger', 0, NULL, NULL, NULL, '2026-02-18 18:14:49', '2026-02-18 18:14:49'),
(205, 19, 83, 54, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'1984\' by George Orwell. Waiting for librarian approval.', 'info', 0, 'MUS-A-64', '{\"rack_number\":\"MUS-A-64\",\"shelf_position\":\"Middle\",\"floor_level\":1,\"dewey_decimal\":\"823.912\",\"dewey_classification\":\"English Fiction\"}', NULL, '2026-02-18 18:20:01', '2026-02-18 18:20:01'),
(206, 20, NULL, NULL, NULL, 'Welcome to ABC Library', 'Welcome to ABC Library!\n\nYour account has been created successfully.\n\nLogin Details:\nEmail: dulmint@email.com\nPassword: VrGTFeTj\nMember Code: MEM014\n\nPlease change your password after first login.\nYour membership expires on: Feb 21, 2027', 'success', 0, NULL, NULL, NULL, '2026-02-21 14:33:30', '2026-02-21 14:33:30'),
(207, 19, 83, 54, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: 1984\nAuthor: George Orwell\nBook ID: 83\nDue Date: Mar 4, 2026\n\nLocation Details:\nDewey Decimal: 823.912\nClassification: English Fiction\nRack Number: MUS-A-64\nShelf Position: Middle\nFloor Level: 1\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 1\n• Find Rack MUS-A-64\n• Look for the book on Middle shelf\n• Present your member ID at the counter', 'success', 0, 'MUS-A-64', '{\"rack_number\":\"MUS-A-64\",\"shelf_position\":\"Middle\",\"floor_level\":1,\"dewey_decimal\":\"823.912\",\"dewey_classification\":\"English Fiction\"}', NULL, '2026-02-22 14:21:29', '2026-02-22 14:21:29'),
(208, 7, 58, 52, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: Life of Pi\nAuthor: Yann Martel\nBook ID: 58\nDue Date: Sep 11, 2025\n\nLocation Details:\nDewey Decimal: 813.6\nClassification: Canadian Fiction\nRack Number: PSY-A-02\nShelf Position: Bottom\nFloor Level: 1\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 1\n• Find Rack PSY-A-02\n• Look for the book on Bottom shelf\n• Present your member ID at the counter', 'success', 0, 'PSY-A-02', '{\"rack_number\":\"PSY-A-02\",\"shelf_position\":\"Bottom\",\"floor_level\":1,\"dewey_decimal\":\"813.6\",\"dewey_classification\":\"Canadian Fiction\"}', NULL, '2026-02-22 14:21:32', '2026-02-22 14:21:32'),
(209, 19, 59, 55, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'The Chronicles of Narnia\' by C.S. Lewis. Waiting for librarian approval.', 'info', 0, 'REL-A-01', '{\"rack_number\":\"REL-A-01\",\"shelf_position\":\"Top\",\"floor_level\":2,\"dewey_decimal\":\"823.912\",\"dewey_classification\":\"English Fantasy Fiction\"}', NULL, '2026-02-22 14:27:36', '2026-02-22 14:27:36'),
(210, 19, 73, 56, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'The Lord of the Rings\' by J.R.R. Tolkien. Waiting for librarian approval.', 'info', 0, 'ENV-A-01', '{\"rack_number\":\"ENV-A-01\",\"shelf_position\":\"Bottom\",\"floor_level\":3,\"dewey_decimal\":\"823.912\",\"dewey_classification\":\"English Fantasy Fiction\"}', NULL, '2026-02-22 14:27:41', '2026-02-22 14:27:41'),
(211, 20, 84, 57, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'Percy Jackson and the Sea of Monsters\' by Rick Riordan. Waiting for librarian approval.', 'info', 0, 'LIT-A-02', '{\"rack_number\":\"LIT-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":1,\"dewey_decimal\":\"813.6\",\"dewey_classification\":\"American Juvenile Literature\"}', NULL, '2026-02-22 14:34:24', '2026-02-22 14:34:24'),
(212, 20, 77, 58, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'Moby-Dick\' by Herman Melville. Waiting for librarian approval.', 'info', 0, 'LIT-A-01', '{\"rack_number\":\"LIT-A-01\",\"shelf_position\":\"Top\",\"floor_level\":2,\"dewey_decimal\":\"813.3\",\"dewey_classification\":\"American Fiction\"}', NULL, '2026-02-22 14:34:27', '2026-02-22 14:34:27'),
(213, 20, 75, 59, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'War and Peace\' by Leo Tolstoy. Waiting for librarian approval.', 'info', 0, 'MUS-A-01', '{\"rack_number\":\"MUS-A-01\",\"shelf_position\":\"Middle\",\"floor_level\":3,\"dewey_decimal\":\"891.73\",\"dewey_classification\":\"Russian Literature\"}', NULL, '2026-02-22 14:34:32', '2026-02-22 14:34:32'),
(214, 20, 75, 59, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: War and Peace\nAuthor: Leo Tolstoy\nBook ID: 75\nDue Date: Mar 8, 2026\n\nLocation Details:\nDewey Decimal: 891.73\nClassification: Russian Literature\nRack Number: MUS-A-01\nShelf Position: Middle\nFloor Level: 3\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 3\n• Find Rack MUS-A-01\n• Look for the book on Middle shelf\n• Present your member ID at the counter', 'success', 0, 'MUS-A-01', '{\"rack_number\":\"MUS-A-01\",\"shelf_position\":\"Middle\",\"floor_level\":3,\"dewey_decimal\":\"891.73\",\"dewey_classification\":\"Russian Literature\"}', NULL, '2026-02-22 14:35:15', '2026-02-22 14:35:15'),
(215, 20, 84, 57, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: Percy Jackson and the Sea of Monsters\nAuthor: Rick Riordan\nBook ID: 84\nDue Date: Mar 8, 2026\n\nLocation Details:\nDewey Decimal: 813.6\nClassification: American Juvenile Literature\nRack Number: LIT-A-02\nShelf Position: Middle\nFloor Level: 1\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 1\n• Find Rack LIT-A-02\n• Look for the book on Middle shelf\n• Present your member ID at the counter', 'success', 0, 'LIT-A-02', '{\"rack_number\":\"LIT-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":1,\"dewey_decimal\":\"813.6\",\"dewey_classification\":\"American Juvenile Literature\"}', NULL, '2026-02-22 14:35:17', '2026-02-22 14:35:17'),
(216, 20, 77, 58, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: Moby-Dick\nAuthor: Herman Melville\nBook ID: 77\nDue Date: Mar 8, 2026\n\nLocation Details:\nDewey Decimal: 813.3\nClassification: American Fiction\nRack Number: LIT-A-01\nShelf Position: Top\nFloor Level: 2\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 2\n• Find Rack LIT-A-01\n• Look for the book on Top shelf\n• Present your member ID at the counter', 'success', 0, 'LIT-A-01', '{\"rack_number\":\"LIT-A-01\",\"shelf_position\":\"Top\",\"floor_level\":2,\"dewey_decimal\":\"813.3\",\"dewey_classification\":\"American Fiction\"}', NULL, '2026-02-22 14:35:19', '2026-02-22 14:35:19'),
(217, 19, 73, 56, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: The Lord of the Rings\nAuthor: J.R.R. Tolkien\nBook ID: 73\nDue Date: Mar 8, 2026\n\nLocation Details:\nDewey Decimal: 823.912\nClassification: English Fantasy Fiction\nRack Number: ENV-A-01\nShelf Position: Bottom\nFloor Level: 3\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 3\n• Find Rack ENV-A-01\n• Look for the book on Bottom shelf\n• Present your member ID at the counter', 'success', 0, 'ENV-A-01', '{\"rack_number\":\"ENV-A-01\",\"shelf_position\":\"Bottom\",\"floor_level\":3,\"dewey_decimal\":\"823.912\",\"dewey_classification\":\"English Fantasy Fiction\"}', NULL, '2026-02-22 14:35:22', '2026-02-22 14:35:22'),
(218, 19, 59, 55, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: The Chronicles of Narnia\nAuthor: C.S. Lewis\nBook ID: 59\nDue Date: Mar 8, 2026\n\nLocation Details:\nDewey Decimal: 823.912\nClassification: English Fantasy Fiction\nRack Number: REL-A-01\nShelf Position: Top\nFloor Level: 2\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 2\n• Find Rack REL-A-01\n• Look for the book on Top shelf\n• Present your member ID at the counter', 'success', 0, 'REL-A-01', '{\"rack_number\":\"REL-A-01\",\"shelf_position\":\"Top\",\"floor_level\":2,\"dewey_decimal\":\"823.912\",\"dewey_classification\":\"English Fantasy Fiction\"}', NULL, '2026-02-22 14:35:24', '2026-02-22 14:35:24'),
(219, 7, 70, 53, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: The Alchemist\nAuthor: Paulo Coelho\nBook ID: 70\nDue Date: Sep 11, 2025\n\nLocation Details:\nDewey Decimal: 869.3\nClassification: Brazilian Literature\nRack Number: MAT-A-01\nShelf Position: Bottom\nFloor Level: 1\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 1\n• Find Rack MAT-A-01\n• Look for the book on Bottom shelf\n• Present your member ID at the counter', 'success', 0, 'MAT-A-01', '{\"rack_number\":\"MAT-A-01\",\"shelf_position\":\"Bottom\",\"floor_level\":1,\"dewey_decimal\":\"869.3\",\"dewey_classification\":\"Brazilian Literature\"}', NULL, '2026-02-22 14:35:27', '2026-02-22 14:35:27'),
(220, 19, 63, 60, NULL, 'Book Reservation Submitted', 'Book reservation request submitted for \'Gone Girl\' by Gillian Flynn. Waiting for librarian approval.', 'info', 0, 'SOC-A-02', '{\"rack_number\":\"SOC-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":2,\"dewey_decimal\":\"813.6\",\"dewey_classification\":\"American Fiction\"}', NULL, '2026-02-22 14:39:33', '2026-02-22 14:39:33'),
(221, 7, NULL, NULL, NULL, 'Book Status Updated', 'Your book reservation status has been updated to: active', 'info', 0, NULL, NULL, NULL, '2026-02-22 14:45:39', '2026-02-22 14:45:39'),
(222, 19, 63, 60, NULL, 'Book Approved - Ready for Collection', 'BOOK APPROVED - Ready for Collection!\n\nBook: Gone Girl\nAuthor: Gillian Flynn\nBook ID: 63\nDue Date: Mar 8, 2026\n\nLocation Details:\nDewey Decimal: 813.6\nClassification: American Fiction\nRack Number: SOC-A-02\nShelf Position: Middle\nFloor Level: 2\n\nCollection Instructions:\n• Visit the library during operating hours\n• Go to Floor 2\n• Find Rack SOC-A-02\n• Look for the book on Middle shelf\n• Present your member ID at the counter', 'success', 0, 'SOC-A-02', '{\"rack_number\":\"SOC-A-02\",\"shelf_position\":\"Middle\",\"floor_level\":2,\"dewey_decimal\":\"813.6\",\"dewey_classification\":\"American Fiction\"}', NULL, '2026-02-22 14:48:29', '2026-02-22 14:48:29'),
(223, 11, 66, 45, NULL, 'Book Loan Request Rejected', 'BOOK LOAN REQUEST REJECTED\n\nBook: The Road\nAuthor: Cormac McCarthy\nBook ID: 66\n\nNext Steps:\n• You can try borrowing other available books\n• Contact the library for more information\n• Check back later for availability', 'warning', 0, NULL, NULL, NULL, '2026-02-22 14:50:05', '2026-02-22 14:50:05'),
(224, 11, 66, 45, NULL, 'Book Loan Request Rejected', 'BOOK LOAN REQUEST REJECTED\n\nBook: The Road\nAuthor: Cormac McCarthy\nBook ID: 66\n\nNext Steps:\n• You can try borrowing other available books\n• Contact the library for more information\n• Check back later for availability', 'warning', 0, NULL, NULL, NULL, '2026-02-22 14:50:13', '2026-02-22 14:50:13'),
(225, 19, NULL, NULL, NULL, 'Registration Payment Processed', 'Welcome to ESSSL Library!\n\nYour registration payment has been processed successfully.\nAmount: LKR 1,000.00\nPayment Method: Cash\n\nYou can now enjoy all library services!', 'success', 0, NULL, NULL, NULL, '2026-02-22 15:10:16', '2026-02-22 15:10:16'),
(226, 20, NULL, NULL, NULL, 'Membership Renewed', 'Your membership has been successfully renewed!\n\nRenewal Type: Weekly\nNew Expiry Date: Feb 28, 2027\nAmount Paid: LKR 2,500.00\nPayment Method: Cash', 'success', 0, NULL, NULL, NULL, '2026-02-22 15:14:23', '2026-02-22 15:14:23'),
(227, 19, NULL, NULL, NULL, 'Book Status Updated', 'Your book reservation status has been updated to: returned', 'info', 0, NULL, NULL, NULL, '2026-02-22 18:57:41', '2026-02-22 18:57:41'),
(228, 19, NULL, NULL, NULL, 'Book Status Updated', 'Your book reservation status has been updated to: returned', 'info', 0, NULL, NULL, NULL, '2026-02-22 19:01:19', '2026-02-22 19:01:19');

-- --------------------------------------------------------

--
-- Table structure for table `overdue_books`
--

CREATE TABLE `overdue_books` (
  `loan_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `book_id` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `fine_amount` decimal(10,2) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `days_overdue` int(7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `payment_type` enum('new_registration','renewal','fine','other') DEFAULT 'renewal',
  `payment_method` enum('cash','card') DEFAULT 'cash',
  `amount` decimal(10,2) NOT NULL,
  `renewal_type` enum('weekly','monthly','yearly') DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL COMMENT 'admin_id who processed payment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `member_id`, `payment_type`, `payment_method`, `amount`, `renewal_type`, `payment_date`, `notes`, `processed_by`, `created_at`) VALUES
(9, 9, 'renewal', 'cash', 1200.00, 'yearly', '2025-08-01 03:30:00', 'Annual renewal', 2, '2025-08-01 03:30:00'),
(8, 11, 'new_registration', 'cash', 500.00, NULL, '2025-02-10 05:30:00', 'Student registration fee', 2, '2025-02-10 05:30:00'),
(7, 10, 'new_registration', 'card', 1500.00, NULL, '2025-01-20 08:30:00', 'Faculty registration fee', 2, '2025-01-20 08:30:00'),
(6, 9, 'new_registration', 'cash', 500.00, NULL, '2025-01-15 04:30:00', 'Student registration fee', 2, '2025-01-15 04:30:00'),
(10, 10, 'renewal', 'card', 2000.00, 'yearly', '2025-07-15 09:30:00', 'Faculty annual renewal', 2, '2025-07-15 09:30:00'),
(11, 12, 'renewal', 'cash', 400.00, 'monthly', '2025-08-20 06:30:00', 'Monthly staff renewal', 2, '2025-08-20 06:30:00'),
(12, 13, 'renewal', 'cash', 100.00, 'weekly', '2025-08-25 05:00:00', 'Weekly public renewal', 2, '2025-08-25 05:00:00'),
(13, 12, 'fine', 'cash', 6.50, NULL, '2025-08-26 10:30:00', 'Overdue fine for The Pragmatic Programmer', 2, '2025-08-26 10:30:00'),
(14, 13, 'fine', 'cash', 9.00, NULL, '2025-08-27 05:30:00', 'Overdue fine for Thinking, Fast and Slow', 2, '2025-08-27 05:30:00'),
(15, 15, 'other', 'card', 250.00, NULL, '2025-08-10 07:30:00', 'Lost book replacement - damaged cover', 2, '2025-08-10 07:30:00'),
(16, 19, 'new_registration', 'cash', 1000.00, '', '2026-02-22 15:10:16', '', 1, '2026-02-22 15:10:16'),
(17, 20, 'renewal', 'cash', 2500.00, 'weekly', '2026-02-22 15:14:23', '', 1, '2026-02-22 15:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`setting_id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'site_name', 'ESSSL Library Management System', '2026-02-21 12:28:22'),
(2, 'site_description', 'Your Gateway to Knowledge', '2025-08-02 19:51:49'),
(3, 'site_logo', 'logo.png', '2025-08-02 19:51:49'),
(4, 'site_favicon', 'favicon.ico', '2025-08-02 19:51:49');

-- --------------------------------------------------------

--
-- Structure for view `active_loans`
--
DROP TABLE IF EXISTS `active_loans`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_loans`  AS SELECT `bl`.`loan_id` AS `loan_id`, `bl`.`member_id` AS `member_id`, `bl`.`book_id` AS `book_id`, `bl`.`loan_date` AS `loan_date`, `bl`.`due_date` AS `due_date`, `bl`.`status` AS `status`, `bl`.`approval_status` AS `approval_status`, `bl`.`fine_amount` AS `fine_amount`, `b`.`title` AS `title`, `b`.`author` AS `author`, `b`.`rack_number` AS `rack_number`, `b`.`dewey_decimal_number` AS `dewey_decimal_number`, `m`.`first_name` AS `first_name`, `m`.`last_name` AS `last_name`, `m`.`member_code` AS `member_code`, to_days(current_timestamp()) - to_days(`bl`.`due_date`) AS `days_overdue` FROM ((`book_loans` `bl` join `books` `b` on(`bl`.`book_id` = `b`.`book_id`)) join `members` `m` on(`bl`.`member_id` = `m`.`member_id`)) WHERE `bl`.`status` in ('active','overdue') ;

-- --------------------------------------------------------

--
-- Structure for view `active_notifications`
--
DROP TABLE IF EXISTS `active_notifications`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_notifications`  AS SELECT `mn`.`notification_id` AS `notification_id`, `mn`.`member_id` AS `member_id`, `mn`.`book_id` AS `book_id`, `mn`.`loan_id` AS `loan_id`, `mn`.`reservation_id` AS `reservation_id`, `mn`.`title` AS `title`, `mn`.`message` AS `message`, `mn`.`type` AS `type`, `mn`.`is_read` AS `is_read`, `mn`.`rack_number` AS `rack_number`, `mn`.`additional_data` AS `additional_data`, `mn`.`read_at` AS `read_at`, `mn`.`created_at` AS `created_at`, `mn`.`updated_at` AS `updated_at`, concat(`m`.`first_name`,' ',`m`.`last_name`) AS `member_name`, `m`.`email` AS `member_email`, `m`.`membership_type` AS `membership_type` FROM (`member_notifications` `mn` join `members` `m` on(`mn`.`member_id` = `m`.`member_id`)) WHERE `mn`.`is_read` = 0 ORDER BY `mn`.`created_at` DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`) USING HASH;

--
-- Indexes for table `admin_messages`
--
ALTER TABLE `admin_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_admin_messages_member_notifications` (`admin_id`,`created_at`),
  ADD KEY `idx_admin_messages_target_type` (`target_type`,`created_at`),
  ADD KEY `idx_admin_messages_sent_count` (`sent_to_count`,`created_at`);

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`book_id`),
  ADD UNIQUE KEY `isbn` (`isbn`),
  ADD KEY `idx_title` (`title`(250)),
  ADD KEY `idx_author` (`author`(250)),
  ADD KEY `idx_isbn` (`isbn`),
  ADD KEY `idx_dewey_decimal` (`dewey_decimal_number`),
  ADD KEY `idx_rack_number` (`rack_number`),
  ADD KEY `idx_genre` (`genre`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_books_location` (`floor_level`,`rack_number`,`shelf_position`),
  ADD KEY `idx_books_dewey_genre` (`dewey_decimal_number`,`genre`);

--
-- Indexes for table `book_loans`
--
ALTER TABLE `book_loans`
  ADD PRIMARY KEY (`loan_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_member_id` (`member_id`),
  ADD KEY `idx_book_id` (`book_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_approval_status` (`approval_status`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_loan_date` (`loan_date`),
  ADD KEY `idx_loans_member_status` (`member_id`,`status`,`approval_status`);

--
-- Indexes for table `book_reservations`
--
ALTER TABLE `book_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `idx_member_id` (`member_id`),
  ADD KEY `idx_book_id` (`book_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expiry_date` (`expiry_date`),
  ADD KEY `idx_reservations_book_status` (`book_id`,`status`,`priority_number`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`member_id`),
  ADD UNIQUE KEY `member_code` (`member_code`),
  ADD UNIQUE KEY `email` (`email`) USING HASH,
  ADD KEY `idx_member_code` (`member_code`),
  ADD KEY `idx_email` (`email`(250)),
  ADD KEY `idx_membership_type` (`membership_type`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `member_favorites`
--
ALTER TABLE `member_favorites`
  ADD PRIMARY KEY (`favorite_id`),
  ADD UNIQUE KEY `unique_favorite` (`member_id`,`book_id`),
  ADD KEY `idx_member_id` (`member_id`),
  ADD KEY `idx_book_id` (`book_id`);

--
-- Indexes for table `member_notifications`
--
ALTER TABLE `member_notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `book_id` (`book_id`),
  ADD KEY `loan_id` (`loan_id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `idx_member_id` (`member_id`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_notifications_member_read` (`member_id`,`is_read`,`created_at`),
  ADD KEY `idx_member_notifications_member_read` (`member_id`,`is_read`,`created_at`),
  ADD KEY `idx_member_notifications_type` (`type`,`created_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_member_id` (`member_id`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_processed_by` (`processed_by`),
  ADD KEY `idx_payment_type` (`payment_type`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_payments_member_date` (`member_id`,`payment_date`),
  ADD KEY `idx_payments_type_date` (`payment_type`,`payment_date`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `admin_messages`
--
ALTER TABLE `admin_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `book_loans`
--
ALTER TABLE `book_loans`
  MODIFY `loan_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `book_reservations`
--
ALTER TABLE `book_reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `member_favorites`
--
ALTER TABLE `member_favorites`
  MODIFY `favorite_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `member_notifications`
--
ALTER TABLE `member_notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=229;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
