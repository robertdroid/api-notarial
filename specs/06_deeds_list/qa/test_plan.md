# Plan de Pruebas: Listado de Escrituras con Búsqueda

## Información General
- **Feature:** 06_deeds_list
- **Rama/PR:** `feature/06-deeds-list` / PR #5
- **QA:** Claude (QA)
- **Fecha:** 2026-11-10

## Alcance de Pruebas

### En Scope
- `GET /api/v3/deeds`: listado paginado, ordenamiento, búsqueda por palabras
- Validación de `page` y normalización de `search`
- Autenticación JWT y aislamiento multi-tenant
- Migración de índices (`idx_escrituras_notaria`, `idx_participantes_escritura_tipo`)
- Documentación OpenAPI
- Regresión de endpoints existentes

### Fuera de Scope
- Filtros avanzados, exportación, ordenamiento configurable, detalle y CRUD de escrituras (excluidos por el PO)
- Soft delete de escrituras (ADR-004)

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API, HTTP real contra el contenedor)
- [x] Integración (queries contra BD local con datos reales + oráculo SQL independiente)
- [x] Regresión
- [x] Multi-tenancy

## Estrategia
- **Tokens:** JWT de prueba firmados con el `JwtService` de la app para las notarías 1 y 3 (no se usaron credenciales de usuarios reales).
- **Oráculo SQL:** consulta independiente (JOIN/`IN` en lugar de `EXISTS`) para contrastar conteos de búsqueda.
- **Orden:** descarga de las 134 páginas de la notaría 1 y verificación de unicidad de ids y orden descendente.
- **Casos de datos:** participantes eliminados (`id_estatus = 0`), palabras repartidas entre participantes (ADR-002), escrituras sin participantes, números no numéricos.

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- PHPUnit: 10.5.66
- MariaDB: 11.4 (contenedor `notarial-db`, notaría 1 con 13372 escrituras, notaría 3 con 6049)
- Docker: activo (api, db, phpmyadmin)
