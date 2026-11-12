-- Migration: normalize_participant_status
-- Description: Legacy "deleted" participants (id_estatus = 0) become inactive (2).
--              Cosmetic while v1 coexists (it may keep writing 0); v3 code treats any value <> 1 as inactive.
--              Re-run after v1 is retired.
-- Author: Roberto Trejo
-- Date: 2026-11-12

-- UP
UPDATE participantes_escritura SET id_estatus = 2 WHERE id_estatus = 0;

-- DOWN (not reversible: 0 and 2 are indistinguishable afterwards; both mean inactive)
