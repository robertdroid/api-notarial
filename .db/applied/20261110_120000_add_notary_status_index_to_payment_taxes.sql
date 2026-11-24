-- Migration: add_notary_status_index_to_payment_taxes
-- Description: Index for GET /api/v3/payment-taxes (notary filter + status filter)
-- Author: Roberto Trejo
-- Date: 2026-11-10

-- UP
ALTER TABLE payment_taxes
    ADD INDEX idx_payment_taxes_notary_status (id_notary, id_status);

-- DOWN (rollback)
-- ALTER TABLE payment_taxes DROP INDEX idx_payment_taxes_notary_status;
