-- =====================================================================
--  EXECOM Logistics — POS & Inventory System
--  Database schema + sample data  (MySQL 5.7+ / MariaDB 10.4+)
--
--  WARNING: re-importing this file DROPS and recreates every table
--  in execomlogistics_db. Back up first if you have live data.
--
--  Default logins (change them after first sign-in):
--    admin   / admin123    (role: admin)
--    cashier / cashier123  (role: cashier)
-- =====================================================================

-- Silence the harmless "database exists" / "unknown table" notes that
-- IF [NOT] EXISTS produces, so the import reports 0 warnings.
SET @OLD_SQL_NOTES = @@SQL_NOTES, SQL_NOTES = 0;

CREATE DATABASE IF NOT EXISTS execomlogistics_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE execomlogistics_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;
SET SQL_NOTES = @OLD_SQL_NOTES;

-- ---------------------------------------------------------------------
-- Users & authentication
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(50)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(100) NOT NULL,
  role           ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection: failed logins are counted per username+IP.
CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  ip_address    VARCHAR(45)  NOT NULL,
  success       TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_ip_time (ip_address, attempted_at),
  KEY idx_attempts_user_ip (username, ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Catalog
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(50)  NOT NULL,
  slug        VARCHAR(50)  NOT NULL,
  icon        VARCHAR(30)  NOT NULL DEFAULT 'grid',
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  category_id    INT UNSIGNED  NOT NULL,
  code           VARCHAR(20)   NOT NULL,
  barcode        VARCHAR(50)   NULL,
  name           VARCHAR(100)  NOT NULL,
  description    VARCHAR(255)  NULL,
  price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock          INT           NOT NULL DEFAULT 0,
  reorder_level  INT           NOT NULL DEFAULT 5,
  image          VARCHAR(255)  NULL,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (code),
  UNIQUE KEY uq_products_barcode (barcode),
  KEY idx_products_category (category_id),
  KEY idx_products_name (name),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_products_price CHECK (price >= 0),
  CONSTRAINT chk_products_stock CHECK (stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Customers  (a sale with customer_id = NULL is a walk-in)
-- ---------------------------------------------------------------------
CREATE TABLE customers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  phone       VARCHAR(30)  NULL,
  email       VARCHAR(120) NULL,
  address     VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Sales
--   held      = saved cart, stock NOT yet deducted
--   completed = paid, stock deducted
--   cancelled = voided (stock returned; who/when/why in voided_by, voided_at, void_reason)
-- ---------------------------------------------------------------------
CREATE TABLE sales (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_no           VARCHAR(20)   NOT NULL,
  user_id           INT UNSIGNED  NOT NULL,
  customer_id       INT UNSIGNED  NULL,
  payment_type      ENUM('cash','gcash','card') NOT NULL DEFAULT 'cash',
  status            ENUM('held','completed','cancelled') NOT NULL DEFAULT 'completed',
  subtotal          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_percent  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  discount_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vat_rate          DECIMAL(5,2)  NOT NULL DEFAULT 12.00,
  vat_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_paid       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  change_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at      DATETIME      NULL,
  voided_at         DATETIME      NULL,
  voided_by         INT UNSIGNED  NULL,
  void_reason       VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_no (sale_no),
  KEY idx_sales_status_date (status, created_at),
  KEY idx_sales_user (user_id),
  KEY idx_sales_customer (customer_id),
  KEY idx_sales_created (created_at),
  KEY idx_sales_voided_by (voided_by),
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_sales_discount CHECK (discount_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name/code/price are copied onto each line so history survives product edits.
CREATE TABLE sale_items (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_id       INT UNSIGNED  NOT NULL,
  product_id    INT UNSIGNED  NULL,
  product_code  VARCHAR(20)   NOT NULL,
  product_name  VARCHAR(100)  NOT NULL,
  unit_price    DECIMAL(10,2) NOT NULL,
  quantity      INT           NOT NULL,
  line_total    DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_items_sale (sale_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Stock audit log: every change to products.stock and why.
--   quantity is signed (+ in, - out); stock_after is the level after the change.
-- ---------------------------------------------------------------------
CREATE TABLE stock_movements (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id   INT UNSIGNED    NOT NULL,
  user_id      INT UNSIGNED    NULL,
  sale_id      INT UNSIGNED    NULL,
  type         ENUM('initial','sale','restock','adjustment','void') NOT NULL,
  quantity     INT             NOT NULL,
  stock_after  INT             NOT NULL,
  note         VARCHAR(255)    NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movements_product (product_id, created_at),
  CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_movements_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_movements_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Company settings (editable by admin in Settings)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  setting_key    VARCHAR(50)  NOT NULL,
  setting_value  VARCHAR(255) NOT NULL DEFAULT '',
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  SAMPLE DATA
-- =====================================================================

-- Passwords: admin123 / cashier123 (bcrypt via password_hash)
INSERT INTO users (id, username, password_hash, full_name, role) VALUES
  (1, 'admin',   '$2y$10$7gxc7KA0gu01bSQBkwmJH.vSePJE8ApYFSY0jLBfXrPAWlw31EG/y', 'System Administrator', 'admin'),
  (2, 'cashier', '$2y$10$nDFq/226yK.ITggPBw5mTOMTJYcU4ecgAMBnVa25vB6.UDu.Xv9BG', 'Sales Counter',        'cashier');

-- Company details printed on receipts. Replace them with your own
-- (Settings page arrives in Phase 4; until then edit them in phpMyAdmin).
INSERT INTO settings (setting_key, setting_value) VALUES
  ('shop_name',      'EXECOM Logistics'),
  ('shop_address',   'Company Address, City, Province'),
  ('shop_phone',     '(000) 000-0000'),
  ('shop_tin',       ''),
  ('vat_rate',       '12.00'),
  ('receipt_footer', 'Thank you for choosing EXECOM Logistics! Keep this receipt for warranty claims.');

INSERT INTO categories (id, name, slug, icon, sort_order) VALUES
  (1, 'Laptops & Computers', 'laptops-computers', 'laptop',    1),
  (2, 'Peripherals',         'peripherals',       'mouse',     2),
  (3, 'Accessories',         'accessories',       'plug',      3),
  (4, 'Network',             'network',           'network',   4),
  (5, 'Office Supplies',     'office-supplies',   'clipboard', 5);

INSERT INTO products (id, category_id, code, barcode, name, description, price, stock, reorder_level) VALUES
  ( 1, 1, 'ITM-0001', '4806500000011', 'Laptop',            '14" Core i5, 8GB RAM, 512GB SSD',        25000.00, 12, 3),
  ( 2, 2, 'ITM-0002', '4806500000028', 'Mouse',             'Wireless optical mouse, 2.4GHz',           350.00, 45, 10),
  ( 3, 2, 'ITM-0003', '4806500000035', 'Keyboard',          'Full-size USB keyboard',                   550.00, 32, 10),
  ( 4, 2, 'ITM-0004', '4806500000042', 'Monitor 24"',       '24" Full HD IPS monitor, HDMI/VGA',       4500.00, 18, 5),
  ( 5, 3, 'ITM-0005', '4806500000059', 'UPS 1000VA',        '1000VA line-interactive UPS with AVR',    3800.00, 10, 3),
  ( 6, 4, 'ITM-0006', '4806500000066', 'Network Switch',    '16-port Gigabit unmanaged switch',        2500.00, 15, 3),
  ( 7, 3, 'ITM-0007', '4806500000073', 'External HDD 1TB',  'USB 3.0 portable hard drive',             3200.00, 22, 5),
  ( 8, 5, 'ITM-0008', '4806500000080', 'Printer',           'Ink tank printer: print, scan, copy',     6500.00,  8, 3),
  ( 9, 3, 'ITM-0009', '4806500000097', 'RAM 8GB',           'DDR4 3200MHz desktop memory',             1800.00, 30, 8),
  (10, 3, 'ITM-0010', '4806500000103', 'SSD 512GB',         '2.5" SATA III solid state drive',         2800.00, 20, 5),
  (11, 2, 'ITM-0011', '4806500000110', 'Webcam',            '1080p USB webcam with microphone',        1200.00, 16, 5),
  (12, 2, 'ITM-0012', '4806500000127', 'Headset',           'Over-ear USB headset with boom mic',      1500.00, 14, 5);

INSERT INTO customers (id, name, phone, email, address) VALUES
  (1, 'Juan Dela Cruz', '0917 123 4567', 'juan.delacruz@example.com', 'Makati City'),
  (2, 'Maria Santos',   '0918 234 5678', 'maria.santos@example.com',  'Pasig City'),
  (3, 'Carlo Reyes',    '0919 345 6789', NULL,                         'Taguig City'),
  (4, 'Angela Lim',     '0920 456 7890', 'angela.lim@example.com',    NULL);

-- A few completed sales so History/Reports have data.
-- Totals: VAT 12% is applied on (subtotal - discount).
INSERT INTO sales (id, sale_no, user_id, customer_id, payment_type, status, subtotal, discount_percent, discount_amount, vat_rate, vat_amount, total, amount_paid, change_amount, created_at, completed_at) VALUES
  (1, '0000001', 2, NULL, 'cash',  'completed', 1250.00,  0.00,   0.00, 12.00, 150.00, 1400.00, 1500.00, 100.00, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (2, '0000002', 2, 1,    'gcash', 'completed', 4600.00, 10.00, 460.00, 12.00, 496.80, 4636.80, 4636.80,   0.00, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (3, '0000003', 1, 2,    'card',  'completed', 5700.00,  0.00,   0.00, 12.00, 684.00, 6384.00, 6384.00,   0.00, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (4, '0000004', 2, NULL, 'cash',  'completed', 4700.00,  0.00,   0.00, 12.00, 564.00, 5264.00, 5300.00,  36.00, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 HOUR);

INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_price, quantity, line_total) VALUES
  (1,  2, 'ITM-0002', 'Mouse',             350.00, 2,  700.00),
  (1,  3, 'ITM-0003', 'Keyboard',          550.00, 1,  550.00),
  (2,  9, 'ITM-0009', 'RAM 8GB',          1800.00, 1, 1800.00),
  (2, 10, 'ITM-0010', 'SSD 512GB',        2800.00, 1, 2800.00),
  (3,  4, 'ITM-0004', 'Monitor 24"',      4500.00, 1, 4500.00),
  (3, 11, 'ITM-0011', 'Webcam',           1200.00, 1, 1200.00),
  (4, 12, 'ITM-0012', 'Headset',          1500.00, 1, 1500.00),
  (4,  7, 'ITM-0007', 'External HDD 1TB', 3200.00, 1, 3200.00);

-- Opening stock in the audit log
INSERT INTO stock_movements (product_id, user_id, type, quantity, stock_after, note)
SELECT id, 1, 'initial', stock, stock, 'Opening stock' FROM products;

-- Sample product illustrations (files in assets/uploads/products/)
UPDATE products SET image = CONCAT('sample-', LOWER(code), '.png');
