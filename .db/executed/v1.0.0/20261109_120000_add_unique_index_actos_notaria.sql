-- Migration: add_unique_index_actos_notaria
-- Description: Add unique index for act name per notary to prevent duplicates
-- Author: Roberto Trejo
-- Date: 2026-11-09

-- UP
CREATE UNIQUE INDEX idx_actos_nombre_notaria
ON actos_notaria (id_notaria, nombre_acto);

-- DOWN (rollback)
-- DROP INDEX idx_actos_nombre_notaria ON actos_notaria;
