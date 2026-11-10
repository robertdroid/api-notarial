# Plan de Pruebas: Payment Taxes CRUD

## Información General
- **Feature:** 07_payment_taxes_crud
- **Rama/PR:** `feature/07-payment-taxes-crud` / PR #6
- **QA:** Claude (QA)
- **Fecha:** 2026-11-10

## Alcance de Pruebas

### En Scope
- `GET/POST /api/v3/payment-taxes` y `GET/PUT /api/v3/payment-taxes/{id}`
- Filtros `status` y `name` (parcial, insensible a mayúsculas/acentos, comodines escapados)
- Validaciones de `taxName` y `status`, unicidad por notaría
- Autenticación JWT y aislamiento multi-tenant
- Migración del índice `idx_payment_taxes_notary_status`
- Documentación OpenAPI

### Fuera de Scope
- Endpoint DELETE (excluido por el PO; se verifica que no exista)
- Relación impuesto-procedimiento
- Mensajes 401 en inglés (ver ADR-005; QA valida solo el status 401)

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API, HTTP real contra el contenedor)
- [x] Integración (BD local, EXPLAIN, verificación directa de filas)
- [x] Regresión (suite completa)
- [x] Multi-tenancy

## Estrategia
- Tokens JWT generados con el mismo secreto/issuer/audience que `JwtService`, para notarías 1 y 3, y uno expirado.
- Las pruebas de escritura solo tocan filas creadas por QA (prefijo `QA`), eliminadas al terminar; los datos semilla quedaron intactos.

## Ambiente de Pruebas
- PHP: 8.3.36
- MariaDB: 11.4 (Docker `notarial-db`)
- Docker: contenedores `notarial-api`, `notarial-db` activos
