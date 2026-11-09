-- Migration: add_password_hash_to_usuarios_notaria
-- Description: Agrega columna password_hash para migración de MD5 a bcrypt
-- Author: Roberto Trejo
-- Date: 2026-11-09

-- UP
ALTER TABLE usuarios_notaria
ADD COLUMN password_hash VARCHAR(255) NULL
AFTER contrasenia;

-- Índice en usuario para búsqueda rápida (si no existe)
-- CREATE INDEX idx_usuarios_notaria_usuario ON usuarios_notaria(usuario);

-- DOWN (rollback)
-- ALTER TABLE usuarios_notaria DROP COLUMN password_hash;
