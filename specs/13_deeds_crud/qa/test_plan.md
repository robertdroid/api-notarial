# Plan de Pruebas: CRUD Completo de Escrituras

## Información General
- **Feature:** 13_deeds_crud
- **Rama/PR:** feature/13-deeds-crud / PR #12 (base: develop)
- **QA:** Claude (QA)
- **Fecha:** 2026-11-12

## Alcance de Pruebas

### En Scope
- Las 12 rutas nuevas: `GET/POST /deeds`, `GET/PUT /deeds/{id}`, `POST /deeds/{id}/finish`, y los subrecursos `participants` y `registrations` (GET, POST, PUT, DELETE)
- Autenticación JWT en las 12 rutas, ids de ruta inválidos (`abc`, `1.5`, `0`, `-5`)
- Multi-tenancy (notarías 1, 2 y 3): token de una notaría contra recursos de otra, en los 12 endpoints
- Validaciones de body (longitudes con multibyte, fechas, tipos estrictos, límites de listas, `typeId`)
- Reglas de referencias: acto, tipo y fraccionamiento inactivos / de otra notaría; `subdivision_not_allowed`; `registration_limit_exceeded`; reglas "solo si cambia" en el PUT
- Corrección del bug legacy: `PUT` nunca toca `terminada`; `finish` irreversible
- Auditoría (`id_usuario`, `fecha_modificacion`) en escritura y en subrecursos
- Atomicidad (rollback forzado) y concurrencia (`FOR UPDATE`: inscripciones y `finish`)
- Compatibilidad con la v1: centinelas `id_fraccionamiento` `-1`/`0`, `id_estatus` `0`, fechas `0000-00-00`, `terminada` NULL
- Migraciones (InnoDB, índice, ausencia de UNIQUE, normalización de estatus)
- Cantidad de queries, `EXPLAIN` y tiempos de respuesta
- Criterios de código (strict_types, sin `SELECT *`, repos sin transacciones, `UPDATE` sin `terminada`)
- `docs/openapi.yaml` y regresión (suite completa + smoke de los demás módulos)

### Fuera de Scope
- `DELETE /deeds/{id}` y reabrir escrituras (excluidos por el PO)
- Unicidad de `deedNumber` y su 409: **eliminada por ADR-002** (se verifica lo contrario: duplicados permitidos)
- Interoperabilidad real con la v1 (no disponible localmente; tarea 0.1 del plan es previa al deploy)
- Body JSON escalar (`"abc"`) → 500: problema preexistente de `Core\Http\Request`, documentado en el spec 12 y marcado fuera de alcance
- Cobertura de código (el contenedor no tiene Xdebug ni PCOV)

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API, 321 aserciones sobre HTTP y BD)
- [x] Integración (BD real InnoDB, transacciones, rollback)
- [x] Regresión (suite completa + smoke)
- [x] Multi-tenancy
- [x] Concurrencia
- [x] Seguridad básica (SQLi, token forjado, fuga de errores, XSS almacenado)
- [x] Rendimiento (queries por request, EXPLAIN, tiempos)

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- MariaDB: 11.4 (contenedor `notarial-db`); `escrituras`, `participantes_escritura`, `inscripciones_escritura` en **InnoDB** (migraciones aplicadas); catálogos en MyISAM
- Docker: `notarial-api`, `notarial-db` y `notarial-phpmyadmin` arriba
- Datos: BD local con 19,432 escrituras (notarías 1, 2 y 3). Fixtures temporales `QA13_*` (acto, tipo y fraccionamiento inactivos) y escrituras `QA13-*`, **eliminados al terminar** (la BD quedó con 19,432 escrituras, igual que al inicio)
- Tokens: JWT firmados con el secreto local para usuarios 2 y 8 (notaría 1), 3 (notaría 2) y 4 (notaría 3)

## Scripts
`specs/13_deeds_crud/qa/test_evidence/scripts/` (`lib.sh`, `part1.sh`, `part2.sh`, `part3.sh`). Se ejecutan con `bash` desde la raíz del repo, con los tokens en `/tmp/qa13/`.
