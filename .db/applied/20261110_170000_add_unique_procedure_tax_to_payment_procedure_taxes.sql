-- Migration: add_unique_procedure_tax_to_payment_procedure_taxes
-- Description: Natural key (id_procedure, id_tax) for payment_procedure_taxes.
--              Prevents duplicate assignments and enables INSERT ... ON DUPLICATE KEY UPDATE.
--              Also serves GET /api/v3/payment-procedures/{id}/taxes (prefix id_procedure).
-- Author: Roberto Trejo
-- Date: 2026-11-10

-- PRE-CHECK (run in production BEFORE applying; must return 0 rows):
-- SELECT id_procedure, id_tax, COUNT(*) AS c, GROUP_CONCAT(id_status) AS statuses
-- FROM payment_procedure_taxes
-- GROUP BY id_procedure, id_tax
-- HAVING c > 1;

-- UP
ALTER TABLE payment_procedure_taxes
    ADD UNIQUE INDEX uq_payment_procedure_taxes_procedure_tax (id_procedure, id_tax);

-- DOWN (rollback)
-- ALTER TABLE payment_procedure_taxes DROP INDEX uq_payment_procedure_taxes_procedure_tax;
