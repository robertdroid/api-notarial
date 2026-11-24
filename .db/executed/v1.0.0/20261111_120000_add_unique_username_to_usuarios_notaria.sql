-- Migration: add_unique_username_to_usuarios_notaria
-- Description: Enforces globally unique usernames (login has no notary context) and adds a
--              (id_notaria, id_estatus) index for the users list.
--              Case-insensitive uniqueness is enforced by the application (spec 12, ADR-003);
--              this index only blocks exact duplicates and races.
-- Author: Roberto Trejo
-- Date: 2026-11-11

-- PRE-CHECK A (must return 0 rows; if not, STOP and escalate: business must pick which user to rename)
-- SELECT usuario, COUNT(*) c, GROUP_CONCAT(id_usuario) ids
-- FROM usuarios_notaria GROUP BY usuario HAVING c > 1;

-- PRE-CHECK B (informative; does not block the index, report to PO if any)
-- SELECT LOWER(usuario) u, COUNT(*) c, GROUP_CONCAT(id_usuario) ids
-- FROM usuarios_notaria GROUP BY LOWER(usuario) HAVING c > 1;

-- UP
ALTER TABLE usuarios_notaria
    ADD UNIQUE INDEX uq_usuarios_notaria_usuario (usuario),
    ADD INDEX idx_usuarios_notaria_notary_status (id_notaria, id_estatus);

-- DOWN (rollback)
-- ALTER TABLE usuarios_notaria
--     DROP INDEX idx_usuarios_notaria_notary_status,
--     DROP INDEX uq_usuarios_notaria_usuario;
