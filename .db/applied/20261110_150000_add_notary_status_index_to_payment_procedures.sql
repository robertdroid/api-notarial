-- Migration: add_notary_status_index_to_payment_procedures
-- Description: Index for GET /api/v3/payment-procedures (notary filter + status filter)
-- Author: Roberto Trejo
-- Date: 2026-11-10

-- UP
ALTER TABLE payment_procedures
    ADD INDEX idx_payment_procedures_notary_status (id_notary, id_status);

-- DOWN (rollback)
-- ALTER TABLE payment_procedures DROP INDEX idx_payment_procedures_notary_status;
