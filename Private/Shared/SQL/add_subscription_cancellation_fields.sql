-- Add cancellation tracking fields to payments table
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL DEFAULT NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(255) NULL DEFAULT NULL AFTER cancelled_at;
