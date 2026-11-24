# Plan de Pruebas: Payment Procedure Taxes CRUD

## Información General
- **Feature:** 10_payment_procedure_taxes_crud
- **Rama/PR:** `feature/10-payment-procedure-taxes-crud` / PR #9 → `develop`
- **QA:** Claude (QA Engineer)
- **Fecha:** 2026-11-10

## Alcance de Pruebas

### En Scope
- `GET/POST /api/v3/payment-procedures/{id}/taxes` (single y bulk)
- `PUT/DELETE /api/v3/payment-procedures/{id}/taxes/{taxId}`
- Rehabilitación (status 2 → 1) vía POST
- Validaciones de DTO, códigos 400/404/409/422
- Multi-tenancy (notaría 1 vs. notaría 3)
- Migración del índice único `(id_procedure, id_tax)`
- Atomicidad del bulk, soft delete, concurrencia
- Regresión en endpoints de payment-procedures / payment-taxes / payment-clients

### Fuera de Scope
- CRUD de payment-taxes (spec 07) y payment-procedures (spec 08) más allá de regresión
- Consulta inversa (procedimientos por impuesto)
- Tests de carga

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API)
- [x] Integración (verificación directa en BD)
- [x] Regresión
- [x] Multi-tenancy

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- MariaDB: 11.4 (contenedor `notarial-db`, tablas MyISAM)
- Docker: activo
- Autenticación: JWT generado con el `JwtService` de la app (notarías 1 y 3), ya que no se conocen las contraseñas de usuarios reales
- Fixtures temporales: un impuesto inactivo (`QA_INACTIVE_TAX`, notaría 1) y filas en procedimientos 17–20; todo eliminado al terminar (tabla `payment_procedure_taxes` restaurada a 11 filas)
