-- Migration: payments_transactions_innodb_and_indexes
-- Description: Moves payments_transactions and payments_transaction_details to InnoDB (atomic
--              header + details writes) and adds the natural keys:
--              UNIQUE (id_notary, uuid) for idempotent POST, PK (id_transaction, id_tax) for
--              details, and (id_notary, id_client) for per-client lookups.
-- Author: Roberto Trejo
-- Date: 2026-11-11

-- PRE-CHECK (must all return 0 rows in production before applying): pre-checks A, C (c > 1) and D.
-- The legacy v1 app still writes to these tables: confirm task 0.1 of the implementation plan first.

-- UP
ALTER TABLE payments_transactions ENGINE=InnoDB;
ALTER TABLE payments_transaction_details ENGINE=InnoDB;

ALTER TABLE payments_transactions
    ADD UNIQUE INDEX uq_payments_transactions_notary_uuid (id_notary, uuid),
    ADD INDEX idx_payments_transactions_notary_client (id_notary, id_client);

ALTER TABLE payments_transaction_details
    ADD PRIMARY KEY (id_transaction, id_tax);

-- DOWN (rollback)
-- ALTER TABLE payments_transaction_details DROP PRIMARY KEY;
-- ALTER TABLE payments_transactions
--     DROP INDEX idx_payments_transactions_notary_client,
--     DROP INDEX uq_payments_transactions_notary_uuid;
-- ALTER TABLE payments_transaction_details ENGINE=MyISAM;
-- ALTER TABLE payments_transactions ENGINE=MyISAM;
