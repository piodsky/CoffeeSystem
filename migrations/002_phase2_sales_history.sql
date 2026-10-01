-- =====================================================================
--  Phase 2 migration: Sales History (void a sale).
--  Safe to run on a live execomlogistics_db, and safe to run twice:
--  it only ADDS columns/indexes; it never drops or changes data.
--    C:\xampp\mysql\bin\mysql.exe -u root execomlogistics_db < migrations\002_phase2_sales_history.sql
-- =====================================================================

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS voided_at   DATETIME     NULL AFTER completed_at,
  ADD COLUMN IF NOT EXISTS voided_by   INT UNSIGNED NULL AFTER voided_at,
  ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL AFTER voided_by,
  ADD INDEX IF NOT EXISTS idx_sales_created (created_at),
  ADD INDEX IF NOT EXISTS idx_sales_voided_by (voided_by);

ALTER TABLE sales
  ADD CONSTRAINT fk_sales_voided_by FOREIGN KEY IF NOT EXISTS (voided_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL;
