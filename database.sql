-- ====================================================================
-- database.sql — সব টেবিল (index.php + admin.php + config.php এর জন্য)
--
-- কীভাবে ইম্পোর্ট করবেন:
--   cPanel → phpMyAdmin → আপনার database সিলেক্ট → Import → এই ফাইল → Go
--   তারপর config.php তে DB_NAME / DB_USER / DB_PASS বসান।
--
-- ⚠️ সতর্কতা: এই ফাইল ইম্পোর্ট করলে নিচের টেবিলগুলো আগে মুছে নতুন করে বানানো হয়।
--    অর্থাৎ এই ডাটাবেজে আগের ডাটা (ইউজার, প্রোডাক্ট, অর্ডার) থাকলে মুছে যাবে।
--    তাই চালু সাইটে আবার ইম্পোর্ট করবেন না — শুধু প্রথমবার সেটআপের সময় করুন।
--
-- অ্যাডমিন ইউজার/পাসওয়ার্ড ডাটাবেজে নেই — config.php (ADMIN_USERNAME / ADMIN_PASSWORD) এ আছে।
-- সব টেবিলের subdomain_id = 0 (একটাই স্টোর)।
-- ====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `product_categories`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `product_plans`;
DROP TABLE IF EXISTS `stock_keys`;
DROP TABLE IF EXISTS `user_custom_prices`;
DROP TABLE IF EXISTS `user_allowed_products`;
DROP TABLE IF EXISTS `licenses`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `gateway_transactions`;
DROP TABLE IF EXISTS `payment_methods`;
DROP TABLE IF EXISTS `live_purchase_feed`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `coupon_usages`;
DROP TABLE IF EXISTS `banners`;
DROP TABLE IF EXISTS `custom_popups`;
DROP TABLE IF EXISTS `system_notifications`;
DROP TABLE IF EXISTS `referral_settings`;
DROP TABLE IF EXISTS `spin_segments`;
DROP TABLE IF EXISTS `user_spin_offers`;
DROP TABLE IF EXISTS `deposit_bonuses`;
DROP TABLE IF EXISTS `reset_logs`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `deposits`;
DROP TABLE IF EXISTS `announcements`;


-- ---------------------------------------------------------------- settings
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS `users` (
  `id`                     INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`           INT NOT NULL DEFAULT 0,
  `username`               VARCHAR(100) NOT NULL,
  `email`                  VARCHAR(191) NULL,
  `password`               VARCHAR(255) NOT NULL,
  `firebase_uid`           VARCHAR(128) NULL,
  `avatar_url`             VARCHAR(255) NULL,
  `role`                   VARCHAR(20) NOT NULL DEFAULT 'customer',
  `status`                 VARCHAR(20) NOT NULL DEFAULT 'active',
  `balance`                DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `profit_balance`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `parent_granted_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `coins`                  INT NOT NULL DEFAULT 0,
  `discount_percent`       DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `custom_bdt_rate`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `can_add_fund`           TINYINT(1) NOT NULL DEFAULT 1,
  `api_enabled`            TINYINT(1) NOT NULL DEFAULT 1,
  `allowed_products`       TEXT NULL,
  `min_deposit`            DECIMAL(10,2) NULL,
  `max_deposit`            DECIMAL(10,2) NULL,
  `custom_tutorial_link`   VARCHAR(255) NULL,
  `referral_code`          VARCHAR(20) NULL,
  `referred_by`            INT NOT NULL DEFAULT 0,
  `referral_tokens`        INT NOT NULL DEFAULT 0,
  `spin_count_available`   INT NOT NULL DEFAULT 0,
  `spin_spend_total`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by`             INT NOT NULL DEFAULT 0,
  `created_by_subdomain`   VARCHAR(100) NOT NULL DEFAULT 'main',
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_users_sub` (`subdomain_id`),
  KEY `idx_users_name` (`username`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_ref` (`referral_code`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- catalog
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `name`         VARCHAR(100) NOT NULL,
  `sort_order`   INT NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  KEY `idx_cat_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`     INT NOT NULL DEFAULT 0,
  `name`             VARCHAR(191) NOT NULL,
  `code`             VARCHAR(100) NULL,
  `category`         VARCHAR(100) NOT NULL DEFAULT 'APKMOD',
  `tags`             VARCHAR(255) NULL,
  `description`      TEXT NULL,
  `image_url`        VARCHAR(255) NULL,
  `logo_url`         VARCHAR(255) NULL,
  `banner_url`       VARCHAR(255) NULL,
  `download_link`    VARCHAR(255) NULL,
  `setup_link`       VARCHAR(255) NULL,
  `app_download_url` VARCHAR(255) NULL,
  `km_api_id`        VARCHAR(100) NULL,
  `sort_order`       INT NOT NULL DEFAULT 0,
  `is_pinned`        TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted`       TINYINT(1) NOT NULL DEFAULT 0,
  `status`           VARCHAR(20) NOT NULL DEFAULT 'active',
  `is_maintenance`   TINYINT(1) NOT NULL DEFAULT 0,
  `maintenance_msg`  TEXT NULL,
  `reset_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_prod_sub` (`subdomain_id`),
  KEY `idx_prod_state` (`is_active`,`is_maintenance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_plans` (
  `id`                        INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`              INT NOT NULL DEFAULT 0,
  `product_id`                INT NOT NULL,
  `plan_title`                VARCHAR(150) NOT NULL,
  `validity`                  VARCHAR(100) NULL,
  `duration_days`             INT NOT NULL DEFAULT 1,
  `duration_unit`             VARCHAR(20) NOT NULL DEFAULT 'days',
  `duration_value`            INT NOT NULL DEFAULT 1,
  `price`                     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `regular_price`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `user_price`                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reseller_price`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_percent`          DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `reseller_discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `api_plan_id`               INT NOT NULL DEFAULT 0,
  `reward_coins`              INT NOT NULL DEFAULT 0,
  `coin_price`                INT NOT NULL DEFAULT 0,
  `is_active`                 TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted`                TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_plan_product` (`product_id`),
  KEY `idx_plan_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stock_keys` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `product_id`   INT NOT NULL,
  `plan_id`      INT NOT NULL,
  `license_key`  VARCHAR(255) NOT NULL,
  `status`       ENUM('available','used') NOT NULL DEFAULT 'available',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_stock_pick` (`plan_id`,`status`),
  KEY `idx_stock_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_custom_prices` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT NOT NULL,
  `plan_id`      INT NOT NULL,
  `custom_price` DECIMAL(12,2) NOT NULL,
  UNIQUE KEY `uq_user_plan` (`user_id`,`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_allowed_products` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT NOT NULL,
  `product_id` INT NOT NULL,
  UNIQUE KEY `uq_user_prod` (`user_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- sales
CREATE TABLE IF NOT EXISTS `licenses` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `user_id`      INT NOT NULL,
  `product_id`   INT NULL,
  `plan_id`      INT NULL,
  `license_key`  VARCHAR(255) NOT NULL,
  `validity`     VARCHAR(100) NULL,
  `price`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `source`       VARCHAR(50) NOT NULL DEFAULT 'MAIN',
  `payment_type` VARCHAR(30) NULL,
  `status`       VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NULL DEFAULT NULL,
  KEY `idx_lic_user` (`user_id`),
  KEY `idx_lic_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`  INT NOT NULL DEFAULT 0,
  `user_id`       INT NOT NULL,
  `tx_type`       VARCHAR(20) NOT NULL DEFAULT 'deposit',
  `method`        VARCHAR(50) NOT NULL,
  `amount`        DECIMAL(12,2) NOT NULL,
  `bonus_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sender_num`    VARCHAR(100) NULL,
  `trx_id`        VARCHAR(100) NOT NULL,
  `order_plan_id` INT NULL,
  `order_qty`     INT NULL,
  `order_coupon`  VARCHAR(60) NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_trx_user` (`user_id`),
  KEY `idx_trx_id` (`trx_id`),
  KEY `idx_trx_sub` (`subdomain_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gateway_transactions` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `trx_id`       VARCHAR(100) NOT NULL,
  `method`       VARCHAR(50) NULL,
  `amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `user_id`      INT NULL,
  `status`       VARCHAR(20) NOT NULL DEFAULT 'used',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_gw_trx` (`trx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`   INT NOT NULL DEFAULT 0,
  `method_key`     VARCHAR(50) NOT NULL,
  `name`           VARCHAR(100) NOT NULL,
  `account_number` VARCHAR(150) NULL,
  `account_type`   VARCHAR(50) NULL,
  `instructions`   TEXT NULL,
  `logo_url`       VARCHAR(255) NULL,
  `banner_url`     VARCHAR(255) NULL,
  `qr_image_url`   VARCHAR(255) NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `allow_add_fund` TINYINT(1) NOT NULL DEFAULT 1,
  `allow_checkout` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`     INT NOT NULL DEFAULT 0,
  KEY `idx_pm_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `live_purchase_feed` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`    INT NOT NULL DEFAULT 0,
  `masked_username` VARCHAR(100) NULL,
  `product_name`    VARCHAR(191) NULL,
  `amount_bdt`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_feed_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- marketing
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`    INT NOT NULL DEFAULT 0,
  `code`            VARCHAR(60) NOT NULL,
  `title`           VARCHAR(150) NULL,
  `discount_type`   VARCHAR(20) NOT NULL DEFAULT 'percent',
  `discount_value`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `max_discount`    DECIMAL(12,2) NULL,
  `min_spend`       DECIMAL(12,2) NULL,
  `target_type`     VARCHAR(30) NOT NULL DEFAULT 'all',
  `product_ids`     TEXT NULL,
  `user_role`       VARCHAR(20) NULL,
  `max_uses`        INT NOT NULL DEFAULT 0,
  `per_user_limit`  INT NOT NULL DEFAULT 0,
  `used_count`      INT NOT NULL DEFAULT 0,
  `starts_at`       DATETIME NULL,
  `expires_at`      DATETIME NULL,
  `expiry_date`     DATETIME NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_coupon_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coupon_usages` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `coupon_id`       INT NOT NULL,
  `subdomain_id`    INT NOT NULL DEFAULT 0,
  `user_id`         INT NOT NULL,
  `product_id`      INT NULL,
  `discount_bdt`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `order_total_bdt` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_cu_coupon` (`coupon_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `banners` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `image_url`    VARCHAR(255) NOT NULL,
  `target_link`  VARCHAR(255) NULL,
  `title`        VARCHAR(150) NULL,
  `sort_order`   INT NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `status`       VARCHAR(20) NULL DEFAULT 'active',
  KEY `idx_banner_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `custom_popups` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`          INT NOT NULL DEFAULT 0,
  `title`                 VARCHAR(150) NULL,
  `message`               TEXT NULL,
  `image_url`             VARCHAR(255) NULL,
  `link_url`              VARCHAR(255) NULL,
  `link_text`             VARCHAR(100) NULL,
  `show_once_per_session` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`            INT NOT NULL DEFAULT 0,
  `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
  KEY `idx_popup_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_notifications` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`   INT NOT NULL DEFAULT 0,
  `target_type`    VARCHAR(20) NOT NULL DEFAULT 'all',
  `target_user_id` INT NULL,
  `title`          VARCHAR(191) NOT NULL,
  `message`        TEXT NULL,
  `scope`          VARCHAR(20) NOT NULL DEFAULT 'sub_only',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notif_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `referral_settings` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id`       INT NOT NULL DEFAULT 0,
  `token_per_referral` INT NOT NULL DEFAULT 10,
  `referrer_bonus_bdt` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_ref_sub` (`subdomain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `spin_segments` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `title`        VARCHAR(100) NOT NULL,
  `spend_target` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bonus_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `probability`  DECIMAL(6,2) NOT NULL DEFAULT 10.00,
  `color`        VARCHAR(20) NULL,
  `sort_order`   INT NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_spin_offers` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `user_id`      INT NOT NULL,
  `segment_id`   INT NULL,
  `title`        VARCHAR(100) NULL,
  `bonus_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `spend_target` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`       VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at`   DATETIME NULL,
  `expires_at`   DATETIME NULL,
  KEY `idx_uso_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `deposit_bonuses` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `title`        VARCHAR(100) NULL,
  `min_deposit`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bonus_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `bonus_type`   VARCHAR(20) NOT NULL DEFAULT 'fixed',
  `claim_limit`  INT NOT NULL DEFAULT 0,
  `sort_order`   INT NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reset_logs` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `subdomain_id` INT NOT NULL DEFAULT 0,
  `user_id`      INT NOT NULL,
  `license_key`  VARCHAR(255) NULL,
  `product_name` VARCHAR(191) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_reset_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT NULL,
  `username`   VARCHAR(100) NULL,
  `action`     VARCHAR(100) NOT NULL,
  `details`    TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
