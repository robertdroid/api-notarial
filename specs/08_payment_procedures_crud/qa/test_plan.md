# Plan de Pruebas: Payment Procedures CRUD

## Información General
- **Feature:** 08_payment_procedures_crud
- **Rama/PR:** feature/08-payment-procedures-crud / PR #7
- **QA:** Claude (QA)
- **Fecha:** 2026-11-10

## Alcance de Pruebas

### En Scope
- `GET /api/v3/payment-procedures` (filtros `status`, `name`)
- `GET /api/v3/payment-procedures/{id}`
- `POST /api/v3/payment-procedures`
- `PUT /api/v3/payment-procedures/{id}` (incluye soft delete con `status: 2`)
- Autenticación JWT, multi-tenancy, validaciones y casos edge
- Migración del índice `(id_notary, id_status)` y documentación OpenAPI

### Fuera de Scope
- Endpoint DELETE (no existe; baja vía PUT)
- Relación procedimiento-impuesto (`payment_procedure_taxes`)
- Validación de uso en transacciones antes de desactivar

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API, curl contra Docker)
- [x] Integración (verificación directa en BD tras cada escritura)
- [x] Regresión (suite completa)
- [x] Multi-tenancy (notaría 1 vs notaría 3)

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- MariaDB: 11.4 (contenedor `notarial-db`)
- Docker: corriendo (api, db, phpmyadmin)
- Autenticación: tokens JWT HS256 generados localmente con el `JWT_SECRET` de desarrollo (usuario 2 / notaría 1, usuario 4 / notaría 3, y uno expirado)
- Datos QA creados durante las pruebas fueron eliminados al terminar (tabla vuelve a 18 filas notaría 1 + 2 filas notaría 3)
