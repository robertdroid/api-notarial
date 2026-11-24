-- Migration: payments_payments_innodb_and_indexes
-- Description: Moves payments_payments to InnoDB and adds the natural keys:
--              UNIQUE (id_notary, uuid) for idempotent POST and
--              (id_notary, id_client) for per-client lookups.
-- Author: Roberto Trejo
-- Date: 2026-11-18

-- PRE-CHECK (must all return 0 rows in production before applying; task 0.1 of the implementation plan):
-- A. Duplicates by (id_notary, uuid):
--    SELECT id_notary, uuid, COUNT(*) c FROM payments_payments GROUP BY id_notary, uuid HAVING c > 1;
-- B. Empty uuid (they would collide with each other in the unique index):
--    SELECT id_payment FROM payments_payments WHERE uuid = '';
-- C. uuid outside ^[A-Za-z0-9_-]{1,20}$ (could not be fetched by uuid nor sent in a PUT):
--    SELECT id_payment, uuid FROM payments_payments WHERE uuid NOT REGEXP '^[A-Za-z0-9_-]{1,20}$';
-- D. The legacy v1 app still writes to this table: confirm its INSERTs generate a uuid. If they do not,
--    apply this migration after v1 stops writing (a repeated '' uuid would fail on the unique index).

-- UP
ALTER TABLE payments_payments ENGINE=InnoDB;

ALTER TABLE payments_payments
    ADD UNIQUE INDEX uq_payments_payments_notary_uuid (id_notary, uuid),
    ADD INDEX idx_payments_payments_notary_client (id_notary, id_client);

-- DOWN (rollback)
-- ALTER TABLE payments_payments
--     DROP INDEX idx_payments_payments_notary_client,
--     DROP INDEX uq_payments_payments_notary_uuid;
-- ALTER TABLE payments_payments ENGINE=MyISAM;
