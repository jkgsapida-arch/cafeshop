-- =====================================================================
--  COFFEE SHOP WEB SYSTEM  -  schema.sql
--  Project  : Coffee Shop Web System (Web System and Cloud Computing - Finals)
--  Database : coffeeshop
--  Engine   : MySQL 5.7+/8.x or MariaDB 10.4+ (XAMPP)  |  InnoDB, utf8mb4
--  Location : CoffeeShop/admin/database/schema.sql
--
--  HOW TO IMPORT (phpMyAdmin or CLI)
--    1. Start Apache + MySQL in the XAMPP Control Panel.
--    2. If an OLD `coffeeshop` database exists, drop it first
--       (phpMyAdmin > coffeeshop > Operations > Delete the database),
--       because this file REPLACES the previous schema.
--    3. Import this file:  phpMyAdmin > Import > schema.sql
--       or  C:\xampp\mysql\bin\mysql.exe -u root < schema.sql
--
--  DESIGN RULES (read before editing the PHP models)
--    * Every table uses `id` INT UNSIGNED AUTO_INCREMENT as primary key,
--      snake_case plural table names, created_at / updated_at timestamps.
--    * users.role is an ENUM: customer | admin | staff | fulfillment.
--      Fine-grained permissions: permissions / role_permissions /
--      user_permissions.
--    * EVERY product has at least one row in product_variants
--      (use a single "Standard" variant for items without sizes).
--      Carts, stock, recipes and nutrition all point to a variant.
--    * Final unit price of a line =
--        COALESCE(branch_variant_prices.price_override,   -- branch + variant (most specific)
--                 product_variants.price_override,         -- fixed variant price (beans 250g/1kg, gift cards)
--                 branch_products.price_override,          -- branch price for the whole product
--                 active promo_price, base_price)
--        + product_variants.additional_charge
--        + SUM(customization option charges)
--      A branch_products.price_override therefore NEVER flattens variants that
--      have their own fixed price; use branch_variant_prices for those.
--      The view v_variant_effective_prices returns this base price per
--      branch + variant (it excludes customization charges).
--      Always recalculate server-side at checkout.
--    * Inventory tracking is chosen per product with products.inventory_mode:
--        none               -> never limited (gift cards, services)
--        product_stock      -> sold units are deducted from branch_product_stock
--                              (beans, pastries, merchandise, equipment)
--        recipe_ingredients -> made-to-order drinks: ONLY ingredients are
--                              deducted (product_recipes + customization_option_ingredients
--                              from branch_ingredient_stock); branch_product_stock
--                              is NOT used. v_recipe_variant_capacity shows how
--                              many servings each branch can still make.
--      (products.track_inventory is now a read-only generated column = 1 only
--       for product_stock; never INSERT/UPDATE it - change inventory_mode.)
--    * Stock: for product_stock items, product_variants.stock_quantity is the
--      central/default pool; branch_product_stock holds the real per-branch
--      quantity and is authoritative once an order is assigned to a branch.
--    * Reporting views share ONE definition of a "counted" order:
--      v_counted_orders (not cancelled AND payment not failed).
--    * No cron/event scheduler on XAMPP: periodic work (abandoned carts, mail
--      retries, scheduled gift cards, point expiry) is tracked in the
--      maintenance_jobs table and must be triggered from PHP (see SECTION 24B).
--    * Gift cards: the buyer's recipient details live on cart_items/order_items
--      (gift_* columns); checkout creates gift_cards (status 'pending') and
--      links it via gift_cards.purchase_order_item_id; payment success
--      activates it and e-mails it (see SECTION 17 and SECTION 24B).
--    * Orders keep SNAPSHOTS (names, SKUs, prices, addresses, options) so
--      history never changes when products or addresses are edited.
--    * Never store full card numbers or CVV. Only brand + last 4 + a
--      provider token/reference.
--    * Money = DECIMAL(10,2) (PHP). Quantities of ingredients = DECIMAL(12,3).
--    * No triggers / stored procedures: all stock, points and balance
--      changes must happen in PHP inside a transaction (BEGIN ... COMMIT)
--      and write a row to the matching *_transactions table.
--
--  DEMO LOGIN ACCOUNTS (seed data at the bottom - CHANGE/DELETE LATER)
--    admin@coffeeshop.local        / Admin@12345   (admin)
--    staff@coffeeshop.local        / Admin@12345   (staff)
--    fulfillment@coffeeshop.local  / Admin@12345   (fulfillment)
--    customer@coffeeshop.local     / Admin@12345   (customer)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE DATABASE IF NOT EXISTS `coffeeshop`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `coffeeshop`;

-- =====================================================================
-- SECTION 1: BRANCHES / STORES                       (features 19, 20, 21)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `branches` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                VARCHAR(20)  NOT NULL,
  `name`                VARCHAR(120) NOT NULL,
  `address`             VARCHAR(255) NOT NULL,
  `city`                VARCHAR(100) NOT NULL,
  `province`            VARCHAR(100) NOT NULL,
  `postal_code`         VARCHAR(20)  DEFAULT NULL,
  `country`             VARCHAR(100) NOT NULL DEFAULT 'Philippines',
  `phone`               VARCHAR(30)  DEFAULT NULL,
  `email`               VARCHAR(190) DEFAULT NULL,
  `latitude`            DECIMAL(10,7) DEFAULT NULL,
  `longitude`           DECIMAL(10,7) DEFAULT NULL,
  `supports_delivery`   TINYINT(1)   NOT NULL DEFAULT 1,
  `supports_pickup`     TINYINT(1)   NOT NULL DEFAULT 1,
  `supports_takeaway`   TINYINT(1)   NOT NULL DEFAULT 1,
  `supports_dine_in`    TINYINT(1)   NOT NULL DEFAULT 1,
  `prep_time_minutes`   SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `archived_at`         DATETIME     DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`),
  KEY `idx_branches_active` (`is_active`, `archived_at`),
  KEY `idx_branches_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regular weekly hours (day_of_week: 0 = Sunday ... 6 = Saturday)
CREATE TABLE IF NOT EXISTS `branch_operating_hours` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`   INT UNSIGNED NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `open_time`   TIME DEFAULT NULL,
  `close_time`  TIME DEFAULT NULL,
  `is_closed`   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_hours_day` (`branch_id`, `day_of_week`),
  CONSTRAINT `fk_branch_hours_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Holidays and special schedules (override the weekly hours on that date)
CREATE TABLE IF NOT EXISTS `branch_special_hours` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`     INT UNSIGNED NOT NULL,
  `special_date`  DATE NOT NULL,
  `label`         VARCHAR(120) NOT NULL,
  `open_time`     TIME DEFAULT NULL,
  `close_time`    TIME DEFAULT NULL,
  `is_closed`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_special_date` (`branch_id`, `special_date`),
  CONSTRAINT `fk_branch_special_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 2: USERS, AUTHENTICATION, ROLES, EMPLOYEES   (features 4,39,40,51)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role`               ENUM('customer','admin','staff','fulfillment') NOT NULL DEFAULT 'customer',
  `first_name`         VARCHAR(80)  NOT NULL,
  `last_name`          VARCHAR(80)  NOT NULL,
  `email`              VARCHAR(190) NOT NULL,
  `phone`              VARCHAR(30)  DEFAULT NULL,
  `password_hash`      VARCHAR(255) NOT NULL,              -- password_hash() output only
  `status`             ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `email_verified_at`  DATETIME DEFAULT NULL,
  `date_of_birth`      DATE DEFAULT NULL,
  `profile_image`      VARCHAR(255) DEFAULT NULL,
  `failed_login_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`       DATETIME DEFAULT NULL,              -- brute-force lockout
  `last_login_at`      DATETIME DEFAULT NULL,
  `last_login_ip`      VARCHAR(45) DEFAULT NULL,
  `deactivated_at`     DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_status` (`role`, `status`),
  KEY `idx_users_phone` (`phone`),
  KEY `idx_users_name` (`last_name`, `first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store only a SHA-256 hash of the token (hash('sha256', $token)), never the raw token.
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL,
  `expires_at`  DATETIME NOT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_verif_token` (`token_hash`),
  KEY `idx_email_verif_user` (`user_id`),
  CONSTRAINT `fk_email_verif_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reset tokens: expire (expires_at) and are single-use (used_at).
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64) NOT NULL,
  `expires_at`    DATETIME NOT NULL,
  `used_at`       DATETIME DEFAULT NULL,
  `requested_ip`  VARCHAR(45) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`, `used_at`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `email`          VARCHAR(190) NOT NULL,
  `ip_address`     VARCHAR(45) NOT NULL,
  `user_agent`     VARCHAR(255) DEFAULT NULL,
  `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
  `failure_reason` VARCHAR(100) DEFAULT NULL,
  `attempted_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_email` (`email`, `attempted_at`),
  KEY `idx_login_attempts_ip` (`ip_address`, `attempted_at`),
  KEY `idx_login_attempts_user` (`user_id`),
  CONSTRAINT `fk_login_attempts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Granular permissions (code example: products.edit, orders.update_status)
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(80)  NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `module`      VARCHAR(50)  NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`),
  KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role`          ENUM('admin','staff','fulfillment','customer') NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role`, `permission_id`),
  CONSTRAINT `fk_role_perm_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user override: is_granted = 1 adds a permission, 0 revokes one the role has.
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `is_granted`    TINYINT(1) NOT NULL DEFAULT 1,
  `granted_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `permission_id`),
  CONSTRAINT `fk_user_perm_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_perm_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_perm_granted_by` FOREIGN KEY (`granted_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employee details for users whose role is admin / staff / fulfillment.
CREATE TABLE IF NOT EXISTS `employee_profiles` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `employee_code`     VARCHAR(30) NOT NULL,
  `branch_id`         INT UNSIGNED DEFAULT NULL,
  `job_title`         VARCHAR(100) DEFAULT NULL,
  `responsibilities`  TEXT DEFAULT NULL,
  `hire_date`         DATE DEFAULT NULL,
  `employment_status` ENUM('active','on_leave','terminated') NOT NULL DEFAULT 'active',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_user` (`user_id`),
  UNIQUE KEY `uq_employee_code` (`employee_code`),
  KEY `idx_employee_branch` (`branch_id`),
  CONSTRAINT `fk_employee_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_account_history` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_user_id` INT UNSIGNED NOT NULL,
  `changed_by`       INT UNSIGNED DEFAULT NULL,
  `change_type`      ENUM('created','updated','role_changed','branch_changed','activated','deactivated','password_reset') NOT NULL,
  `old_value`        VARCHAR(255) DEFAULT NULL,
  `new_value`        VARCHAR(255) DEFAULT NULL,
  `notes`            VARCHAR(255) DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp_history_employee` (`employee_user_id`, `created_at`),
  CONSTRAINT `fk_emp_history_employee` FOREIGN KEY (`employee_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_emp_history_changed_by` FOREIGN KEY (`changed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value system settings (tax rate, cart expiry, loyalty defaults, ...)
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `value_type`    ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
  `description`   VARCHAR(255) DEFAULT NULL,
  `updated_by`    INT UNSIGNED DEFAULT NULL,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`),
  CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 3: CUSTOMER ADDRESSES, SAVED PAYMENT METHODS   (features 5, 7, 9)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED NOT NULL,
  `label`               VARCHAR(60) DEFAULT NULL,           -- Home, Office, ...
  `address_type`        ENUM('billing','shipping','both') NOT NULL DEFAULT 'both',
  `recipient_name`      VARCHAR(150) NOT NULL,
  `phone`               VARCHAR(30) NOT NULL,
  `email`               VARCHAR(190) DEFAULT NULL,
  `address_line1`       VARCHAR(255) NOT NULL,
  `address_line2`       VARCHAR(255) DEFAULT NULL,
  `city`                VARCHAR(100) NOT NULL,
  `province`            VARCHAR(100) NOT NULL,
  `postal_code`         VARCHAR(20) NOT NULL,
  `country`             VARCHAR(100) NOT NULL DEFAULT 'Philippines',
  `is_default_billing`  TINYINT(1) NOT NULL DEFAULT 0,
  `is_default_shipping` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_addresses_user` (`user_id`, `is_default_shipping`, `is_default_billing`),
  CONSTRAINT `fk_addresses_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokenized / masked payment methods only. NEVER the full card number or CVV.
CREATE TABLE IF NOT EXISTS `customer_payment_methods` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `method_type`     ENUM('credit_card','debit_card','gcash','paypal') NOT NULL,
  `provider`        VARCHAR(50) DEFAULT NULL,               -- mock, stripe, paymongo, ...
  `provider_token`  VARCHAR(255) DEFAULT NULL,              -- gateway token (future real payments)
  `card_brand`      VARCHAR(30) DEFAULT NULL,
  `card_last4`      CHAR(4) DEFAULT NULL,
  `expiry_month`    TINYINT UNSIGNED DEFAULT NULL,
  `expiry_year`     SMALLINT UNSIGNED DEFAULT NULL,
  `holder_name`     VARCHAR(150) DEFAULT NULL,
  `is_default`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_methods_user` (`user_id`),
  CONSTRAINT `fk_payment_methods_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 4: CATEGORIES, PRODUCTS, VARIANTS, IMAGES   (features 1, 22-25)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`     INT UNSIGNED DEFAULT NULL,              -- sub-categories
  `name`          VARCHAR(120) NOT NULL,
  `slug`          VARCHAR(150) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `image_path`    VARCHAR(255) DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `archived_at`   DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_active_order` (`is_active`, `display_order`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `allergens` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_allergens_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`         INT UNSIGNED NOT NULL,
  `sku`                 VARCHAR(50)  NOT NULL,
  `name`                VARCHAR(160) NOT NULL,
  `slug`                VARCHAR(190) NOT NULL,
  `short_description`   VARCHAR(255) DEFAULT NULL,
  `description`         TEXT DEFAULT NULL,
  `product_type`        ENUM('hot_drink','iced_drink','coffee_beans','pastry','merchandise','equipment','gift_card','other') NOT NULL DEFAULT 'other',
  `roast_level`         ENUM('light','medium','medium_dark','dark') DEFAULT NULL,
  `base_price`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `promo_price`         DECIMAL(10,2) DEFAULT NULL,
  `promo_starts_at`     DATETIME DEFAULT NULL,
  `promo_ends_at`       DATETIME DEFAULT NULL,
  `is_featured`         TINYINT(1) NOT NULL DEFAULT 0,
  `is_recommended`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_available`        TINYINT(1) NOT NULL DEFAULT 1,
  `is_customizable`     TINYINT(1) NOT NULL DEFAULT 0,
  `inventory_mode`      ENUM('none','product_stock','recipe_ingredients') NOT NULL DEFAULT 'product_stock',
  `track_inventory`     TINYINT(1) GENERATED ALWAYS AS (`inventory_mode` = 'product_stock') VIRTUAL,  -- read-only, backward compatible
  `low_stock_threshold` INT NOT NULL DEFAULT 10,           -- default for its variants (product_stock only)
  `prep_time_minutes`   SMALLINT UNSIGNED DEFAULT NULL,
  `status`              ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
  `archived_at`         DATETIME DEFAULT NULL,
  `created_by`          INT UNSIGNED DEFAULT NULL,
  `updated_by`          INT UNSIGNED DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  UNIQUE KEY `uq_products_slug` (`slug`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_type` (`product_type`),
  KEY `idx_products_roast` (`roast_level`),
  KEY `idx_products_status_available` (`status`, `is_available`),
  KEY `idx_products_featured` (`is_featured`, `is_recommended`),
  KEY `idx_products_price` (`base_price`),
  KEY `idx_products_inventory_mode` (`inventory_mode`),
  FULLTEXT KEY `ft_products_search` (`name`, `short_description`, `description`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_products_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`          INT UNSIGNED NOT NULL,
  `sku`                 VARCHAR(60) NOT NULL,
  `name`                VARCHAR(100) NOT NULL,              -- e.g. Small, Medium, Large, 250g, Standard
  `size_label`          VARCHAR(30) DEFAULT NULL,
  `volume_ml`           SMALLINT UNSIGNED DEFAULT NULL,
  `price_override`      DECIMAL(10,2) DEFAULT NULL,         -- fixed variant price; NULL = use branch/product price. Beats branch_products.price_override
  `additional_charge`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_quantity`      INT NOT NULL DEFAULT 0,             -- central pool, only for inventory_mode = product_stock
  `low_stock_threshold` INT DEFAULT NULL,                   -- NULL = use products.low_stock_threshold
  `is_default`          TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `display_order`       INT NOT NULL DEFAULT 0,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variants_sku` (`sku`),
  UNIQUE KEY `uq_variants_product_name` (`product_id`, `name`),
  KEY `idx_variants_active` (`product_id`, `is_active`, `display_order`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_variants_stock` CHECK (`stock_quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED NOT NULL,
  `file_path`     VARCHAR(255) NOT NULL,                    -- relative to assets/img/
  `original_name` VARCHAR(255) DEFAULT NULL,
  `mime_type`     VARCHAR(50) DEFAULT NULL,
  `file_size`     INT UNSIGNED DEFAULT NULL,                -- bytes
  `alt_text`      VARCHAR(255) DEFAULT NULL,
  `is_primary`    TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `uploaded_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_images_product` (`product_id`, `is_primary`, `display_order`),
  CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_images_user` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nutrition per variant (a Large has more calories than a Small)
CREATE TABLE IF NOT EXISTS `product_nutrition` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED NOT NULL,
  `variant_id`     INT UNSIGNED NOT NULL,
  `serving_size`   VARCHAR(60) DEFAULT NULL,
  `calories`       SMALLINT UNSIGNED DEFAULT NULL,
  `total_fat_g`    DECIMAL(6,2) DEFAULT NULL,
  `saturated_fat_g` DECIMAL(6,2) DEFAULT NULL,
  `carbs_g`        DECIMAL(6,2) DEFAULT NULL,
  `sugar_g`        DECIMAL(6,2) DEFAULT NULL,
  `protein_g`      DECIMAL(6,2) DEFAULT NULL,
  `sodium_mg`      DECIMAL(8,2) DEFAULT NULL,
  `caffeine_mg`    DECIMAL(6,2) DEFAULT NULL,
  `notes`          VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nutrition_variant` (`variant_id`),
  KEY `idx_nutrition_product` (`product_id`),
  CONSTRAINT `fk_nutrition_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nutrition_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_allergens` (
  `product_id`  INT UNSIGNED NOT NULL,
  `allergen_id` INT UNSIGNED NOT NULL,
  `presence`    ENUM('contains','may_contain') NOT NULL DEFAULT 'contains',
  PRIMARY KEY (`product_id`, `allergen_id`),
  CONSTRAINT `fk_product_allergens_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_allergens_allergen` FOREIGN KEY (`allergen_id`)
    REFERENCES `allergens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 5: CUSTOMIZATION OPTIONS                       (features 2, 24)
--   Sizes are handled by product_variants. Groups below cover milk,
--   sweetness, temperature, espresso shots, syrups and toppings.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `customization_groups` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(50) NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `group_type`    ENUM('milk','sweetness','temperature','espresso','syrup','topping','other') NOT NULL DEFAULT 'other',
  `selection_type` ENUM('single','multiple') NOT NULL DEFAULT 'single',
  `is_required`   TINYINT(1) NOT NULL DEFAULT 0,
  `min_select`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_select`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_groups_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customization_options` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`         INT UNSIGNED NOT NULL,
  `name`             VARCHAR(100) NOT NULL,
  `option_value`     VARCHAR(50) DEFAULT NULL,              -- e.g. 50 for "50% sweet"
  `additional_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_quantity`     TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- e.g. up to 4 extra shots
  `is_default`       TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `display_order`    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_options_group_name` (`group_id`, `name`),
  CONSTRAINT `fk_custom_options_group` FOREIGN KEY (`group_id`)
    REFERENCES `customization_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which options a PRODUCT offers (only these are shown to the customer).
CREATE TABLE IF NOT EXISTS `product_customization_options` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED NOT NULL,
  `option_id`      INT UNSIGNED NOT NULL,
  `price_override` DECIMAL(10,2) DEFAULT NULL,              -- NULL = use option price
  `is_default`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_available`   TINYINT(1) NOT NULL DEFAULT 1,
  `display_order`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_options` (`product_id`, `option_id`),
  KEY `idx_product_options_option` (`option_id`),
  CONSTRAINT `fk_product_options_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_options_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional per-VARIANT restriction. A variant with NO rows here accepts every
-- option of its product; with rows, only the listed options.
CREATE TABLE IF NOT EXISTS `variant_customization_options` (
  `variant_id` INT UNSIGNED NOT NULL,
  `option_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`variant_id`, `option_id`),
  CONSTRAINT `fk_variant_options_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_variant_options_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 6: FAVORITES, SAVED DRINKS, PREFERENCES       (features 5, 6, 54)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `favorites` (
  `user_id`    INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `product_id`),
  KEY `idx_favorites_product` (`product_id`),
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_favorites_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `saved_customizations` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `product_id`   INT UNSIGNED NOT NULL,
  `variant_id`   INT UNSIGNED NOT NULL,
  `name`         VARCHAR(100) NOT NULL,                     -- "My morning latte"
  `notes`        VARCHAR(255) DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_saved_custom_user` (`user_id`),
  KEY `idx_saved_custom_product` (`product_id`),
  KEY `idx_saved_custom_variant` (`variant_id`),
  CONSTRAINT `fk_saved_custom_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_saved_custom_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_saved_custom_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `saved_customization_options` (
  `saved_customization_id` INT UNSIGNED NOT NULL,
  `option_id`              INT UNSIGNED NOT NULL,
  `quantity`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`saved_customization_id`, `option_id`),
  CONSTRAINT `fk_saved_opts_saved` FOREIGN KEY (`saved_customization_id`)
    REFERENCES `saved_customizations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_saved_opts_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal preferences + notification preferences + marketing consent
CREATE TABLE IF NOT EXISTS `customer_preferences` (
  `user_id`                 INT UNSIGNED NOT NULL,
  `preferred_branch_id`     INT UNSIGNED DEFAULT NULL,
  `preferred_size_label`    VARCHAR(30) DEFAULT NULL,
  `preferred_milk_option_id` INT UNSIGNED DEFAULT NULL,
  `preferred_sweetness`     TINYINT UNSIGNED DEFAULT NULL,  -- 0,25,50,75,100
  `preferred_temperature`   ENUM('hot','iced') DEFAULT NULL,
  `default_order_type`      ENUM('delivery','pickup','takeaway','dine_in') DEFAULT NULL,
  `notify_email`            TINYINT(1) NOT NULL DEFAULT 1,
  `notify_sms`              TINYINT(1) NOT NULL DEFAULT 0,
  `notify_push`             TINYINT(1) NOT NULL DEFAULT 0,
  `notify_in_app`           TINYINT(1) NOT NULL DEFAULT 1,
  `marketing_consent`       TINYINT(1) NOT NULL DEFAULT 0,
  `marketing_consent_at`    DATETIME DEFAULT NULL,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_cust_pref_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cust_pref_branch` FOREIGN KEY (`preferred_branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cust_pref_milk` FOREIGN KEY (`preferred_milk_option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Frequently selected add-ons (syrups, toppings, extra shots)
CREATE TABLE IF NOT EXISTS `customer_preferred_options` (
  `user_id`   INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `option_id`),
  CONSTRAINT `fk_pref_options_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pref_options_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 7: BRANCH CATALOG AND BRANCH PRODUCT STOCK     (features 20, 21)
-- =====================================================================

-- A product with NO row here for a branch is treated as unavailable there.
-- price_override replaces the product's base/promo price ONLY for variants that
-- have no product_variants.price_override and no branch_variant_prices row.
CREATE TABLE IF NOT EXISTS `branch_products` (
  `branch_id`      INT UNSIGNED NOT NULL,
  `product_id`     INT UNSIGNED NOT NULL,
  `is_available`   TINYINT(1) NOT NULL DEFAULT 1,
  `price_override` DECIMAL(10,2) DEFAULT NULL,
  `is_featured`    TINYINT(1) NOT NULL DEFAULT 0,
  `display_order`  INT NOT NULL DEFAULT 0,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`, `product_id`),
  KEY `idx_branch_products_product` (`product_id`),
  CONSTRAINT `fk_branch_products_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branch_products_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Branch-specific price for ONE variant (e.g. 1kg beans costs more at the Mall
-- branch). Most specific price rule: wins over every other override.
-- Only the base price is overridden; additional_charge and customization
-- charges are still added on top.
CREATE TABLE IF NOT EXISTS `branch_variant_prices` (
  `branch_id`      INT UNSIGNED NOT NULL,
  `variant_id`     INT UNSIGNED NOT NULL,
  `price_override` DECIMAL(10,2) NOT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`, `variant_id`),
  KEY `idx_branch_variant_prices_variant` (`variant_id`),
  CONSTRAINT `fk_branch_variant_prices_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branch_variant_prices_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branch_variant_prices_user` FOREIGN KEY (`updated_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_branch_variant_price` CHECK (`price_override` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-branch quantity for inventory_mode = 'product_stock' products only.
-- Made-to-order drinks (inventory_mode = 'recipe_ingredients') have NO rows here.
CREATE TABLE IF NOT EXISTS `branch_product_stock` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`           INT UNSIGNED NOT NULL,
  `product_id`          INT UNSIGNED NOT NULL,
  `variant_id`          INT UNSIGNED NOT NULL,
  `quantity`            INT NOT NULL DEFAULT 0,
  `low_stock_threshold` INT DEFAULT NULL,                   -- NULL = variant/product default
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_stock_variant` (`branch_id`, `variant_id`),
  KEY `idx_branch_stock_product` (`product_id`),
  CONSTRAINT `fk_branch_stock_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branch_stock_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branch_stock_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_branch_stock_qty` CHECK (`quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 8: INGREDIENTS AND RECIPES                    (features 26, 27)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ingredients` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku`                 VARCHAR(50) NOT NULL,
  `name`                VARCHAR(150) NOT NULL,
  `category`            ENUM('coffee','dairy','alt_milk','sweetener','syrup','topping','packaging','other') NOT NULL DEFAULT 'other',
  `unit_of_measure`     VARCHAR(20) NOT NULL DEFAULT 'pcs', -- g, kg, ml, l, pcs, box
  `cost_per_unit`       DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `low_stock_threshold` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ingredients_sku` (`sku`),
  KEY `idx_ingredients_category` (`category`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `branch_ingredient_stock` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`           INT UNSIGNED NOT NULL,
  `ingredient_id`       INT UNSIGNED NOT NULL,
  `quantity`            DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `low_stock_threshold` DECIMAL(12,3) DEFAULT NULL,         -- NULL = ingredients.low_stock_threshold
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_ingredient` (`branch_id`, `ingredient_id`),
  KEY `idx_branch_ingredient_ing` (`ingredient_id`),
  CONSTRAINT `fk_branch_ing_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branch_ing_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_branch_ing_qty` CHECK (`quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One ACTIVE recipe per variant; edits create a new `version` and deactivate
-- the old one so historical consumption stays traceable.
CREATE TABLE IF NOT EXISTS `product_recipes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `version`    INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `notes`      VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipes_variant_version` (`variant_id`, `version`),
  KEY `idx_recipes_product` (`product_id`),
  CONSTRAINT `fk_recipes_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_recipes_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_recipes_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recipe_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id`     INT UNSIGNED NOT NULL,
  `ingredient_id` INT UNSIGNED NOT NULL,
  `quantity`      DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipe_ingredient` (`recipe_id`, `ingredient_id`),
  KEY `idx_recipe_items_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_recipe_items_recipe` FOREIGN KEY (`recipe_id`)
    REFERENCES `product_recipes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_recipe_items_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingredients consumed by a customization option (oat milk, extra shot, syrup)
CREATE TABLE IF NOT EXISTS `customization_option_ingredients` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `option_id`     INT UNSIGNED NOT NULL,
  `ingredient_id` INT UNSIGNED NOT NULL,
  `quantity`      DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_option_ingredient` (`option_id`, `ingredient_id`),
  KEY `idx_option_ing_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_option_ing_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_option_ing_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 9: SUPPLIERS, PURCHASING, BRANCH TRANSFERS     (features 21, 29)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(120) DEFAULT NULL,
  `email`          VARCHAR(190) DEFAULT NULL,
  `phone`          VARCHAR(30) DEFAULT NULL,
  `address`        VARCHAR(255) DEFAULT NULL,
  `city`           VARCHAR(100) DEFAULT NULL,
  `province`       VARCHAR(100) DEFAULT NULL,
  `postal_code`    VARCHAR(20) DEFAULT NULL,
  `country`        VARCHAR(100) NOT NULL DEFAULT 'Philippines',
  `payment_terms`  VARCHAR(100) DEFAULT NULL,
  `notes`          TEXT DEFAULT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_name` (`name`),
  KEY `idx_suppliers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_number`     VARCHAR(30) NOT NULL,
  `supplier_id`   INT UNSIGNED NOT NULL,
  `branch_id`     INT UNSIGNED NOT NULL,                    -- receiving branch
  `status`        ENUM('draft','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `order_date`    DATE DEFAULT NULL,
  `expected_date` DATE DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `subtotal`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_total`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_cost`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes`         TEXT DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `received_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_number` (`po_number`),
  KEY `idx_po_supplier` (`supplier_id`),
  KEY `idx_po_branch_status` (`branch_id`, `status`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_po_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_po_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_po_received_by` FOREIGN KEY (`received_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` INT UNSIGNED NOT NULL,
  `ingredient_id`     INT UNSIGNED NOT NULL,
  `quantity_ordered`  DECIMAL(12,3) NOT NULL,
  `quantity_received` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost`         DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `line_total`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes`             VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_item_ingredient` (`purchase_order_id`, `ingredient_id`),
  KEY `idx_po_items_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_po_items_po` FOREIGN KEY (`purchase_order_id`)
    REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_po_items_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_transfers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_number` VARCHAR(30) NOT NULL,
  `from_branch_id`  INT UNSIGNED NOT NULL,
  `to_branch_id`    INT UNSIGNED NOT NULL,
  `status`          ENUM('pending','in_transit','received','cancelled') NOT NULL DEFAULT 'pending',
  `requested_by`    INT UNSIGNED DEFAULT NULL,
  `approved_by`     INT UNSIGNED DEFAULT NULL,
  `shipped_at`      DATETIME DEFAULT NULL,
  `received_at`     DATETIME DEFAULT NULL,
  `notes`           VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transfer_number` (`transfer_number`),
  KEY `idx_transfers_from` (`from_branch_id`),
  KEY `idx_transfers_to` (`to_branch_id`),
  CONSTRAINT `fk_transfers_from` FOREIGN KEY (`from_branch_id`)
    REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfers_to` FOREIGN KEY (`to_branch_id`)
    REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfers_requested_by` FOREIGN KEY (`requested_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transfers_approved_by` FOREIGN KEY (`approved_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_transfer_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_id`   INT UNSIGNED NOT NULL,
  `item_type`     ENUM('product','ingredient') NOT NULL,
  `variant_id`    INT UNSIGNED DEFAULT NULL,                -- when item_type = product
  `ingredient_id` INT UNSIGNED DEFAULT NULL,                -- when item_type = ingredient
  `quantity`      DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transfer_items_transfer` (`transfer_id`),
  KEY `idx_transfer_items_variant` (`variant_id`),
  KEY `idx_transfer_items_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_transfer_items_transfer` FOREIGN KEY (`transfer_id`)
    REFERENCES `inventory_transfers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_items_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_transfer_items_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 10: PROMOTIONS AND COUPONS                          (feature 16)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `promotions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `banner_path` VARCHAR(255) DEFAULT NULL,
  `starts_at`   DATETIME DEFAULT NULL,
  `ends_at`     DATETIME DEFAULT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_promotions_active_dates` (`is_active`, `starts_at`, `ends_at`),
  CONSTRAINT `fk_promotions_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promotion_id`       INT UNSIGNED DEFAULT NULL,
  `code`               VARCHAR(40) NOT NULL,
  `description`        VARCHAR(255) DEFAULT NULL,
  `discount_type`      ENUM('percentage','fixed') NOT NULL,
  `discount_value`     DECIMAL(10,2) NOT NULL,
  `max_discount_amount` DECIMAL(10,2) DEFAULT NULL,         -- cap for percentage coupons
  `min_purchase_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `applies_to`         ENUM('order','products','categories') NOT NULL DEFAULT 'order',
  `is_customer_specific` TINYINT(1) NOT NULL DEFAULT 0,     -- see customer_coupons
  `usage_limit`        INT UNSIGNED DEFAULT NULL,           -- total uses, NULL = unlimited
  `per_customer_limit` INT UNSIGNED DEFAULT NULL,
  `used_count`         INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at`          DATETIME DEFAULT NULL,
  `expires_at`         DATETIME DEFAULT NULL,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`         INT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupons_code` (`code`),
  KEY `idx_coupons_promotion` (`promotion_id`),
  KEY `idx_coupons_active_dates` (`is_active`, `starts_at`, `expires_at`),
  CONSTRAINT `fk_coupons_promotion` FOREIGN KEY (`promotion_id`)
    REFERENCES `promotions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_coupons_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_products` (
  `coupon_id`  INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `product_id`),
  CONSTRAINT `fk_coupon_products_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_products_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_categories` (
  `coupon_id`   INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `category_id`),
  CONSTRAINT `fk_coupon_categories_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_categories_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coupons assigned to specific customers (shown in their dashboard)
CREATE TABLE IF NOT EXISTS `customer_coupons` (
  `coupon_id`   INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED DEFAULT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at`     DATETIME DEFAULT NULL,
  PRIMARY KEY (`coupon_id`, `user_id`),
  KEY `idx_customer_coupons_user` (`user_id`),
  CONSTRAINT `fk_customer_coupons_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_customer_coupons_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_customer_coupons_assigned_by` FOREIGN KEY (`assigned_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 11: LOYALTY PROGRAM AND REWARDS                (features 14, 15)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `loyalty_tiers` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(60) NOT NULL,
  `min_lifetime_points` INT UNSIGNED NOT NULL DEFAULT 0,
  `points_multiplier`   DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  `benefits`            TEXT DEFAULT NULL,
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_tiers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loyalty_accounts` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                  INT UNSIGNED NOT NULL,
  `tier_id`                  INT UNSIGNED DEFAULT NULL,
  `points_balance`           INT NOT NULL DEFAULT 0,
  `lifetime_points_earned`   INT UNSIGNED NOT NULL DEFAULT 0,
  `lifetime_points_redeemed` INT UNSIGNED NOT NULL DEFAULT 0,
  `lifetime_points_expired`  INT UNSIGNED NOT NULL DEFAULT 0,
  `status`                   ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
  `enrolled_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_accounts_user` (`user_id`),
  KEY `idx_loyalty_accounts_tier` (`tier_id`),
  CONSTRAINT `fk_loyalty_accounts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_accounts_tier` FOREIGN KEY (`tier_id`)
    REFERENCES `loyalty_tiers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_loyalty_balance` CHECK (`points_balance` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurable earning rules.
--   per_amount_spent: `points_awarded` points for every `spend_amount` PHP spent
CREATE TABLE IF NOT EXISTS `loyalty_rules` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(120) NOT NULL,
  `rule_type`          ENUM('per_amount_spent','per_order','signup_bonus','category_bonus','product_bonus') NOT NULL,
  `points_awarded`     INT UNSIGNED NOT NULL DEFAULT 1,
  `spend_amount`       DECIMAL(10,2) DEFAULT NULL,
  `min_order_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `category_id`        INT UNSIGNED DEFAULT NULL,
  `product_id`         INT UNSIGNED DEFAULT NULL,
  `points_expire_days` INT UNSIGNED DEFAULT NULL,           -- NULL = never expire
  `starts_at`          DATETIME DEFAULT NULL,
  `ends_at`            DATETIME DEFAULT NULL,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_rules_active` (`is_active`, `rule_type`),
  CONSTRAINT `fk_loyalty_rules_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_rules_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rewards` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(150) NOT NULL,
  `description`     TEXT DEFAULT NULL,
  `points_required` INT UNSIGNED NOT NULL,
  `reward_type`     ENUM('free_product','percentage_discount','fixed_discount','other') NOT NULL,
  `product_id`      INT UNSIGNED DEFAULT NULL,              -- for free_product
  `discount_value`  DECIMAL(10,2) DEFAULT NULL,
  `validity_days`   INT UNSIGNED NOT NULL DEFAULT 30,       -- days a redeemed voucher stays usable
  `max_redemptions` INT UNSIGNED DEFAULT NULL,
  `redeemed_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `available_from`  DATETIME DEFAULT NULL,
  `expires_at`      DATETIME DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rewards_active` (`is_active`, `points_required`),
  CONSTRAINT `fk_rewards_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A redeemed reward = a voucher the customer can apply at checkout.
-- `order_id` gets its foreign key in the "circular references" section.
CREATE TABLE IF NOT EXISTS `reward_redemptions` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT UNSIGNED NOT NULL,
  `reward_id`          INT UNSIGNED NOT NULL,
  `loyalty_account_id` INT UNSIGNED NOT NULL,
  `points_spent`       INT UNSIGNED NOT NULL,
  `redemption_code`    VARCHAR(40) NOT NULL,
  `status`             ENUM('available','applied','used','expired','cancelled') NOT NULL DEFAULT 'available',
  `order_id`           INT UNSIGNED DEFAULT NULL,
  `redeemed_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`         DATETIME DEFAULT NULL,
  `used_at`            DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reward_redemption_code` (`redemption_code`),
  KEY `idx_reward_redemptions_user` (`user_id`, `status`),
  KEY `idx_reward_redemptions_reward` (`reward_id`),
  KEY `idx_reward_redemptions_order` (`order_id`),
  CONSTRAINT `fk_reward_redemptions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_reward_redemptions_reward` FOREIGN KEY (`reward_id`)
    REFERENCES `rewards` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_reward_redemptions_account` FOREIGN KEY (`loyalty_account_id`)
    REFERENCES `loyalty_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 12: SHIPPING / ORDER-TYPE METHODS             (features 3, 8, 33)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `shipping_methods` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                    VARCHAR(40) NOT NULL,
  `name`                    VARCHAR(100) NOT NULL,
  `description`             VARCHAR(255) DEFAULT NULL,
  `order_type`              ENUM('delivery','pickup','takeaway','dine_in') NOT NULL DEFAULT 'delivery',
  `base_fee`                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `free_delivery_threshold` DECIMAL(10,2) DEFAULT NULL,     -- subtotal at/above which fee = 0
  `estimated_min_minutes`   INT UNSIGNED DEFAULT NULL,
  `estimated_max_minutes`   INT UNSIGNED DEFAULT NULL,
  `is_active`               TINYINT(1) NOT NULL DEFAULT 1,
  `display_order`           INT NOT NULL DEFAULT 0,
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shipping_methods_code` (`code`),
  KEY `idx_shipping_methods_type` (`order_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 13: SHOPPING CART (guest + registered, abandonment tracking)
--                                                  (features 3, 46)
-- =====================================================================

-- `converted_order_id` gets its foreign key in the "circular references" section.
CREATE TABLE IF NOT EXISTS `carts` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT UNSIGNED DEFAULT NULL,           -- NULL = guest cart
  `session_id`         VARCHAR(128) DEFAULT NULL,           -- PHP session_id() for guests
  `branch_id`          INT UNSIGNED DEFAULT NULL,
  `order_type`         ENUM('delivery','pickup','takeaway','dine_in') DEFAULT NULL,
  `shipping_method_id` INT UNSIGNED DEFAULT NULL,
  `coupon_code`        VARCHAR(40) DEFAULT NULL,
  `status`             ENUM('active','converted','abandoned','merged','expired') NOT NULL DEFAULT 'active',
  `last_activity_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`         DATETIME DEFAULT NULL,
  `abandoned_at`       DATETIME DEFAULT NULL,
  `converted_at`       DATETIME DEFAULT NULL,
  `converted_order_id` INT UNSIGNED DEFAULT NULL,
  `merged_into_cart_id` INT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_carts_user_status` (`user_id`, `status`),
  KEY `idx_carts_session_status` (`session_id`, `status`),
  KEY `idx_carts_branch` (`branch_id`),
  KEY `idx_carts_activity` (`status`, `last_activity_at`),
  KEY `idx_carts_converted_order` (`converted_order_id`),
  CONSTRAINT `fk_carts_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_carts_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_carts_shipping_method` FOREIGN KEY (`shipping_method_id`)
    REFERENCES `shipping_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_carts_merged_into` FOREIGN KEY (`merged_into_cart_id`)
    REFERENCES `carts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `customization_hash` = sha1 of the sorted option ids + quantities, so the
-- same drink with the same customizations merges into one line (and a
-- different customization becomes a separate line). Plain items use ''.
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id`            INT UNSIGNED NOT NULL,
  `product_id`         INT UNSIGNED NOT NULL,
  `variant_id`         INT UNSIGNED NOT NULL,
  `quantity`           INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price`         DECIMAL(10,2) NOT NULL,              -- display only; recalculated at checkout
  `customization_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `customization_hash` CHAR(40) NOT NULL DEFAULT '',        -- gift cards: also hash the gift_* fields so each card is its own line
  `special_instructions` VARCHAR(255) DEFAULT NULL,
  -- Digital gift card details (only for product_type = 'gift_card'; keep quantity = 1 per line)
  `gift_sender_name`     VARCHAR(150) DEFAULT NULL,
  `gift_recipient_name`  VARCHAR(150) DEFAULT NULL,
  `gift_recipient_email` VARCHAR(190) DEFAULT NULL,
  `gift_message`         VARCHAR(500) DEFAULT NULL,
  `gift_delivery_date`   DATE DEFAULT NULL,                 -- NULL = send right after payment
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_items_line` (`cart_id`, `variant_id`, `customization_hash`),
  KEY `idx_cart_items_product` (`product_id`),
  KEY `idx_cart_items_variant` (`variant_id`),
  CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`)
    REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cart_items_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cart_items_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_cart_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart_item_options` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_item_id`     INT UNSIGNED NOT NULL,
  `option_id`        INT UNSIGNED NOT NULL,
  `quantity`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `additional_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_item_option` (`cart_item_id`, `option_id`),
  KEY `idx_cart_item_options_option` (`option_id`),
  CONSTRAINT `fk_cart_item_options_item` FOREIGN KEY (`cart_item_id`)
    REFERENCES `cart_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cart_item_options_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 14: ORDERS                         (features 7, 8, 10, 11, 31, 32)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `orders` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number`           VARCHAR(30) NOT NULL,
  `user_id`                INT UNSIGNED DEFAULT NULL,       -- NULL = guest checkout
  `is_guest`               TINYINT(1) NOT NULL DEFAULT 0,
  `customer_name`          VARCHAR(150) NOT NULL,
  `customer_email`         VARCHAR(190) NOT NULL,
  `customer_phone`         VARCHAR(30) NOT NULL,
  `branch_id`              INT UNSIGNED DEFAULT NULL,
  `order_type`             ENUM('delivery','pickup','takeaway','dine_in') NOT NULL DEFAULT 'pickup',
  `order_channel`          ENUM('desktop','mobile_web','mobile_app','in_store') NOT NULL DEFAULT 'desktop',
  `shipping_method_id`     INT UNSIGNED DEFAULT NULL,
  `status`                 ENUM('pending','confirmed','processing','preparing','ready_for_pickup','picked_up','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `payment_status`         ENUM('pending','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'pending',
  `payment_method`         ENUM('credit_card','debit_card','gcash','paypal','cash_on_delivery','wallet','gift_card') DEFAULT NULL,

  -- totals (always recalculated server-side)
  `subtotal`               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `coupon_id`              INT UNSIGNED DEFAULT NULL,
  `coupon_code`            VARCHAR(40) DEFAULT NULL,
  `coupon_discount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reward_redemption_id`   INT UNSIGNED DEFAULT NULL,
  `reward_discount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_total`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate`               DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_total`              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `grand_total`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gift_card_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- portion paid by gift card
  `wallet_amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- portion paid by wallet
  `refunded_total`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- billing snapshot
  `billing_name`           VARCHAR(150) NOT NULL,
  `billing_email`          VARCHAR(190) NOT NULL,
  `billing_phone`          VARCHAR(30) NOT NULL,
  `billing_address`        VARCHAR(255) NOT NULL,
  `billing_city`           VARCHAR(100) NOT NULL,
  `billing_province`       VARCHAR(100) NOT NULL,
  `billing_postal_code`    VARCHAR(20) NOT NULL,
  `billing_country`        VARCHAR(100) NOT NULL DEFAULT 'Philippines',

  -- shipping snapshot (store pickup: copy the branch address)
  `shipping_name`          VARCHAR(150) DEFAULT NULL,
  `shipping_phone`         VARCHAR(30) DEFAULT NULL,
  `shipping_address`       VARCHAR(255) DEFAULT NULL,
  `shipping_city`          VARCHAR(100) DEFAULT NULL,
  `shipping_province`      VARCHAR(100) DEFAULT NULL,
  `shipping_postal_code`   VARCHAR(20) DEFAULT NULL,
  `shipping_country`       VARCHAR(100) DEFAULT 'Philippines',

  `notes`                  TEXT DEFAULT NULL,               -- customer order notes
  `special_instructions`   TEXT DEFAULT NULL,
  `admin_notes`            TEXT DEFAULT NULL,

  -- fulfillment timeline
  `requested_pickup_at`    DATETIME DEFAULT NULL,
  `estimated_ready_at`     DATETIME DEFAULT NULL,
  `estimated_delivery_at`  DATETIME DEFAULT NULL,
  `confirmed_at`           DATETIME DEFAULT NULL,
  `preparation_started_at` DATETIME DEFAULT NULL,
  `ready_at`               DATETIME DEFAULT NULL,
  `picked_up_at`           DATETIME DEFAULT NULL,
  `shipped_at`             DATETIME DEFAULT NULL,
  `delivered_at`           DATETIME DEFAULT NULL,
  `cancelled_at`           DATETIME DEFAULT NULL,

  `assigned_to`            INT UNSIGNED DEFAULT NULL,       -- fulfillment employee
  `idempotency_token`      VARCHAR(64) DEFAULT NULL,        -- blocks duplicate submissions
  `stock_deducted`         TINYINT(1) NOT NULL DEFAULT 0,
  `ingredients_deducted`   TINYINT(1) NOT NULL DEFAULT 0,
  `loyalty_points_earned`  INT UNSIGNED NOT NULL DEFAULT 0,
  `loyalty_points_awarded` TINYINT(1) NOT NULL DEFAULT 0,
  `placed_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_number` (`order_number`),
  UNIQUE KEY `uq_orders_idempotency` (`idempotency_token`),
  KEY `idx_orders_user` (`user_id`, `placed_at`),
  KEY `idx_orders_branch_status` (`branch_id`, `status`),
  KEY `idx_orders_status` (`status`, `placed_at`),
  KEY `idx_orders_payment_status` (`payment_status`),
  KEY `idx_orders_type` (`order_type`),
  KEY `idx_orders_placed_at` (`placed_at`),
  KEY `idx_orders_email` (`customer_email`),
  KEY `idx_orders_assigned_to` (`assigned_to`),
  KEY `idx_orders_coupon` (`coupon_id`),
  KEY `idx_orders_reward` (`reward_redemption_id`),
  KEY `idx_orders_shipping_method` (`shipping_method_id`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_shipping_method` FOREIGN KEY (`shipping_method_id`)
    REFERENCES `shipping_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_reward_redemption` FOREIGN KEY (`reward_redemption_id`)
    REFERENCES `reward_redemptions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_assigned_to` FOREIGN KEY (`assigned_to`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of what was bought: names/SKUs/prices stay even if the product changes.
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`             INT UNSIGNED NOT NULL,
  `product_id`           INT UNSIGNED DEFAULT NULL,
  `variant_id`           INT UNSIGNED DEFAULT NULL,
  `product_name`         VARCHAR(160) NOT NULL,
  `variant_name`         VARCHAR(100) DEFAULT NULL,
  `sku`                  VARCHAR(60) NOT NULL,
  `product_type`         VARCHAR(30) DEFAULT NULL,
  `quantity`             INT UNSIGNED NOT NULL,
  `unit_price`           DECIMAL(10,2) NOT NULL,            -- price before customizations
  `customization_total`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_discount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total`           DECIMAL(10,2) NOT NULL,            -- (unit_price + customization_total) * qty - discount
  `special_instructions` VARCHAR(255) DEFAULT NULL,
  -- Gift card snapshot (product_type = 'gift_card'). Checkout copies these from
  -- cart_items, creates the gift_cards row in 'pending' status and links it with
  -- gift_cards.purchase_order_item_id. One order line = one card (quantity 1).
  `gift_sender_name`     VARCHAR(150) DEFAULT NULL,
  `gift_recipient_name`  VARCHAR(150) DEFAULT NULL,
  `gift_recipient_email` VARCHAR(190) DEFAULT NULL,
  `gift_message`         VARCHAR(500) DEFAULT NULL,
  `gift_delivery_date`   DATE DEFAULT NULL,
  `returned_quantity`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_product` (`product_id`),
  KEY `idx_order_items_variant` (`variant_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_order_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_item_options` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_item_id`    INT UNSIGNED NOT NULL,
  `option_id`        INT UNSIGNED DEFAULT NULL,
  `group_name`       VARCHAR(100) NOT NULL,
  `option_name`      VARCHAR(100) NOT NULL,
  `quantity`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `additional_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_order_item_options_item` (`order_item_id`),
  KEY `idx_order_item_options_option` (`option_id`),
  CONSTRAINT `fk_order_item_options_item` FOREIGN KEY (`order_item_id`)
    REFERENCES `order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_item_options_option` FOREIGN KEY (`option_id`)
    REFERENCES `customization_options` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chronological status log (who changed it and when).
CREATE TABLE IF NOT EXISTS `order_status_history` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED NOT NULL,
  `old_status`  VARCHAR(30) DEFAULT NULL,
  `new_status`  VARCHAR(30) NOT NULL,
  `note`        VARCHAR(255) DEFAULT NULL,
  `changed_by`  INT UNSIGNED DEFAULT NULL,                  -- NULL = system / customer action
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_status_history_order` (`order_id`, `created_at`),
  KEY `idx_order_status_history_user` (`changed_by`),
  CONSTRAINT `fk_order_status_history_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_status_history_user` FOREIGN KEY (`changed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 15: PAYMENTS (MOCK) AND SHIPMENTS                (features 9, 33)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `payments` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`          INT UNSIGNED NOT NULL,
  `payment_reference` VARCHAR(50) NOT NULL,                 -- mock transaction reference
  `method`            ENUM('credit_card','debit_card','gcash','paypal','cash_on_delivery','wallet','gift_card') NOT NULL,
  `status`            ENUM('pending','successful','failed','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  `amount`            DECIMAL(10,2) NOT NULL,
  `refunded_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`          CHAR(3) NOT NULL DEFAULT 'PHP',
  `card_brand`        VARCHAR(30) DEFAULT NULL,
  `card_last4`        CHAR(4) DEFAULT NULL,
  `card_holder_name`  VARCHAR(150) DEFAULT NULL,
  `gateway_provider`  VARCHAR(50) NOT NULL DEFAULT 'mock',
  `provider_reference` VARCHAR(100) DEFAULT NULL,           -- future real gateway id/token
  `failure_reason`    VARCHAR(255) DEFAULT NULL,
  `idempotency_key`   VARCHAR(64) DEFAULT NULL,             -- prevents duplicate payments
  `paid_at`           DATETIME DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_reference` (`payment_reference`),
  UNIQUE KEY `uq_payments_idempotency` (`idempotency_key`),
  KEY `idx_payments_order` (`order_id`),
  KEY `idx_payments_status` (`status`, `paid_at`),
  KEY `idx_payments_method` (`method`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipments` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`              INT UNSIGNED NOT NULL,
  `courier_name`          VARCHAR(100) DEFAULT NULL,
  `courier_user_id`       INT UNSIGNED DEFAULT NULL,        -- in-house rider (employee), optional
  `tracking_number`       VARCHAR(100) DEFAULT NULL,
  `status`                ENUM('pending','assigned','in_transit','out_for_delivery','delivered','failed','returned') NOT NULL DEFAULT 'pending',
  `shipped_at`            DATETIME DEFAULT NULL,
  `estimated_delivery_at` DATETIME DEFAULT NULL,
  `delivered_at`          DATETIME DEFAULT NULL,
  `delivery_notes`        VARCHAR(255) DEFAULT NULL,
  `created_by`            INT UNSIGNED DEFAULT NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shipments_order` (`order_id`),
  KEY `idx_shipments_tracking` (`tracking_number`),
  KEY `idx_shipments_status` (`status`),
  CONSTRAINT `fk_shipments_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_shipments_courier_user` FOREIGN KEY (`courier_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_shipments_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipment_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shipment_id` INT UNSIGNED NOT NULL,
  `status`      VARCHAR(30) NOT NULL,
  `location`    VARCHAR(150) DEFAULT NULL,
  `notes`       VARCHAR(255) DEFAULT NULL,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shipment_events_shipment` (`shipment_id`, `created_at`),
  CONSTRAINT `fk_shipment_events_shipment` FOREIGN KEY (`shipment_id`)
    REFERENCES `shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_shipment_events_user` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 16: COUPON USAGE AND LOYALTY LEDGER        (features 14, 15, 16)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `coupon_usages` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id`       INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED DEFAULT NULL,
  `order_id`        INT UNSIGNED NOT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL,
  `used_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_usage_order` (`coupon_id`, `order_id`),
  KEY `idx_coupon_usages_user` (`user_id`),
  KEY `idx_coupon_usages_order` (`order_id`),
  CONSTRAINT `fk_coupon_usages_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_usages_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_usages_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every point movement is a row (points: + earned/refunded, - redeemed/expired).
CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loyalty_account_id`    INT UNSIGNED NOT NULL,
  `transaction_type`      ENUM('earn','redeem','expire','adjustment','reversal','bonus') NOT NULL,
  `points`                INT NOT NULL,
  `balance_after`         INT NOT NULL,
  `order_id`              INT UNSIGNED DEFAULT NULL,
  `reward_redemption_id`  INT UNSIGNED DEFAULT NULL,
  `rule_id`               INT UNSIGNED DEFAULT NULL,
  `description`           VARCHAR(255) DEFAULT NULL,
  `expires_at`            DATETIME DEFAULT NULL,            -- expiry of earned points
  `created_by`            INT UNSIGNED DEFAULT NULL,        -- admin for manual adjustments
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_tx_account` (`loyalty_account_id`, `created_at`),
  KEY `idx_loyalty_tx_order` (`order_id`),
  KEY `idx_loyalty_tx_type` (`transaction_type`),
  KEY `idx_loyalty_tx_expiry` (`expires_at`),
  CONSTRAINT `fk_loyalty_tx_account` FOREIGN KEY (`loyalty_account_id`)
    REFERENCES `loyalty_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_tx_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_tx_redemption` FOREIGN KEY (`reward_redemption_id`)
    REFERENCES `reward_redemptions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_tx_rule` FOREIGN KEY (`rule_id`)
    REFERENCES `loyalty_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_loyalty_tx_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 17: GIFT CARDS AND WALLETS                     (features 17, 18)
-- =====================================================================

-- GIFT CARD PURCHASE FLOW (all in PHP, inside db_transaction):
--   1. Checkout: for each gift-card order line INSERT a gift_cards row with
--      status = 'pending', balance = initial_value, purchase_order_id,
--      purchase_order_item_id, sender/recipient/message/delivery_date copied
--      from the order line. A 'pending' card can NOT be redeemed.
--   2. Payment successful: UPDATE gift_cards SET status = 'active', expires_at = ...
--      and INSERT gift_card_transactions ('purchase', reference 'gc-purchase:<id>').
--   3. If delivery_date IS NULL or <= today: e-mail it now (event 'gift_card_delivery',
--      dedupe_key 'gift_card_delivery:<id>:email') and set sent_at.
--      Otherwise the maintenance job 'gift_card_delivery' sends it on delivery_date.
--   4. Payment failed / order cancelled before payment: set status = 'cancelled'.
CREATE TABLE IF NOT EXISTS `gift_cards` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`               VARCHAR(40) NOT NULL,
  `initial_value`      DECIMAL(10,2) NOT NULL,
  `balance`            DECIMAL(10,2) NOT NULL,
  `currency`           CHAR(3) NOT NULL DEFAULT 'PHP',
  `purchaser_user_id`  INT UNSIGNED DEFAULT NULL,
  `purchase_order_id`  INT UNSIGNED DEFAULT NULL,
  `purchase_order_item_id` INT UNSIGNED DEFAULT NULL,       -- the order line that bought this card (one card per line)
  `sender_name`        VARCHAR(150) DEFAULT NULL,
  `recipient_name`     VARCHAR(150) DEFAULT NULL,
  `recipient_email`    VARCHAR(190) DEFAULT NULL,
  `message`            VARCHAR(500) DEFAULT NULL,
  `delivery_date`      DATE DEFAULT NULL,                   -- scheduled send date
  `sent_at`            DATETIME DEFAULT NULL,
  `status`             ENUM('pending','active','redeemed','expired','cancelled') NOT NULL DEFAULT 'pending',
  `expires_at`         DATETIME DEFAULT NULL,
  `redeemed_by_user_id` INT UNSIGNED DEFAULT NULL,
  `last_used_at`       DATETIME DEFAULT NULL,
  `created_by`         INT UNSIGNED DEFAULT NULL,           -- admin when issued manually
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gift_cards_code` (`code`),
  KEY `idx_gift_cards_purchaser` (`purchaser_user_id`),
  KEY `idx_gift_cards_recipient_email` (`recipient_email`),
  KEY `idx_gift_cards_status` (`status`, `expires_at`),
  KEY `idx_gift_cards_order` (`purchase_order_id`),
  UNIQUE KEY `uq_gift_cards_order_item` (`purchase_order_item_id`),
  KEY `idx_gift_cards_delivery` (`status`, `delivery_date`, `sent_at`),
  CONSTRAINT `fk_gift_cards_purchaser` FOREIGN KEY (`purchaser_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_cards_order` FOREIGN KEY (`purchase_order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_cards_order_item` FOREIGN KEY (`purchase_order_item_id`)
    REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_cards_redeemed_by` FOREIGN KEY (`redeemed_by_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_cards_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_gift_cards_balance` CHECK (`balance` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- amount: + loads/refunds, - redemptions. `reference` is unique to stop duplicates.
CREATE TABLE IF NOT EXISTS `gift_card_transactions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gift_card_id`     INT UNSIGNED NOT NULL,
  `transaction_type` ENUM('purchase','redemption','refund','adjustment','expiration') NOT NULL,
  `amount`           DECIMAL(10,2) NOT NULL,
  `balance_after`    DECIMAL(10,2) NOT NULL,
  `order_id`         INT UNSIGNED DEFAULT NULL,
  `user_id`          INT UNSIGNED DEFAULT NULL,
  `reference`        VARCHAR(64) DEFAULT NULL,
  `notes`            VARCHAR(255) DEFAULT NULL,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gift_card_tx_reference` (`reference`),
  KEY `idx_gift_card_tx_card` (`gift_card_id`, `created_at`),
  KEY `idx_gift_card_tx_order` (`order_id`),
  KEY `idx_gift_card_tx_user` (`user_id`),
  CONSTRAINT `fk_gift_card_tx_card` FOREIGN KEY (`gift_card_id`)
    REFERENCES `gift_cards` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_card_tx_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_card_tx_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_gift_card_tx_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `balance`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`   CHAR(3) NOT NULL DEFAULT 'PHP',
  `status`     ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_user` (`user_id`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_wallets_balance` CHECK (`balance` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 18: RETURNS, CANCELLATIONS, REFUNDS      (features 13, 34-37)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `return_reasons` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_type`  ENUM('cancellation','return','both') NOT NULL DEFAULT 'both',
  `label`         VARCHAR(150) NOT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_return_reasons_type` (`request_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One table for both cancellation requests and return requests.
CREATE TABLE IF NOT EXISTS `return_requests` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_number`          VARCHAR(30) NOT NULL,
  `order_id`                INT UNSIGNED NOT NULL,
  `user_id`                 INT UNSIGNED DEFAULT NULL,
  `request_type`            ENUM('cancellation','return') NOT NULL,
  `reason_id`               INT UNSIGNED DEFAULT NULL,
  `reason_text`             VARCHAR(150) NOT NULL,          -- snapshot of the chosen reason
  `details`                 TEXT DEFAULT NULL,
  `status`                  ENUM('pending','approved','rejected','item_received','refunded','completed','cancelled') NOT NULL DEFAULT 'pending',
  `requested_refund_method` ENUM('original_payment','store_credit','wallet') NOT NULL DEFAULT 'original_payment',
  `admin_notes`             TEXT DEFAULT NULL,
  `reviewed_by`             INT UNSIGNED DEFAULT NULL,
  `reviewed_at`             DATETIME DEFAULT NULL,
  `received_by`             INT UNSIGNED DEFAULT NULL,
  `received_at`             DATETIME DEFAULT NULL,
  `completed_at`            DATETIME DEFAULT NULL,
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_return_requests_number` (`request_number`),
  KEY `idx_return_requests_order` (`order_id`),
  KEY `idx_return_requests_user` (`user_id`, `created_at`),
  KEY `idx_return_requests_status` (`status`, `request_type`),
  CONSTRAINT `fk_return_requests_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_return_requests_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_return_requests_reason` FOREIGN KEY (`reason_id`)
    REFERENCES `return_reasons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_return_requests_reviewed_by` FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_return_requests_received_by` FOREIGN KEY (`received_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items/quantities the customer wants to return. Also stores the inspection
-- result so only resellable goods are restocked.
CREATE TABLE IF NOT EXISTS `return_request_items` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_request_id`  INT UNSIGNED NOT NULL,
  `order_item_id`      INT UNSIGNED NOT NULL,
  `quantity`           INT UNSIGNED NOT NULL,
  `item_condition`     ENUM('pending_inspection','resellable','damaged','defective') NOT NULL DEFAULT 'pending_inspection',
  `restock_eligible`   TINYINT(1) NOT NULL DEFAULT 0,
  `restocked_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
  `restocked_at`       DATETIME DEFAULT NULL,
  `inspection_notes`   VARCHAR(255) DEFAULT NULL,
  `inspected_by`       INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_return_items_request_item` (`return_request_id`, `order_item_id`),
  KEY `idx_return_items_order_item` (`order_item_id`),
  CONSTRAINT `fk_return_items_request` FOREIGN KEY (`return_request_id`)
    REFERENCES `return_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_return_items_order_item` FOREIGN KEY (`order_item_id`)
    REFERENCES `order_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_return_items_inspected_by` FOREIGN KEY (`inspected_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_return_items_qty` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `return_request_history` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_request_id` INT UNSIGNED NOT NULL,
  `old_status`        VARCHAR(30) DEFAULT NULL,
  `new_status`        VARCHAR(30) NOT NULL,
  `note`              VARCHAR(255) DEFAULT NULL,
  `changed_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_history_request` (`return_request_id`, `created_at`),
  CONSTRAINT `fk_return_history_request` FOREIGN KEY (`return_request_id`)
    REFERENCES `return_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_return_history_user` FOREIGN KEY (`changed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refunds` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_reference`  VARCHAR(50) NOT NULL,
  `order_id`          INT UNSIGNED NOT NULL,
  `payment_id`        INT UNSIGNED DEFAULT NULL,
  `return_request_id` INT UNSIGNED DEFAULT NULL,
  `user_id`           INT UNSIGNED DEFAULT NULL,
  `refund_type`       ENUM('full','partial') NOT NULL,
  `refund_method`     ENUM('original_payment','store_credit','wallet') NOT NULL DEFAULT 'original_payment',
  `amount`            DECIMAL(10,2) NOT NULL,
  `status`            ENUM('pending','approved','processing','completed','failed','rejected') NOT NULL DEFAULT 'pending',
  `reason`            VARCHAR(255) DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `provider_reference` VARCHAR(100) DEFAULT NULL,
  `idempotency_key`   VARCHAR(64) DEFAULT NULL,             -- prevents duplicate refunds
  `processed_by`      INT UNSIGNED DEFAULT NULL,
  `processed_at`      DATETIME DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refunds_reference` (`refund_reference`),
  UNIQUE KEY `uq_refunds_idempotency` (`idempotency_key`),
  KEY `idx_refunds_order` (`order_id`),
  KEY `idx_refunds_payment` (`payment_id`),
  KEY `idx_refunds_return_request` (`return_request_id`),
  KEY `idx_refunds_user` (`user_id`),
  KEY `idx_refunds_status` (`status`, `processed_at`),
  CONSTRAINT `fk_refunds_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_payment` FOREIGN KEY (`payment_id`)
    REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_return_request` FOREIGN KEY (`return_request_id`)
    REFERENCES `return_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_processed_by` FOREIGN KEY (`processed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- amount: + deposits/refunds/credits, - withdrawals/payments.
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wallet_id`        INT UNSIGNED NOT NULL,
  `transaction_type` ENUM('deposit','withdrawal','payment','refund','store_credit','adjustment') NOT NULL,
  `amount`           DECIMAL(10,2) NOT NULL,
  `balance_after`    DECIMAL(10,2) NOT NULL,
  `order_id`         INT UNSIGNED DEFAULT NULL,
  `refund_id`        INT UNSIGNED DEFAULT NULL,
  `reference`        VARCHAR(64) DEFAULT NULL,              -- unique: prevents duplicate transactions
  `description`      VARCHAR(255) DEFAULT NULL,
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_tx_reference` (`reference`),
  KEY `idx_wallet_tx_wallet` (`wallet_id`, `created_at`),
  KEY `idx_wallet_tx_order` (`order_id`),
  KEY `idx_wallet_tx_refund` (`refund_id`),
  CONSTRAINT `fk_wallet_tx_wallet` FOREIGN KEY (`wallet_id`)
    REFERENCES `wallets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_tx_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_tx_refund` FOREIGN KEY (`refund_id`)
    REFERENCES `refunds` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_wallet_tx_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 19: INVENTORY LEDGER AND STOCK ALERTS      (features 28, 30, 37)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`         INT UNSIGNED NOT NULL,
  `item_type`         ENUM('product','ingredient') NOT NULL,
  `product_id`        INT UNSIGNED DEFAULT NULL,
  `variant_id`        INT UNSIGNED DEFAULT NULL,
  `ingredient_id`     INT UNSIGNED DEFAULT NULL,
  `transaction_type`  ENUM('initial_stock','purchase_received','restock','sale','ingredient_consumption','adjustment','damaged','wasted','return_restock','return_damaged','transfer_in','transfer_out') NOT NULL,
  `quantity_change`   DECIMAL(12,3) NOT NULL,               -- + in / - out
  `previous_stock`    DECIMAL(12,3) NOT NULL,
  `new_stock`         DECIMAL(12,3) NOT NULL,
  `order_id`          INT UNSIGNED DEFAULT NULL,
  `purchase_order_id` INT UNSIGNED DEFAULT NULL,
  `return_request_id` INT UNSIGNED DEFAULT NULL,
  `transfer_id`       INT UNSIGNED DEFAULT NULL,
  `notes`             VARCHAR(255) DEFAULT NULL,
  `performed_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inv_tx_branch_date` (`branch_id`, `created_at`),
  KEY `idx_inv_tx_variant` (`variant_id`, `created_at`),
  KEY `idx_inv_tx_product` (`product_id`),
  KEY `idx_inv_tx_ingredient` (`ingredient_id`, `created_at`),
  KEY `idx_inv_tx_type` (`transaction_type`),
  KEY `idx_inv_tx_order` (`order_id`),
  KEY `idx_inv_tx_po` (`purchase_order_id`),
  KEY `idx_inv_tx_return` (`return_request_id`),
  KEY `idx_inv_tx_transfer` (`transfer_id`),
  KEY `idx_inv_tx_user` (`performed_by`),
  CONSTRAINT `fk_inv_tx_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_po` FOREIGN KEY (`purchase_order_id`)
    REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_return` FOREIGN KEY (`return_request_id`)
    REFERENCES `return_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_transfer` FOREIGN KEY (`transfer_id`)
    REFERENCES `inventory_transfers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_tx_user` FOREIGN KEY (`performed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_alerts` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id`        INT UNSIGNED NOT NULL,
  `item_type`        ENUM('product','ingredient') NOT NULL,
  `variant_id`       INT UNSIGNED DEFAULT NULL,
  `ingredient_id`    INT UNSIGNED DEFAULT NULL,
  `alert_type`       ENUM('low_stock','out_of_stock') NOT NULL,
  `current_quantity` DECIMAL(12,3) NOT NULL,
  `threshold`        DECIMAL(12,3) NOT NULL,
  `status`           ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  `acknowledged_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at`      DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_alerts_branch_status` (`branch_id`, `status`),
  KEY `idx_stock_alerts_variant` (`variant_id`),
  KEY `idx_stock_alerts_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_stock_alerts_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_alerts_variant` FOREIGN KEY (`variant_id`)
    REFERENCES `product_variants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_alerts_ingredient` FOREIGN KEY (`ingredient_id`)
    REFERENCES `ingredients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_alerts_ack_by` FOREIGN KEY (`acknowledged_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 20: NOTIFICATIONS                      (features 12, 49, 50)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(60) NOT NULL,
  `channel`       ENUM('email','sms','push','in_app') NOT NULL DEFAULT 'email',
  `subject`       VARCHAR(190) DEFAULT NULL,
  `body_template` TEXT NOT NULL,                            -- placeholders like {{order_number}}
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notif_templates_code_channel` (`code`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Browser / device Web Push subscriptions (real push). A PushSubscription JSON
-- (endpoint + keys.p256dh + keys.auth) is far longer than notifications.recipient,
-- so it is stored here, one row per browser. `endpoint_hash` = hash('sha256', endpoint)
-- is the unique key (endpoint itself is too long for an index).
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `endpoint_hash`   CHAR(64) NOT NULL,
  `endpoint`        TEXT NOT NULL,
  `p256dh_key`      VARCHAR(255) NOT NULL,
  `auth_key`        VARCHAR(255) NOT NULL,
  `content_encoding` VARCHAR(20) NOT NULL DEFAULT 'aes128gcm',
  `expiration_time` DATETIME DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `device_label`    VARCHAR(100) DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `failure_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- deactivate after repeated 404/410 responses
  `last_used_at`    DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_subscriptions_endpoint` (`endpoint_hash`),
  KEY `idx_push_subscriptions_user` (`user_id`, `is_active`),
  CONSTRAINT `fk_push_subscriptions_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `dedupe_key` (e.g. "order_confirmation:123:email") is UNIQUE so the same event
-- can never be sent twice to the same channel.
-- For channel = 'push' use push_subscription_id (recipient may stay NULL or hold a short label).
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audience`          ENUM('customer','staff') NOT NULL DEFAULT 'customer',
  `user_id`           INT UNSIGNED DEFAULT NULL,
  `recipient`         VARCHAR(190) DEFAULT NULL,            -- email / phone (push: see push_subscription_id)
  `push_subscription_id` INT UNSIGNED DEFAULT NULL,         -- channel = 'push': target browser/device
  `channel`           ENUM('email','sms','push','in_app') NOT NULL,
  `event_type`        VARCHAR(60) NOT NULL,                 -- order_confirmation, low_stock, ...
  `template_id`       INT UNSIGNED DEFAULT NULL,
  `subject`           VARCHAR(190) DEFAULT NULL,
  `message`           TEXT NOT NULL,
  `order_id`          INT UNSIGNED DEFAULT NULL,
  `related_type`      VARCHAR(40) DEFAULT NULL,             -- return_request, refund, stock_alert ...
  `related_id`        INT UNSIGNED DEFAULT NULL,
  `status`            ENUM('pending','sent','delivered','failed','read') NOT NULL DEFAULT 'pending',
  `attempt_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_attempt_at`   DATETIME DEFAULT NULL,
  `error_message`     VARCHAR(255) DEFAULT NULL,
  `dedupe_key`        VARCHAR(190) DEFAULT NULL,
  `sent_at`           DATETIME DEFAULT NULL,
  `read_at`           DATETIME DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_dedupe` (`dedupe_key`),
  KEY `idx_notifications_user` (`user_id`, `status`, `created_at`),
  KEY `idx_notifications_order` (`order_id`),
  KEY `idx_notifications_status` (`status`, `channel`),
  KEY `idx_notifications_event` (`event_type`),
  KEY `idx_notifications_push_sub` (`push_subscription_id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_push_sub` FOREIGN KEY (`push_subscription_id`)
    REFERENCES `push_subscriptions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_template` FOREIGN KEY (`template_id`)
    REFERENCES `notification_templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 21: CUSTOMER SUPPORT                                (feature 41)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_number` VARCHAR(30) NOT NULL,
  `user_id`       INT UNSIGNED DEFAULT NULL,
  `order_id`      INT UNSIGNED DEFAULT NULL,
  `contact_name`  VARCHAR(150) DEFAULT NULL,
  `contact_email` VARCHAR(190) DEFAULT NULL,
  `subject`       VARCHAR(190) NOT NULL,
  `category`      ENUM('order','payment','product','account','delivery','refund','other') NOT NULL DEFAULT 'other',
  `priority`      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`        ENUM('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  `assigned_to`   INT UNSIGNED DEFAULT NULL,
  `resolved_at`   DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_tickets_number` (`ticket_number`),
  KEY `idx_support_tickets_user` (`user_id`),
  KEY `idx_support_tickets_order` (`order_id`),
  KEY `idx_support_tickets_status` (`status`, `priority`),
  KEY `idx_support_tickets_assigned` (`assigned_to`),
  CONSTRAINT `fk_support_tickets_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_support_tickets_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_support_tickets_assigned` FOREIGN KEY (`assigned_to`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT UNSIGNED NOT NULL,
  `sender_id`   INT UNSIGNED DEFAULT NULL,
  `sender_role` ENUM('customer','staff','system') NOT NULL DEFAULT 'customer',
  `message`     TEXT NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,              -- internal staff note, hidden from customer
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_messages_ticket` (`ticket_id`, `created_at`),
  CONSTRAINT `fk_support_messages_ticket` FOREIGN KEY (`ticket_id`)
    REFERENCES `support_tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_support_messages_sender` FOREIGN KEY (`sender_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 22: ANALYTICS EVENTS AND AUDIT LOGS        (features 46, 52, 53)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `analytics_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type`  ENUM('product_view','search','add_to_cart','remove_from_cart','quantity_change','checkout_start','checkout_complete') NOT NULL,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `session_id`  VARCHAR(128) DEFAULT NULL,
  `cart_id`     INT UNSIGNED DEFAULT NULL,
  `product_id`  INT UNSIGNED DEFAULT NULL,
  `variant_id`  INT UNSIGNED DEFAULT NULL,
  `order_id`    INT UNSIGNED DEFAULT NULL,
  `branch_id`   INT UNSIGNED DEFAULT NULL,
  `search_term` VARCHAR(190) DEFAULT NULL,
  `quantity`    INT DEFAULT NULL,
  `metadata`    TEXT DEFAULT NULL,                          -- optional JSON text
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_type_date` (`event_type`, `created_at`),
  KEY `idx_events_session` (`session_id`, `created_at`),
  KEY `idx_events_user` (`user_id`),
  KEY `idx_events_product` (`product_id`, `event_type`),
  KEY `idx_events_branch` (`branch_id`),
  KEY `idx_events_order` (`order_id`),
  CONSTRAINT `fk_events_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_events_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_events_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_events_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,                  -- admin / employee responsible
  `action`      VARCHAR(80) NOT NULL,                       -- product.update, order.status_change, ...
  `entity_type` VARCHAR(60) NOT NULL,                       -- products, orders, refunds, ...
  `entity_id`   INT UNSIGNED DEFAULT NULL,
  `old_values`  TEXT DEFAULT NULL,                          -- JSON text
  `new_values`  TEXT DEFAULT NULL,                          -- JSON text
  `order_id`    INT UNSIGNED DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_date` (`user_id`, `created_at`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_order` (`order_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 23: CIRCULAR REFERENCES (added after `orders` exists)
-- =====================================================================

ALTER TABLE `carts`
  ADD CONSTRAINT `fk_carts_converted_order` FOREIGN KEY (`converted_order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `reward_redemptions`
  ADD CONSTRAINT `fk_reward_redemptions_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- =====================================================================
-- SECTION 24: REPORTING VIEWS                            (features 42-48)
--   Read-only helpers for the admin dashboard and reports. They can be
--   filtered with WHERE (date range, branch, product, ...) from PHP.
--   "Counted" orders = not cancelled and payment not failed. This rule
--   lives in ONE place (v_counted_orders) and every sales / customer /
--   fulfillment view reads from it so the numbers can never disagree.
-- =====================================================================

-- Shared definition of an order that counts toward sales and customer totals.
CREATE OR REPLACE VIEW `v_counted_orders` AS
SELECT o.*
FROM orders o
WHERE o.status <> 'cancelled'
  AND o.payment_status <> 'failed';

-- Effective BASE price (before customization charges) of every variant at every
-- branch that lists the product. Mirrors the price rule in the header comment.
--   price_source: branch_variant | variant | branch_product | promo | base
CREATE OR REPLACE VIEW `v_variant_effective_prices` AS
SELECT
  bp.branch_id,
  p.id  AS product_id,
  v.id  AS variant_id,
  v.sku AS variant_sku,
  COALESCE(bvp.price_override, v.price_override, bp.price_override, pr.promo_now, p.base_price) AS base_price,
  v.additional_charge,
  COALESCE(bvp.price_override, v.price_override, bp.price_override, pr.promo_now, p.base_price)
    + v.additional_charge AS unit_price,
  CASE
    WHEN bvp.price_override IS NOT NULL THEN 'branch_variant'
    WHEN v.price_override   IS NOT NULL THEN 'variant'
    WHEN bp.price_override  IS NOT NULL THEN 'branch_product'
    WHEN pr.promo_now       IS NOT NULL THEN 'promo'
    ELSE 'base'
  END AS price_source,
  (bp.is_available = 1 AND p.is_available = 1 AND p.status = 'active'
   AND p.archived_at IS NULL AND v.is_active = 1) AS is_orderable
FROM branch_products bp
JOIN products p         ON p.id = bp.product_id
JOIN product_variants v ON v.product_id = p.id
LEFT JOIN branch_variant_prices bvp
       ON bvp.branch_id = bp.branch_id AND bvp.variant_id = v.id AND bvp.is_active = 1
LEFT JOIN (
  SELECT id AS product_id,
         CASE WHEN promo_price IS NOT NULL
               AND (promo_starts_at IS NULL OR promo_starts_at <= NOW())
               AND (promo_ends_at   IS NULL OR promo_ends_at   >= NOW())
              THEN promo_price END AS promo_now
  FROM products
) pr ON pr.product_id = p.id;

-- How many more servings of each recipe-driven variant a branch can make with the
-- ingredients it has (base recipe only; extra shots/syrups/milk swaps from
-- customization_option_ingredients are checked in PHP at checkout).
CREATE OR REPLACE VIEW `v_recipe_variant_capacity` AS
SELECT
  b.id AS branch_id,
  pr.product_id,
  pr.variant_id,
  MIN(FLOOR(COALESCE(bis.quantity, 0) / ri.quantity)) AS servings_available
FROM product_recipes pr
JOIN products p      ON p.id = pr.product_id AND p.inventory_mode = 'recipe_ingredients'
JOIN recipe_items ri ON ri.recipe_id = pr.id
JOIN branches b      ON b.is_active = 1 AND b.archived_at IS NULL
LEFT JOIN branch_ingredient_stock bis
       ON bis.branch_id = b.id AND bis.ingredient_id = ri.ingredient_id
WHERE pr.is_active = 1
  AND ri.quantity > 0
GROUP BY b.id, pr.product_id, pr.variant_id;

CREATE OR REPLACE VIEW `v_low_stock_products` AS
SELECT
  bps.branch_id,
  b.name AS branch_name,
  p.id   AS product_id,
  p.name AS product_name,
  v.id   AS variant_id,
  v.name AS variant_name,
  v.sku  AS variant_sku,
  bps.quantity,
  COALESCE(bps.low_stock_threshold, v.low_stock_threshold, p.low_stock_threshold) AS threshold,
  CASE WHEN bps.quantity <= 0 THEN 'out_of_stock' ELSE 'low_stock' END AS stock_status
FROM branch_product_stock bps
JOIN branches b         ON b.id = bps.branch_id
JOIN products p         ON p.id = bps.product_id
JOIN product_variants v ON v.id = bps.variant_id
WHERE p.inventory_mode = 'product_stock'
  AND p.status <> 'archived'
  AND v.is_active = 1
  AND bps.quantity <= COALESCE(bps.low_stock_threshold, v.low_stock_threshold, p.low_stock_threshold);

CREATE OR REPLACE VIEW `v_low_stock_ingredients` AS
SELECT
  bis.branch_id,
  b.name AS branch_name,
  i.id   AS ingredient_id,
  i.name AS ingredient_name,
  i.unit_of_measure,
  bis.quantity,
  COALESCE(bis.low_stock_threshold, i.low_stock_threshold) AS threshold,
  CASE WHEN bis.quantity <= 0 THEN 'out_of_stock' ELSE 'low_stock' END AS stock_status
FROM branch_ingredient_stock bis
JOIN branches b    ON b.id = bis.branch_id
JOIN ingredients i ON i.id = bis.ingredient_id
WHERE i.is_active = 1
  AND bis.quantity <= COALESCE(bis.low_stock_threshold, i.low_stock_threshold);

CREATE OR REPLACE VIEW `v_daily_sales` AS
SELECT
  DATE(o.placed_at)   AS sales_date,
  o.branch_id,
  COUNT(*)            AS total_orders,
  SUM(o.subtotal)     AS gross_sales,
  SUM(o.discount_total) AS discount_total,
  SUM(o.delivery_fee) AS delivery_fee_total,
  SUM(o.tax_total)    AS tax_total,
  SUM(o.grand_total)  AS revenue,
  SUM(o.refunded_total) AS refund_total
FROM v_counted_orders o
GROUP BY DATE(o.placed_at), o.branch_id;

CREATE OR REPLACE VIEW `v_product_sales` AS
SELECT
  oi.product_id,
  oi.product_name,
  p.category_id,
  o.branch_id,
  SUM(oi.quantity)           AS quantity_sold,
  SUM(oi.line_total)         AS revenue,
  COUNT(DISTINCT oi.order_id) AS order_count
FROM order_items oi
JOIN v_counted_orders o ON o.id = oi.order_id
LEFT JOIN products p    ON p.id = oi.product_id
GROUP BY oi.product_id, oi.product_name, p.category_id, o.branch_id;

CREATE OR REPLACE VIEW `v_customer_order_summary` AS
SELECT
  u.id AS user_id,
  CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
  u.email,
  u.status,
  u.created_at AS registered_at,
  COUNT(o.id)                       AS order_count,
  COALESCE(SUM(o.grand_total), 0)   AS total_spent,
  MAX(o.placed_at)                  AS last_order_at,
  COALESCE(SUM(o.refunded_total), 0) AS total_refunded
FROM users u
LEFT JOIN v_counted_orders o ON o.user_id = u.id
WHERE u.role = 'customer'
GROUP BY u.id, u.first_name, u.last_name, u.email, u.status, u.created_at;

CREATE OR REPLACE VIEW `v_order_fulfillment_times` AS
SELECT
  o.id AS order_id,
  o.order_number,
  o.branch_id,
  o.order_type,
  o.placed_at,
  TIMESTAMPDIFF(MINUTE, o.preparation_started_at, o.ready_at) AS preparation_minutes,
  TIMESTAMPDIFF(MINUTE, o.ready_at, o.picked_up_at)           AS pickup_wait_minutes,
  TIMESTAMPDIFF(MINUTE, o.shipped_at, o.delivered_at)         AS delivery_minutes
FROM v_counted_orders o;

CREATE OR REPLACE VIEW `v_cart_conversion_daily` AS
SELECT
  DATE(e.created_at) AS event_date,
  SUM(e.event_type = 'product_view')      AS product_views,
  SUM(e.event_type = 'search')            AS searches,
  SUM(e.event_type = 'add_to_cart')       AS add_to_cart_events,
  SUM(e.event_type = 'remove_from_cart')  AS cart_removals,
  SUM(e.event_type = 'quantity_change')   AS quantity_changes,
  SUM(e.event_type = 'checkout_start')    AS checkout_starts,
  SUM(e.event_type = 'checkout_complete') AS checkout_completions
FROM analytics_events e
GROUP BY DATE(e.created_at);

-- Cart timing settings from system_settings (one row; falls back to 60 min / 72 h).
CREATE OR REPLACE VIEW `v_cart_settings` AS
SELECT
  COALESCE(MAX(CASE WHEN setting_key = 'cart_abandoned_after_minutes' THEN CAST(setting_value AS UNSIGNED) END), 60) AS abandoned_after_minutes,
  COALESCE(MAX(CASE WHEN setting_key = 'cart_expiry_hours'            THEN CAST(setting_value AS UNSIGNED) END), 72) AS expiry_hours
FROM system_settings
WHERE setting_key IN ('cart_abandoned_after_minutes', 'cart_expiry_hours');

-- Abandoned carts. WORKS EVEN IF THE PHP SWEEPER HAS NOT RUN YET: a cart counts
-- as abandoned when it is already flagged 'abandoned' OR when it is still
-- 'active' but idle longer than cart_abandoned_after_minutes. Empty carts are
-- ignored. `is_swept` = 1 when the sweeper has already stored abandoned_at.
-- The sweeper itself (maintenance job 'cart_abandon_sweep', SECTION 24B) only
-- makes the stored status / abandoned_at permanent for analytics and e-mails.
CREATE OR REPLACE VIEW `v_abandoned_carts` AS
SELECT
  c.id AS cart_id,
  c.user_id,
  c.session_id,
  c.branch_id,
  c.last_activity_at,
  COALESCE(c.abandoned_at, c.last_activity_at + INTERVAL s.abandoned_after_minutes MINUTE) AS abandoned_at,
  (c.status = 'abandoned') AS is_swept,
  COUNT(ci.id) AS item_lines,
  COALESCE(SUM((ci.unit_price + ci.customization_total) * ci.quantity), 0) AS cart_value
FROM carts c
CROSS JOIN v_cart_settings s
JOIN cart_items ci ON ci.cart_id = c.id
WHERE c.status = 'abandoned'
   OR (c.status = 'active'
       AND c.last_activity_at < (NOW() - INTERVAL s.abandoned_after_minutes MINUTE))
GROUP BY c.id, c.user_id, c.session_id, c.branch_id, c.last_activity_at, c.abandoned_at,
         c.status, s.abandoned_after_minutes;

-- Work list for the sweeper / monitoring: active carts overdue to be marked
-- 'abandoned'. (Run the UPDATE directly on `carts` - see SECTION 24B.)
CREATE OR REPLACE VIEW `v_carts_due_for_abandonment` AS
SELECT
  c.id AS cart_id,
  c.user_id,
  c.session_id,
  c.last_activity_at,
  TIMESTAMPDIFF(MINUTE, c.last_activity_at, NOW()) AS idle_minutes
FROM carts c
CROSS JOIN v_cart_settings s
WHERE c.status = 'active'
  AND c.last_activity_at < (NOW() - INTERVAL s.abandoned_after_minutes MINUTE)
  AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id);

-- =====================================================================
-- SECTION 24B: MAINTENANCE JOBS (XAMPP has no cron and MySQL events are off)
--   Periodic work is run from PHP. maintenance_jobs records when each job last
--   ran so it can be triggered "lazily" from normal requests (e.g. at the end
--   of Homepage/index.php or the admin dashboard) or from Windows Task
--   Scheduler (C:\xampp\php\php.exe on a small CLI script of your own).
--
--   ATOMIC CLAIM (only one request runs a due job; rowCount() = 1 means "yours"):
--     UPDATE maintenance_jobs
--        SET last_started_at = NOW(), last_status = 'running', run_count = run_count + 1
--      WHERE job_key = ? AND is_enabled = 1
--        AND (last_started_at IS NULL OR last_started_at <= NOW() - INTERVAL interval_minutes MINUTE)
--        AND (last_status <> 'running' OR last_started_at <= NOW() - INTERVAL 10 MINUTE);
--   FINISH:
--     UPDATE maintenance_jobs SET last_status = 'ok' (or 'failed'), last_finished_at = NOW(),
--            last_message = ? WHERE job_key = ?;
--
--   JOB SQL
--   cart_abandon_sweep  ($mins = (int) cart_abandoned_after_minutes, default 60):
--     UPDATE carts c SET c.status = 'abandoned', c.abandoned_at = NOW()
--      WHERE c.status = 'active'
--        AND c.last_activity_at < (NOW() - INTERVAL {$mins} MINUTE)
--        AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id);
--   cart_expire_sweep:
--     UPDATE carts SET status = 'expired'
--      WHERE status IN ('active','abandoned') AND expires_at IS NOT NULL AND expires_at < NOW();
--   Continue an abandoned cart (customer returns; $hours = cart_expiry_hours):
--     UPDATE carts SET status = 'active', abandoned_at = NULL, last_activity_at = NOW(),
--            expires_at = NOW() + INTERVAL {$hours} HOUR
--      WHERE id = ? AND status = 'abandoned';
--   mail_retry            : call mail_retry_failed()            (Config/mail.php)
--   gift_card_delivery    : SELECT * FROM gift_cards WHERE status = 'active' AND sent_at IS NULL
--                             AND recipient_email IS NOT NULL
--                             AND (delivery_date IS NULL OR delivery_date <= CURDATE());
--                           e-mail each (event 'gift_card_delivery'), then SET sent_at = NOW().
--   loyalty_points_expiry : find 'earn' rows in loyalty_transactions with expires_at < NOW() that
--                           are not yet expired; write an 'expire' row + update loyalty_accounts.
--   reward_redemption_expiry:
--     UPDATE reward_redemptions SET status = 'expired'
--      WHERE status = 'available' AND expires_at IS NOT NULL AND expires_at < NOW();
-- =====================================================================

CREATE TABLE IF NOT EXISTS `maintenance_jobs` (
  `job_key`          VARCHAR(60)  NOT NULL,
  `description`      VARCHAR(255) DEFAULT NULL,
  `interval_minutes` INT UNSIGNED NOT NULL DEFAULT 15,
  `is_enabled`       TINYINT(1)   NOT NULL DEFAULT 1,
  `last_started_at`  DATETIME DEFAULT NULL,
  `last_finished_at` DATETIME DEFAULT NULL,
  `last_status`      ENUM('never','running','ok','failed') NOT NULL DEFAULT 'never',
  `last_message`     VARCHAR(255) DEFAULT NULL,
  `run_count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SECTION 25: SEED DATA (reference data + sample menu + demo accounts)
--   Safe to edit or delete. Import this file into a FRESH database.
-- =====================================================================

-- ---- Branch (sample - edit in Admin > Branches) ----------------------
INSERT INTO `branches` (`id`,`code`,`name`,`address`,`city`,`province`,`postal_code`,`country`,`phone`,`email`,`prep_time_minutes`) VALUES
(1,'MAIN','Forest & Cream - Main Branch','123 Sample Street','Dasmariñas','Cavite','4114','Philippines','+63 46 000 0000','main@coffeeshop.local',15);

INSERT INTO `branch_operating_hours` (`branch_id`,`day_of_week`,`open_time`,`close_time`,`is_closed`) VALUES
(1,0,'08:00:00','21:00:00',0),
(1,1,'07:00:00','21:00:00',0),
(1,2,'07:00:00','21:00:00',0),
(1,3,'07:00:00','21:00:00',0),
(1,4,'07:00:00','21:00:00',0),
(1,5,'07:00:00','22:00:00',0),
(1,6,'08:00:00','22:00:00',0);

INSERT INTO `branch_special_hours` (`branch_id`,`special_date`,`label`,`open_time`,`close_time`,`is_closed`) VALUES
(1,'2026-12-25','Christmas Day',NULL,NULL,1),
(1,'2026-12-31','New Year''s Eve','08:00:00','18:00:00',0);

-- ---- Demo accounts (password for all four: Admin@12345) ---------------
INSERT INTO `users` (`id`,`role`,`first_name`,`last_name`,`email`,`phone`,`password_hash`,`status`,`email_verified_at`) VALUES
(1,'admin','System','Administrator','admin@coffeeshop.local','09170000001','$2y$12$T5zr2V2hz8ca9wE5hs.gr.IGW4Jk0IVjRDy5BujsYqR1xX5FbtFMG','active',NOW()),
(2,'staff','Sample','Staff','staff@coffeeshop.local','09170000002','$2y$12$T5zr2V2hz8ca9wE5hs.gr.IGW4Jk0IVjRDy5BujsYqR1xX5FbtFMG','active',NOW()),
(3,'fulfillment','Sample','Fulfillment','fulfillment@coffeeshop.local','09170000003','$2y$12$T5zr2V2hz8ca9wE5hs.gr.IGW4Jk0IVjRDy5BujsYqR1xX5FbtFMG','active',NOW()),
(4,'customer','Sample','Customer','customer@coffeeshop.local','09170000004','$2y$12$T5zr2V2hz8ca9wE5hs.gr.IGW4Jk0IVjRDy5BujsYqR1xX5FbtFMG','active',NOW());

INSERT INTO `employee_profiles` (`user_id`,`employee_code`,`branch_id`,`job_title`,`responsibilities`,`hire_date`) VALUES
(1,'EMP-0001',1,'System Administrator','Full system management',CURDATE()),
(2,'EMP-0002',1,'Barista / Customer Service','Order processing, customer support, product and stock updates',CURDATE()),
(3,'EMP-0003',1,'Order Fulfillment','Order preparation, pickup and delivery completion',CURDATE());

-- ---- Permissions and role assignments --------------------------------
INSERT INTO `permissions` (`id`,`code`,`name`,`module`) VALUES
(1,'dashboard.view','View admin dashboard','dashboard'),
(2,'products.view','View products','products'),
(3,'products.create','Create products','products'),
(4,'products.edit','Edit products','products'),
(5,'products.archive','Archive / restore products','products'),
(6,'categories.manage','Manage categories','products'),
(7,'inventory.view','View inventory','inventory'),
(8,'inventory.adjust','Adjust inventory','inventory'),
(9,'ingredients.manage','Manage ingredients and recipes','inventory'),
(10,'suppliers.manage','Manage suppliers','purchasing'),
(11,'purchasing.manage','Manage purchase orders','purchasing'),
(12,'transfers.manage','Manage branch stock transfers','inventory'),
(13,'orders.view','View orders','orders'),
(14,'orders.update_status','Update order status','orders'),
(15,'orders.assign','Assign orders to branches / staff','orders'),
(16,'orders.fulfill','Prepare, pickup and delivery updates','orders'),
(17,'orders.cancel','Cancel orders','orders'),
(18,'returns.view','View return and cancellation requests','returns'),
(19,'returns.manage','Approve / reject returns and cancellations','returns'),
(20,'refunds.view','View refunds','refunds'),
(21,'refunds.process','Process refunds','refunds'),
(22,'customers.view','View customers','customers'),
(23,'customers.manage','Activate / deactivate customers','customers'),
(24,'employees.view','View employees','employees'),
(25,'employees.manage','Manage employees and roles','employees'),
(26,'branches.manage','Manage branches','branches'),
(27,'promotions.manage','Manage promotions and coupons','marketing'),
(28,'loyalty.manage','Manage loyalty program and rewards','marketing'),
(29,'giftcards.manage','Manage gift cards','marketing'),
(30,'wallets.manage','Manage wallets and balance adjustments','marketing'),
(31,'shipping.manage','Manage shipping methods and shipments','orders'),
(32,'notifications.manage','Manage notifications and templates','system'),
(33,'support.view','View support tickets','support'),
(34,'support.respond','Respond to support tickets','support'),
(35,'reports.sales','View sales reports','reports'),
(36,'reports.products','View product reports','reports'),
(37,'reports.inventory','View inventory reports','reports'),
(38,'reports.customers','View customer analytics','reports'),
(39,'reports.analytics','View conversion and operational analytics','reports'),
(40,'settings.manage','Manage system settings','system'),
(41,'audit.view','View audit logs','system');

-- Admin gets everything.
INSERT INTO `role_permissions` (`role`,`permission_id`)
SELECT 'admin', `id` FROM `permissions`;

-- Staff: customer service and café operations (no finance, employees or settings).
INSERT INTO `role_permissions` (`role`,`permission_id`)
SELECT 'staff', `id` FROM `permissions`
WHERE `code` IN ('dashboard.view','products.view','products.edit','inventory.view','inventory.adjust',
                 'ingredients.manage','orders.view','orders.update_status','orders.assign','orders.fulfill',
                 'orders.cancel','returns.view','returns.manage','refunds.view','customers.view',
                 'support.view','support.respond','reports.products','reports.inventory');

-- Fulfillment-only: assigned orders and status updates, nothing financial.
INSERT INTO `role_permissions` (`role`,`permission_id`)
SELECT 'fulfillment', `id` FROM `permissions`
WHERE `code` IN ('orders.view','orders.fulfill');

-- ---- System settings -------------------------------------------------
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`description`) VALUES
('store_name','Forest & Cream Coffee','string','Store display name'),
('support_email','support@coffeeshop.local','string','Customer support email'),
('currency','PHP','string','Currency code'),
('tax_rate','12.00','decimal','Tax percentage (VAT)'),
('prices_include_tax','0','bool','1 = listed prices already include tax'),
('guest_checkout_enabled','1','bool','Allow checkout without an account'),
('cart_expiry_hours','72','int','Hours before an inactive cart expires'),
('cart_abandoned_after_minutes','60','int','Minutes of inactivity before a cart counts as abandoned'),
('max_cart_item_quantity','20','int','Maximum quantity per cart line'),
('max_login_attempts','5','int','Failed logins before lockout'),
('login_lockout_minutes','15','int','Lockout duration after too many failed logins'),
('password_min_length','8','int','Minimum password length'),
('password_reset_expiry_minutes','60','int','Password reset token lifetime'),
('email_verification_expiry_hours','24','int','Email verification token lifetime'),
('loyalty_enabled','1','bool','Enable the loyalty program'),
('default_low_stock_threshold','10','int','Default low-stock threshold'),
('max_image_upload_mb','2','int','Maximum product image size in MB'),
('allowed_image_types','jpg,jpeg,png,webp','string','Allowed product image extensions'),
('gift_card_expiry_days','365','int','Days a purchased gift card stays valid (0 = never expires)'),
('maintenance_lazy_run','1','bool','Run due maintenance_jobs from normal page requests (XAMPP has no cron)');

-- ---- Maintenance jobs (see SECTION 24B) ------------------------------------
INSERT INTO `maintenance_jobs` (`job_key`,`description`,`interval_minutes`) VALUES
('cart_abandon_sweep','Mark inactive carts as abandoned and store abandoned_at',5),
('cart_expire_sweep','Expire carts past their expires_at',60),
('mail_retry','Retry failed e-mail notifications (mail_retry_failed)',10),
('gift_card_delivery','E-mail active gift cards whose delivery_date has arrived',15),
('loyalty_points_expiry','Expire loyalty points past their expires_at',1440),
('reward_redemption_expiry','Expire unused reward vouchers',1440);

-- ---- Loyalty (tiers, earning rules, rewards) -------------------------
INSERT INTO `loyalty_tiers` (`id`,`name`,`min_lifetime_points`,`points_multiplier`,`benefits`) VALUES
(1,'Sprout',0,1.00,'Earn 1x points on every purchase'),
(2,'Evergreen',1000,1.25,'Earn 1.25x points and birthday reward'),
(3,'Forest',3000,1.50,'Earn 1.5x points, birthday reward and priority pickup');

INSERT INTO `loyalty_rules` (`id`,`name`,`rule_type`,`points_awarded`,`spend_amount`,`points_expire_days`) VALUES
(1,'Base points: 1 point per PHP 10 spent','per_amount_spent',1,10.00,365),
(2,'Welcome bonus','signup_bonus',50,NULL,365);

INSERT INTO `rewards` (`id`,`name`,`description`,`points_required`,`reward_type`,`product_id`,`discount_value`,`validity_days`) VALUES
(1,'PHP 50 Off','Get PHP 50 off your order',100,'fixed_discount',NULL,50.00,30),
(2,'Free Butter Croissant','One free butter croissant with any order',150,'free_product',7,NULL,30),
(3,'Free Cafe Latte','One free Cafe Latte (Medium)',250,'free_product',1,NULL,30),
(4,'10% Off Your Order','10% off your whole order',300,'percentage_discount',NULL,10.00,30);

INSERT INTO `customer_preferences` (`user_id`,`preferred_branch_id`) VALUES (4,1);

INSERT INTO `loyalty_accounts` (`id`,`user_id`,`tier_id`,`points_balance`,`lifetime_points_earned`) VALUES (1,4,1,50,50);

INSERT INTO `loyalty_transactions` (`loyalty_account_id`,`transaction_type`,`points`,`balance_after`,`rule_id`,`description`) VALUES
(1,'bonus',50,50,2,'Welcome bonus');

INSERT INTO `wallets` (`user_id`,`balance`) VALUES (4,0.00);

-- ---- Promotions and coupons ------------------------------------------
INSERT INTO `promotions` (`id`,`name`,`description`) VALUES
(1,'Welcome Offer','10% off for new customers'),
(2,'Everyday Savings','Fixed discount on bigger orders');

INSERT INTO `coupons` (`id`,`promotion_id`,`code`,`description`,`discount_type`,`discount_value`,`max_discount_amount`,`min_purchase_amount`,`applies_to`,`per_customer_limit`,`usage_limit`,`expires_at`) VALUES
(1,1,'WELCOME10','10% off your order (max PHP 100)','percentage',10.00,100.00,200.00,'order',1,NULL,NULL),
(2,2,'SAVE50','PHP 50 off orders of PHP 300 or more','fixed',50.00,NULL,300.00,'order',NULL,500,'2027-12-31 23:59:59');

-- ---- Shipping / order-type methods -----------------------------------
INSERT INTO `shipping_methods` (`id`,`code`,`name`,`description`,`order_type`,`base_fee`,`free_delivery_threshold`,`estimated_min_minutes`,`estimated_max_minutes`,`display_order`) VALUES
(1,'store_pickup','Store Pickup','Pick up at your selected branch','pickup',0.00,NULL,10,25,1),
(2,'takeaway','Takeaway','Order and take away at the counter','takeaway',0.00,NULL,5,15,2),
(3,'dine_in','Dine-in','Enjoy your order at the cafe','dine_in',0.00,NULL,5,15,3),
(4,'standard_delivery','Standard Delivery','Delivered to your address','delivery',49.00,500.00,30,60,4),
(5,'express_delivery','Express Delivery','Priority delivery','delivery',99.00,NULL,15,30,5);

-- ---- Return / cancellation reasons -----------------------------------
INSERT INTO `return_reasons` (`request_type`,`label`,`display_order`) VALUES
('cancellation','Ordered by mistake',1),
('cancellation','Changed my mind',2),
('cancellation','Order is taking too long',3),
('cancellation','Wrong items ordered',4),
('cancellation','Found a better price',5),
('return','Wrong item received',1),
('return','Item damaged or spilled',2),
('return','Quality not as expected',3),
('return','Missing item',4),
('both','Other',99);

-- ---- Categories ------------------------------------------------------
INSERT INTO `categories` (`id`,`parent_id`,`name`,`slug`,`description`,`display_order`) VALUES
(1,NULL,'Beverages','beverages','All drinks',1),
(2,1,'Hot Drinks','hot-drinks','Freshly brewed hot coffee and espresso drinks',2),
(3,1,'Iced & Cold Drinks','iced-cold-drinks','Iced coffee and cold beverages',3),
(4,NULL,'Coffee Beans','coffee-beans','Whole bean and ground coffee',4),
(5,NULL,'Pastries','pastries','Freshly baked pastries',5),
(6,NULL,'Merchandise','merchandise','Tumblers, mugs and more',6),
(7,NULL,'Equipment','equipment','Brewing equipment',7),
(8,NULL,'Gift Cards','gift-cards','Digital gift cards',8);

-- ---- Allergens -------------------------------------------------------
INSERT INTO `allergens` (`id`,`name`,`slug`) VALUES
(1,'Milk','milk'),(2,'Soy','soy'),(3,'Tree Nuts','tree-nuts'),
(4,'Gluten','gluten'),(5,'Eggs','eggs'),(6,'Peanuts','peanuts');

-- ---- Sample products -------------------------------------------------
-- inventory_mode: drinks are made to order from ingredients (recipe_ingredients),
-- packaged goods are counted per branch (product_stock), gift cards are unlimited (none).
INSERT INTO `products` (`id`,`category_id`,`sku`,`name`,`slug`,`short_description`,`description`,`product_type`,`roast_level`,`base_price`,`is_featured`,`is_recommended`,`is_customizable`,`inventory_mode`,`low_stock_threshold`) VALUES
(1,2,'DRK-LATTE','Café Latte','cafe-latte','Espresso with silky steamed milk.','Rich espresso balanced with smooth steamed milk and a light layer of foam.','hot_drink',NULL,140.00,1,1,1,'recipe_ingredients',10),
(2,2,'DRK-CAPPUCCINO','Cappuccino','cappuccino','Espresso, steamed milk and thick foam.','Equal parts espresso, steamed milk and airy foam for a bold, creamy cup.','hot_drink',NULL,140.00,0,1,1,'recipe_ingredients',10),
(3,2,'DRK-AMERICANO','Americano','americano','Espresso topped with hot water.','Smooth, full-bodied espresso diluted with hot water.','hot_drink',NULL,110.00,0,0,1,'recipe_ingredients',10),
(4,3,'DRK-ICED-CARAMEL-MACCH','Iced Caramel Macchiato','iced-caramel-macchiato','Iced vanilla milk, espresso and caramel drizzle.','Layers of vanilla-sweetened milk, espresso and caramel over ice.','iced_drink',NULL,165.00,1,1,1,'recipe_ingredients',10),
(5,3,'DRK-ICED-SPANISH','Iced Spanish Latte','iced-spanish-latte','Sweet, creamy iced latte with condensed milk.','Espresso and milk sweetened with condensed milk, served over ice.','iced_drink',NULL,160.00,1,0,1,'recipe_ingredients',10),
(6,4,'BEAN-HOUSE-BLEND','Signature House Blend','signature-house-blend','Balanced medium roast, whole bean.','Notes of cocoa, toasted nuts and brown sugar. Whole bean.','coffee_beans','medium',420.00,1,1,0,'product_stock',10),
(7,5,'PST-CROISSANT','Butter Croissant','butter-croissant','Flaky, golden butter croissant.','Baked fresh daily with real butter.','pastry',NULL,95.00,0,1,0,'product_stock',10),
(8,5,'PST-MUFFIN','Blueberry Muffin','blueberry-muffin','Soft muffin bursting with blueberries.','Moist vanilla muffin loaded with blueberries.','pastry',NULL,85.00,0,0,0,'product_stock',10),
(9,6,'MRC-TUMBLER','Forest & Cream Tumbler','forest-cream-tumbler','Insulated 16oz tumbler.','Double-wall stainless steel tumbler in forest green.','merchandise',NULL,650.00,0,1,0,'product_stock',5),
(10,7,'EQP-GRINDER','Manual Coffee Grinder','manual-coffee-grinder','Adjustable ceramic burr hand grinder.','Compact hand grinder with adjustable grind size.','equipment',NULL,1800.00,0,0,0,'product_stock',3),
(11,8,'GFT-CARD','Digital Gift Card','digital-gift-card','Send a coffee treat to anyone.','Delivered by email with a unique redemption code.','gift_card',NULL,500.00,0,1,0,'none',0);

-- Recipe-driven drinks (variants 1-15) keep stock_quantity = 0: their availability comes from
-- branch_ingredient_stock via product_recipes. Packaged goods keep a real quantity.
INSERT INTO `product_variants` (`id`,`product_id`,`sku`,`name`,`size_label`,`volume_ml`,`price_override`,`additional_charge`,`stock_quantity`,`is_default`,`display_order`) VALUES
(1,1,'DRK-LATTE-S','Small','S',240,NULL,0.00,0,0,1),
(2,1,'DRK-LATTE-M','Medium','M',355,NULL,20.00,0,1,2),
(3,1,'DRK-LATTE-L','Large','L',473,NULL,40.00,0,0,3),
(4,2,'DRK-CAPPUCCINO-S','Small','S',240,NULL,0.00,0,0,1),
(5,2,'DRK-CAPPUCCINO-M','Medium','M',355,NULL,20.00,0,1,2),
(6,2,'DRK-CAPPUCCINO-L','Large','L',473,NULL,40.00,0,0,3),
(7,3,'DRK-AMERICANO-S','Small','S',240,NULL,0.00,0,0,1),
(8,3,'DRK-AMERICANO-M','Medium','M',355,NULL,20.00,0,1,2),
(9,3,'DRK-AMERICANO-L','Large','L',473,NULL,40.00,0,0,3),
(10,4,'DRK-ICED-CARAMEL-MACCH-S','Small','S',355,NULL,0.00,0,0,1),
(11,4,'DRK-ICED-CARAMEL-MACCH-M','Medium','M',473,NULL,20.00,0,1,2),
(12,4,'DRK-ICED-CARAMEL-MACCH-L','Large','L',591,NULL,40.00,0,0,3),
(13,5,'DRK-ICED-SPANISH-S','Small','S',355,NULL,0.00,0,0,1),
(14,5,'DRK-ICED-SPANISH-M','Medium','M',473,NULL,20.00,0,1,2),
(15,5,'DRK-ICED-SPANISH-L','Large','L',591,NULL,40.00,0,0,3),
(16,6,'BEAN-HOUSE-BLEND-250G','250g','250g',NULL,NULL,0.00,60,1,1),
(17,6,'BEAN-HOUSE-BLEND-1KG','1kg','1kg',NULL,1450.00,0.00,30,0,2),
(18,7,'PST-CROISSANT-STD','Standard','Standard',NULL,NULL,0.00,50,1,1),
(19,8,'PST-MUFFIN-STD','Standard','Standard',NULL,NULL,0.00,50,1,1),
(20,9,'MRC-TUMBLER-STD','Standard','Standard',NULL,NULL,0.00,25,1,1),
(21,10,'EQP-GRINDER-STD','Standard','Standard',NULL,NULL,0.00,10,1,1),
(22,11,'GFT-CARD-250','PHP 250','250',NULL,250.00,0.00,0,0,1),
(23,11,'GFT-CARD-500','PHP 500','500',NULL,500.00,0.00,0,1,2),
(24,11,'GFT-CARD-1000','PHP 1,000','1000',NULL,1000.00,0.00,0,0,3);

INSERT INTO `product_nutrition` (`product_id`,`variant_id`,`serving_size`,`calories`,`total_fat_g`,`saturated_fat_g`,`carbs_g`,`sugar_g`,`protein_g`,`sodium_mg`,`caffeine_mg`) VALUES
(1,1,'240 ml',120,4.50,2.80,11.00,10.00,7.00,95.00,75.00),
(1,2,'355 ml',190,7.00,4.50,18.00,16.00,12.00,150.00,150.00),
(1,3,'473 ml',250,9.00,5.50,23.00,21.00,15.00,190.00,225.00),
(7,18,'1 piece',260,14.00,8.00,29.00,5.00,5.00,310.00,NULL),
(8,19,'1 piece',340,15.00,3.00,47.00,28.00,5.00,330.00,NULL);

INSERT INTO `product_allergens` (`product_id`,`allergen_id`,`presence`) VALUES
(1,1,'contains'),(2,1,'contains'),(4,1,'contains'),(5,1,'contains'),
(7,1,'contains'),(7,4,'contains'),(7,5,'may_contain'),
(8,1,'contains'),(8,4,'contains'),(8,5,'contains'),(8,3,'may_contain');

-- ---- Customization groups and options ---------------------------------
INSERT INTO `customization_groups` (`id`,`code`,`name`,`group_type`,`selection_type`,`is_required`,`min_select`,`max_select`,`display_order`) VALUES
(1,'milk','Milk','milk','single',1,1,1,1),
(2,'sweetness','Sweetness Level','sweetness','single',1,1,1,2),
(3,'temperature','Temperature','temperature','single',1,1,1,3),
(4,'espresso','Espresso Shots','espresso','multiple',0,0,1,4),
(5,'syrup','Syrups & Flavors','syrup','multiple',0,0,3,5),
(6,'topping','Toppings & Add-ons','topping','multiple',0,0,3,6);

INSERT INTO `customization_options` (`id`,`group_id`,`name`,`option_value`,`additional_price`,`max_quantity`,`is_default`,`display_order`) VALUES
(1,1,'Whole Milk','whole',0.00,1,1,1),
(2,1,'Oat Milk','oat',40.00,1,0,2),
(3,1,'Soy Milk','soy',30.00,1,0,3),
(4,1,'Almond Milk','almond',40.00,1,0,4),
(5,2,'0% (No Sugar)','0',0.00,1,0,1),
(6,2,'25%','25',0.00,1,0,2),
(7,2,'50%','50',0.00,1,0,3),
(8,2,'75%','75',0.00,1,0,4),
(9,2,'100%','100',0.00,1,1,5),
(10,3,'Hot','hot',0.00,1,1,1),
(11,3,'Iced','iced',0.00,1,0,2),
(12,4,'Extra Espresso Shot','shot',50.00,4,0,1),
(13,5,'Vanilla Syrup','vanilla',30.00,1,0,1),
(14,5,'Caramel Syrup','caramel',30.00,1,0,2),
(15,5,'Hazelnut Syrup','hazelnut',30.00,1,0,3),
(16,6,'Whipped Cream','whipped_cream',25.00,1,0,1),
(17,6,'Chocolate Drizzle','chocolate',25.00,1,0,2),
(18,6,'Caramel Drizzle','caramel_drizzle',25.00,1,0,3);

-- Latte, Cappuccino, Iced Macchiato, Iced Spanish Latte: milk, sweetness, shots, syrups, toppings
INSERT INTO `product_customization_options` (`product_id`,`option_id`,`is_default`,`display_order`)
SELECT p.`id`, o.`id`, o.`is_default`, o.`display_order`
FROM `products` p
JOIN `customization_options` o ON o.`group_id` IN (1,2,4,5,6)
WHERE p.`id` IN (1,2,4,5);

-- Americano: sweetness, shots, syrups (no milk / toppings)
INSERT INTO `product_customization_options` (`product_id`,`option_id`,`is_default`,`display_order`)
SELECT p.`id`, o.`id`, o.`is_default`, o.`display_order`
FROM `products` p
JOIN `customization_options` o ON o.`group_id` IN (2,4,5)
WHERE p.`id` = 3;

-- Hot / Iced choice only for Latte and Americano
INSERT INTO `product_customization_options` (`product_id`,`option_id`,`is_default`,`display_order`)
SELECT p.`id`, o.`id`, o.`is_default`, o.`display_order`
FROM `products` p
JOIN `customization_options` o ON o.`group_id` = 3
WHERE p.`id` IN (1,3);

-- ---- Branch menu and stock (every product at the main branch) --------
-- Per-branch product stock rows are created ONLY for inventory_mode = 'product_stock'.
INSERT INTO `branch_products` (`branch_id`,`product_id`,`is_available`,`display_order`)
SELECT 1, `id`, 1, `id` FROM `products`;

INSERT INTO `branch_product_stock` (`branch_id`,`product_id`,`variant_id`,`quantity`)
SELECT 1, v.`product_id`, v.`id`, v.`stock_quantity`
FROM `product_variants` v
JOIN `products` p ON p.`id` = v.`product_id`
WHERE p.`inventory_mode` = 'product_stock';

INSERT INTO `inventory_transactions` (`branch_id`,`item_type`,`product_id`,`variant_id`,`transaction_type`,`quantity_change`,`previous_stock`,`new_stock`,`notes`)
SELECT 1, 'product', v.`product_id`, v.`id`, 'initial_stock', v.`stock_quantity`, 0, v.`stock_quantity`, 'Seed data'
FROM `product_variants` v
JOIN `products` p ON p.`id` = v.`product_id`
WHERE p.`inventory_mode` = 'product_stock';

-- ---- Ingredients, branch ingredient stock, sample recipes -------------
INSERT INTO `ingredients` (`id`,`sku`,`name`,`category`,`unit_of_measure`,`cost_per_unit`,`low_stock_threshold`) VALUES
(1,'ING-ESPRESSO-BEANS','Espresso Beans','coffee','g',0.9000,2000.000),
(2,'ING-MILK-WHOLE','Whole Milk','dairy','ml',0.0800,5000.000),
(3,'ING-MILK-OAT','Oat Milk','alt_milk','ml',0.1800,3000.000),
(4,'ING-MILK-SOY','Soy Milk','alt_milk','ml',0.1200,3000.000),
(5,'ING-MILK-ALMOND','Almond Milk','alt_milk','ml',0.2000,3000.000),
(6,'ING-SYRUP-VANILLA','Vanilla Syrup','syrup','ml',0.3500,1000.000),
(7,'ING-SYRUP-CARAMEL','Caramel Syrup','syrup','ml',0.3500,1000.000),
(8,'ING-SYRUP-HAZELNUT','Hazelnut Syrup','syrup','ml',0.3500,1000.000),
(9,'ING-WHIPPED-CREAM','Whipped Cream','topping','g',0.3000,1000.000),
(10,'ING-CHOC-SAUCE','Chocolate Sauce','topping','ml',0.3000,1000.000),
(11,'ING-CARAMEL-SAUCE','Caramel Sauce','topping','ml',0.3000,1000.000),
(12,'ING-SUGAR','Sugar','sweetener','g',0.0600,3000.000),
(13,'PKG-CUP-S','Cup 8oz','packaging','pcs',3.5000,100.000),
(14,'PKG-CUP-M','Cup 12oz','packaging','pcs',4.5000,100.000),
(15,'PKG-CUP-L','Cup 16oz','packaging','pcs',5.5000,100.000),
(16,'PKG-LID','Cup Lid','packaging','pcs',1.5000,100.000),
(17,'PKG-STRAW','Straw','packaging','pcs',0.8000,100.000),
(18,'ING-ICE','Ice','other','g',0.0100,2000.000),
(19,'ING-CONDENSED-MILK','Condensed Milk','dairy','ml',0.1500,1000.000),
(20,'PKG-CUP-XL','Cup 20oz','packaging','pcs',6.5000,100.000);

INSERT INTO `branch_ingredient_stock` (`branch_id`,`ingredient_id`,`quantity`)
SELECT 1, `id`, `low_stock_threshold` * 5 FROM `ingredients`;

-- Recipes for the Cafe Latte variants (the default milk is replaced by the
-- customer's chosen milk in PHP; extra shots/syrups/toppings come from
-- customization_option_ingredients).
INSERT INTO `product_recipes` (`id`,`product_id`,`variant_id`,`version`,`notes`) VALUES
(1,1,1,1,'Cafe Latte - Small'),
(2,1,2,1,'Cafe Latte - Medium'),
(3,1,3,1,'Cafe Latte - Large');

INSERT INTO `recipe_items` (`recipe_id`,`ingredient_id`,`quantity`) VALUES
(1,1,18.000),(1,2,150.000),(1,13,1.000),(1,16,1.000),
(2,1,18.000),(2,2,250.000),(2,14,1.000),(2,16,1.000),
(3,1,27.000),(3,2,350.000),(3,15,1.000),(3,16,1.000);

-- Recipes for the other drinks (recipe_ingredients products must have one active
-- recipe per variant, otherwise PHP cannot deduct or check their ingredients).
INSERT INTO `product_recipes` (`id`,`product_id`,`variant_id`,`version`,`notes`) VALUES
(4,2,4,1,'Cappuccino - Small'),
(5,2,5,1,'Cappuccino - Medium'),
(6,2,6,1,'Cappuccino - Large'),
(7,3,7,1,'Americano - Small'),
(8,3,8,1,'Americano - Medium'),
(9,3,9,1,'Americano - Large'),
(10,4,10,1,'Iced Caramel Macchiato - Small'),
(11,4,11,1,'Iced Caramel Macchiato - Medium'),
(12,4,12,1,'Iced Caramel Macchiato - Large'),
(13,5,13,1,'Iced Spanish Latte - Small'),
(14,5,14,1,'Iced Spanish Latte - Medium'),
(15,5,15,1,'Iced Spanish Latte - Large');

INSERT INTO `recipe_items` (`recipe_id`,`ingredient_id`,`quantity`) VALUES
-- Cappuccino (espresso beans, whole milk, hot cup, lid)
(4,1,18.000),(4,2,120.000),(4,13,1.000),(4,16,1.000),
(5,1,18.000),(5,2,200.000),(5,14,1.000),(5,16,1.000),
(6,1,27.000),(6,2,280.000),(6,15,1.000),(6,16,1.000),
-- Americano (espresso beans, hot cup, lid)
(7,1,18.000),(7,13,1.000),(7,16,1.000),
(8,1,18.000),(8,14,1.000),(8,16,1.000),
(9,1,27.000),(9,15,1.000),(9,16,1.000),
-- Iced Caramel Macchiato (beans, milk, vanilla syrup, caramel sauce, ice, cup, lid, straw)
(10,1,18.000),(10,2,150.000),(10,6,15.000),(10,11,15.000),(10,18,120.000),(10,14,1.000),(10,16,1.000),(10,17,1.000),
(11,1,18.000),(11,2,220.000),(11,6,20.000),(11,11,20.000),(11,18,180.000),(11,15,1.000),(11,16,1.000),(11,17,1.000),
(12,1,27.000),(12,2,300.000),(12,6,25.000),(12,11,25.000),(12,18,240.000),(12,20,1.000),(12,16,1.000),(12,17,1.000),
-- Iced Spanish Latte (beans, milk, condensed milk, ice, cup, lid, straw)
(13,1,18.000),(13,2,130.000),(13,19,30.000),(13,18,120.000),(13,14,1.000),(13,16,1.000),(13,17,1.000),
(14,1,18.000),(14,2,200.000),(14,19,45.000),(14,18,180.000),(14,15,1.000),(14,16,1.000),(14,17,1.000),
(15,1,27.000),(15,2,280.000),(15,19,60.000),(15,18,240.000),(15,20,1.000),(15,16,1.000),(15,17,1.000);

INSERT INTO `customization_option_ingredients` (`option_id`,`ingredient_id`,`quantity`) VALUES
(12,1,9.000),
(13,6,10.000),
(14,7,10.000),
(15,8,10.000),
(16,9,15.000),
(17,10,10.000),
(18,11,10.000);

-- ---- Sample supplier ---------------------------------------------------
INSERT INTO `suppliers` (`id`,`name`,`contact_person`,`email`,`phone`,`city`,`province`,`payment_terms`) VALUES
(1,'Sample Coffee Supplier','Juan Dela Cruz','supplier@example.com','+63 900 000 0000','Dasmariñas','Cavite','Net 15');

-- ---- Notification templates --------------------------------------------
INSERT INTO `notification_templates` (`code`,`channel`,`subject`,`body_template`) VALUES
('welcome','email','Welcome to {{store_name}}','Hi {{first_name}}, welcome to {{store_name}}! Your account is ready.'),
('email_verification','email','Verify your email','Hi {{first_name}}, please verify your email using this link: {{verification_link}}'),
('password_reset','email','Reset your password','Hi {{first_name}}, reset your password using this link (valid for 1 hour): {{reset_link}}'),
('order_confirmation','email','Order {{order_number}} confirmed','Thank you {{first_name}}! We received your order {{order_number}}. Total: {{grand_total}}.'),
('payment_confirmation','email','Payment received for {{order_number}}','We received your payment of {{amount}} for order {{order_number}}. Reference: {{payment_reference}}.'),
('order_ready_for_pickup','email','Order {{order_number}} is ready','Your order {{order_number}} is ready for pickup at {{branch_name}}.'),
('order_shipped','email','Order {{order_number}} has shipped','Your order {{order_number}} is on its way. Courier: {{courier_name}}. Tracking: {{tracking_number}}.'),
('order_delivered','email','Order {{order_number}} delivered','Your order {{order_number}} was delivered. Enjoy!'),
('order_cancelled','email','Order {{order_number}} cancelled','Your order {{order_number}} has been cancelled.'),
('return_update','email','Update on request {{request_number}}','Your request {{request_number}} is now: {{status}}.'),
('refund_update','email','Refund update for {{order_number}}','Your refund {{refund_reference}} of {{amount}} is now: {{status}}.'),
('order_ready_for_pickup','sms',NULL,'Your order {{order_number}} is ready for pickup at {{branch_name}}.'),
('order_ready_for_pickup','in_app','Order ready','Your order {{order_number}} is ready for pickup.'),
('new_order','in_app','New order','New order {{order_number}} received.'),
('low_stock','in_app','Low stock alert','{{item_name}} is low at {{branch_name}} ({{quantity}} left).'),
('gift_card_delivery','email','{{sender_name}} sent you a {{store_name}} gift card','Hi {{recipient_name}},\n\n{{sender_name}} sent you a {{store_name}} gift card worth {{amount}}!\n\n{{message}}\n\nGift card code: {{gift_card_code}}\nValid until: {{expires_at}}\n\nRedeem it at checkout: {{store_url}}'),
('reward_earned','email','You earned {{points}} points','Hi {{first_name}},\n\nYou earned {{points}} loyalty points from order {{order_number}}. Your new balance is {{points_balance}} points ({{tier_name}} tier).\n\nKeep collecting to unlock free drinks and discounts!'),
('reward_earned','in_app','Points earned','You earned {{points}} points from order {{order_number}}. Balance: {{points_balance}}.'),
('support_reply','email','Re: {{ticket_subject}} [{{ticket_number}}]','Hi {{first_name}},\n\nOur team replied to your support request {{ticket_number}}:\n\n{{reply_message}}\n\nYou can view and answer it in your account: {{ticket_url}}'),
('support_reply','in_app','Support reply','New reply on your support request {{ticket_number}}.');

-- =====================================================================
SET FOREIGN_KEY_CHECKS = 1;
-- END OF schema.sql
-- =====================================================================