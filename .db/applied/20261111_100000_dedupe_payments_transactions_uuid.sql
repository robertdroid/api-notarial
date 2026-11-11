-- Migration: dedupe_payments_transactions_uuid
-- Description: Removes duplicated transactions (same id_notary + uuid), keeping the lowest
--              id_transaction, so UNIQUE (id_notary, uuid) can be created afterwards.
--              Details of the removed transactions are deleted first to avoid orphans.
-- Author: Roberto Trejo
-- Date: 2026-11-11

-- PRE-CHECK (mandatory in production, see specs/11_payment_transactions/arch/developer_notes.md):
--   Run pre-checks A and B. Apply this migration ONLY if every duplicated group has identical
--   rows and the rows to be removed have no details. Back up both tables first.

-- UP
DELETE d FROM payments_transaction_details d
INNER JOIN payments_transactions dup  ON dup.id_transaction = d.id_transaction
INNER JOIN payments_transactions keep ON keep.id_notary = dup.id_notary
                                     AND keep.uuid = dup.uuid
                                     AND keep.id_transaction < dup.id_transaction;

DELETE dup FROM payments_transactions dup
INNER JOIN payments_transactions keep ON keep.id_notary = dup.id_notary
                                     AND keep.uuid = dup.uuid
                                     AND keep.id_transaction < dup.id_transaction;

-- DOWN (rollback)
-- Not reversible by SQL: restore the removed rows from the backup taken before applying.
