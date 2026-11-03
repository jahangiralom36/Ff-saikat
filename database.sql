-- ====================================================================
-- Ff Store — database.sql
-- phpMyAdmin → আপনার database select → Import → এই ফাইল দিন। (একবারই)
-- Default admin login:  username: admin   |   password: Admin@12345
-- Login করেই admin panel থেকে password বদলে নিন।
-- ====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(40)  NOT NULL UNIQUE,
  `email`         VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `balance`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `role`          ENUM('user','admin') NOT NULL DEFAULT 'user',
  `status`        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(60) NOT NULL UNIQUE,
  `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id`     INT UNSIGNED NULL,
  `name`            VARCHAR(150) NOT NULL,
  `subtitle`        VARCHAR(150) NULL,
  `tag`             VARCHAR(30)  NULL,
  `image_url`        VARCHAR(255) NULL,
  `description`     TEXT NULL,
  `download_link`   VARCHAR(255) NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `is_pinned`       TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `is_maintenance`  TINYINT(1) NOT NULL DEFAULT 0,
  `maintenance_msg` TEXT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_prod_cat` (`category_id`),
  KEY `idx_prod_state` (`is_active`,`is_maintenance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_plans` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`    INT UNSIGNED NOT NULL,
  `title`         VARCHAR(100) NOT NULL,
  `duration_days` INT NOT NULL DEFAULT 1,
  `price`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sort_order`    INT NOT NULL DEFAULT 0,
  KEY `idx_plan_product` (`product_id`),
  CONSTRAINT `fk_plan_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stock_keys` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id`  INT UNSIGNED NOT NULL,
  `plan_id`     INT UNSIGNED NOT NULL,
  `license_key` VARCHAR(255) NOT NULL,
  `status`      ENUM('available','used') NOT NULL DEFAULT 'available',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_stock_pick` (`plan_id`,`status`),
  CONSTRAINT `fk_stock_plan` FOREIGN KEY (`plan_id`) REFERENCES `product_plans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `product_id`    INT UNSIGNED NULL,
  `plan_id`       INT UNSIGNED NULL,
  `product_name`  VARCHAR(150) NOT NULL,
  `plan_title`    VARCHAR(100) NOT NULL,
  `license_key`   VARCHAR(255) NOT NULL,
  `price`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `coupon_code`   VARCHAR(40) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_order_user` (`user_id`),
  CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(60) NOT NULL,
  `account_no`  VARCHAR(120) NOT NULL,
  `account_type` VARCHAR(40) NULL,
  `instructions` TEXT NULL,
  `logo_url`    VARCHAR(255) NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deposits` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `method_name` VARCHAR(60) NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL,
  `sender_no`   VARCHAR(60) NULL,
  `trx_id`      VARCHAR(100) NOT NULL,
  `status`      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note`  VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  UNIQUE KEY `uq_deposit_trx` (`trx_id`),
  KEY `idx_dep_user` (`user_id`),
  KEY `idx_dep_status` (`status`),
  CONSTRAINT `fk_dep_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`          VARCHAR(40) NOT NULL UNIQUE,
  `type`          ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value`         DECIMAL(10,2) NOT NULL,
  `max_uses`      INT NOT NULL DEFAULT 0,
  `used_count`    INT NOT NULL DEFAULT 0,
  `expires_at`    DATETIME NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `banners` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `image_url`  VARCHAR(255) NOT NULL,
  `link_url`   VARCHAR(255) NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message`    VARCHAR(255) NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NULL,
  `action`     VARCHAR(60) NOT NULL,
  `details`    VARCHAR(255) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_log_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- default admin
INSERT INTO `users` (`username`,`email`,`password_hash`,`role`) VALUES
('admin','admin@example.com','$2y$10$2m2Ex.sj1tE0KTcxNVqM1eRL6SGLxVgrHxfRgbpvaMrsMvS.boImS','admin')
ON DUPLICATE KEY UPDATE `role`='admin';

-- ---------------------------------------------------------------- default settings (admin → Site Settings থেকে edit হবে)
INSERT IGNORE INTO `settings` (`setting_key`,`setting_value`) VALUES
('site_name','Ff Store'),
('site_tagline','Instant delivery • Trusted • 24/7 support'),
('currency_symbol','৳'),
('theme_primary','#2563eb'),
('theme_accent','#f43f5e'),
('theme_mode','light'),
('support_link',''),
('support_label','Contact Admin Support'),
('footer_text','© Ff Store. All rights reserved.'),
('min_deposit','50'),
('max_deposit','50000'),
('site_maintenance','0'),
('site_maintenance_msg','Store upgrade in progress. We will be back shortly.'),
('maint_ribbon_text','MAINTENANCE'),
('maint_button_text','MAINTENANCE'),
('maint_popup_chip','TEMPORARILY UNAVAILABLE'),
('maint_popup_rules_title','RULES & INFORMATION:'),
('maint_default_msg','⚠️ Rules & Conditions:\nএই প্রোডাক্টটির সার্ভার আপগ্রেড চলছে। সাময়িক অসুবিধার জন্য আমরা দুঃখিত। খুব শীঘ্রই সার্ভিস চালু করা হবে।'),
('maint_close_text','CLOSE');

-- ---------------------------------------------------------------- starter payment methods (admin → Payment Methods থেকে নিজের নম্বর দিন)
INSERT INTO `payment_methods` (`name`,`account_no`,`account_type`,`instructions`,`sort_order`) VALUES
('bKash','01XXXXXXXXX','Personal','Send Money করে Transaction ID দিন।',1),
('Nagad','01XXXXXXXXX','Personal','Send Money করে Transaction ID দিন।',2);
