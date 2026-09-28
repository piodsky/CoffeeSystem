-- =====================================================================
--  Phase 3 migration: stock audit log + sample product images.
--  Safe to run on a live coffee_db: it only ADDS; it never drops data.
--    C:\xampp\mysql\bin\mysql.exe -u root coffee_db < migrations\003_phase3_inventory.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS stock_movements (
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

-- Opening stock for products that have no history yet
INSERT INTO stock_movements (product_id, user_id, type, quantity, stock_after, note)
SELECT p.id, NULL, 'initial', p.stock, p.stock, 'Opening stock (Phase 3 upgrade)'
  FROM products p
 WHERE NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.product_id = p.id);

-- Sample illustrations for the original sample products that still have no image
UPDATE products SET image = CONCAT('sample-', LOWER(code), '.png')
 WHERE image IS NULL
   AND code IN ('CF-001','CF-002','CF-003','CF-004','CF-005','CF-006','CF-007','CF-008','NC-001','NC-002',
                'PT-001','PT-002','PT-003','SN-001','SN-002','SN-003','MD-001','MD-002');
