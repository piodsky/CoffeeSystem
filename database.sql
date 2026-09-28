-- =====================================================================
--  Brew & Bean Coffee Shop — POS System
--  Database schema + sample data  (MySQL 5.7+ / MariaDB 10.4+)
--
--  WARNING: re-importing this file DROPS and recreates every table
--  in coffee_db. Back up first if you have live data.
--
--  Default logins (change them after first sign-in):
--    admin   / admin123    (role: admin)
--    cashier / cashier123  (role: cashier)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS coffee_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE coffee_db;

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
--   cancelled = voided
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
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_no (sale_no),
  KEY idx_sales_status_date (status, created_at),
  KEY idx_sales_user (user_id),
  KEY idx_sales_customer (customer_id),
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
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
-- Shop settings (editable by admin in Settings)
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
  (1, 'admin',   '$2y$10$7gxc7KA0gu01bSQBkwmJH.vSePJE8ApYFSY0jLBfXrPAWlw31EG/y', 'Store Administrator', 'admin'),
  (2, 'cashier', '$2y$10$nDFq/226yK.ITggPBw5mTOMTJYcU4ecgAMBnVa25vB6.UDu.Xv9BG', 'Front Counter',       'cashier');

INSERT INTO settings (setting_key, setting_value) VALUES
  ('shop_name',      'Brew & Bean Coffee Shop'),
  ('shop_address',   '123 Roaster St., Poblacion, Makati City'),
  ('shop_phone',     '(02) 8123-4567'),
  ('shop_tin',       '000-123-456-000'),
  ('vat_rate',       '12.00'),
  ('receipt_footer', 'Thank you for visiting Brew & Bean! See you again.');

INSERT INTO categories (id, name, slug, icon, sort_order) VALUES
  (1, 'Coffee',      'coffee',      'coffee',    1),
  (2, 'Non-Coffee',  'non-coffee',  'glass',     2),
  (3, 'Pastries',    'pastries',    'croissant', 3),
  (4, 'Snacks',      'snacks',      'cookie',    4),
  (5, 'Merchandise', 'merchandise', 'bag',       5);

INSERT INTO products (id, category_id, code, barcode, name, description, price, stock, reorder_level) VALUES
  ( 1, 1, 'CF-001', '4800000000011', 'Iced Latte',            'Espresso, cold milk and ice',              120.00, 25, 5),
  ( 2, 1, 'CF-002', '4800000000028', 'Hot Latte',             'Espresso with steamed milk',               110.00, 18, 5),
  ( 3, 1, 'CF-003', '4800000000035', 'Americano',             'Espresso topped with hot water',            90.00, 30, 5),
  ( 4, 1, 'CF-004', '4800000000042', 'Cappuccino',            'Espresso, steamed milk, thick foam',       110.00, 16, 5),
  ( 5, 1, 'CF-005', '4800000000059', 'Caramel Macchiato',     'Vanilla, milk, espresso, caramel drizzle', 130.00, 14, 5),
  ( 6, 2, 'CF-006', '4800000000066', 'Matcha Latte',          'Ceremonial matcha with milk',              120.00, 20, 5),
  ( 7, 1, 'CF-007', '4800000000073', 'Mocha',                 'Espresso, chocolate, whipped cream',       125.00, 17, 5),
  ( 8, 1, 'CF-008', '4800000000080', 'Cold Brew',             'Slow-steeped for 18 hours',                100.00, 22, 5),
  ( 9, 2, 'NC-001', '4800000000097', 'Hot Chocolate',         'Rich Belgian cocoa',                       110.00, 15, 5),
  (10, 2, 'NC-002', '4800000000103', 'Strawberry Milk',       'Fresh strawberry puree and milk',          115.00, 12, 5),
  (11, 3, 'PT-001', '4800000000110', 'Plain Croissant',       'Buttery, flaky French croissant',           75.00, 12, 5),
  (12, 3, 'PT-002', '4800000000127', 'Chocolate Croissant',   'Croissant with dark chocolate',             85.00, 10, 5),
  (13, 3, 'PT-003', '4800000000134', 'Blueberry Muffin',      'Loaded with blueberries',                   70.00, 15, 5),
  (14, 4, 'SN-001', '4800000000141', 'Cookies',               'Chocolate chip cookies (2 pcs)',            50.00, 28, 5),
  (15, 4, 'SN-002', '4800000000158', 'Banana Bread',          'Homemade, per slice',                       65.00,  3, 5),
  (16, 4, 'SN-003', '4800000000165', 'Ham & Cheese Sandwich', 'On toasted sourdough',                     145.00,  8, 5),
  (17, 5, 'MD-001', '4800000000172', 'Brew & Bean Tumbler',   'Insulated 16oz tumbler',                   450.00, 10, 3),
  (18, 5, 'MD-002', '4800000000189', 'House Blend Beans 250g','Medium roast whole beans',                 380.00,  2, 3);

INSERT INTO customers (id, name, phone, email, address) VALUES
  (1, 'Juan Dela Cruz', '0917 123 4567', 'juan.delacruz@example.com', 'Makati City'),
  (2, 'Maria Santos',   '0918 234 5678', 'maria.santos@example.com',  'Pasig City'),
  (3, 'Carlo Reyes',    '0919 345 6789', NULL,                         'Taguig City'),
  (4, 'Angela Lim',     '0920 456 7890', 'angela.lim@example.com',    NULL);

-- A few completed sales so History/Reports have data.
-- Totals: VAT 12% is applied on (subtotal - discount).
INSERT INTO sales (id, sale_no, user_id, customer_id, payment_type, status, subtotal, discount_percent, discount_amount, vat_rate, vat_amount, total, amount_paid, change_amount, created_at, completed_at) VALUES
  (1, '0000001', 2, NULL, 'cash',  'completed', 315.00,  0.00,  0.00, 12.00, 37.80, 352.80, 400.00,  47.20, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
  (2, '0000002', 2, 1,    'gcash', 'completed', 230.00, 10.00, 23.00, 12.00, 24.84, 231.84, 231.84,   0.00, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (3, '0000003', 1, 2,    'card',  'completed', 410.00,  0.00,  0.00, 12.00, 49.20, 459.20, 459.20,   0.00, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
  (4, '0000004', 2, NULL, 'cash',  'completed', 310.00,  0.00,  0.00, 12.00, 37.20, 347.20, 500.00, 152.80, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 1 HOUR);

INSERT INTO sale_items (sale_id, product_id, product_code, product_name, unit_price, quantity, line_total) VALUES
  (1,  1, 'CF-001', 'Iced Latte',          120.00, 2, 240.00),
  (1, 11, 'PT-001', 'Plain Croissant',      75.00, 1,  75.00),
  (2,  3, 'CF-003', 'Americano',            90.00, 1,  90.00),
  (2, 13, 'PT-003', 'Blueberry Muffin',     70.00, 2, 140.00),
  (3,  5, 'CF-005', 'Caramel Macchiato',   130.00, 2, 260.00),
  (3, 14, 'SN-001', 'Cookies',              50.00, 3, 150.00),
  (4,  7, 'CF-007', 'Mocha',               125.00, 1, 125.00),
  (4, 12, 'PT-002', 'Chocolate Croissant',  85.00, 1,  85.00),
  (4,  8, 'CF-008', 'Cold Brew',           100.00, 1, 100.00);

-- Opening stock in the audit log
INSERT INTO stock_movements (product_id, user_id, type, quantity, stock_after, note)
SELECT id, 1, 'initial', stock, stock, 'Opening stock' FROM products;

-- Sample product illustrations (files in assets/uploads/products/)
UPDATE products SET image = CONCAT('sample-', LOWER(code), '.png');
