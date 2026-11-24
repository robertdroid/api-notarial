-- Migration: add_notary_status_index_to_payments_clients
-- Description: Index for GET /api/v3/payment-clients (notary filter + status filter)
-- Author: Roberto Trejo
-- Date: 2026-11-10

-- UP
ALTER TABLE payments_clients
    ADD INDEX idx_payments_clients_notary_status (id_notary, id_status);

-- DOWN (rollback)
-- ALTER TABLE payments_clients DROP INDEX idx_payments_clients_notary_status;
