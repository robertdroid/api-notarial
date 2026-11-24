-- Migration: add_search_indexes_to_escrituras
-- Description: Indexes required by GET /api/v3/deeds (notary filter + participant EXISTS lookup)
-- Author: Roberto Trejo
-- Date: 2026-11-10

-- UP
ALTER TABLE escrituras
    ADD INDEX idx_escrituras_notaria (id_notaria);

ALTER TABLE participantes_escritura
    ADD INDEX idx_participantes_escritura_tipo (id_escritura, id_tipo_participante, id_estatus);

-- DOWN (rollback)
-- ALTER TABLE escrituras DROP INDEX idx_escrituras_notaria;
-- ALTER TABLE participantes_escritura DROP INDEX idx_participantes_escritura_tipo;
