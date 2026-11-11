# Plan de Pruebas: Payment Transactions CRUD

## Información General
- **Feature:** 11_payment_transactions
- **Rama/PR:** feature/11-payment-transactions / PR #10
- **QA:** Claude (QA)
- **Fecha:** 2026-11-11

## Alcance de Pruebas

### En Scope
- POST/PUT/GET (id y uuid) de `/api/v3/payment-transactions`
- Idempotencia por uuid, modos de taxes (manual/calculado), filtrado de detalles
- Atomicidad (POST y PUT), concurrencia, multi-tenancy
- Migraciones (InnoDB, índices, dedupe)

### Fuera de Scope
- Listado, anulación/eliminación, reportes, estado de cuenta, `payments_payments`
- Interoperabilidad con la app v1 (no disponible en el ambiente local)

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API)
- [x] Integración (BD, atomicidad, concurrencia)
- [x] Regresión (suite completa)
- [x] Multi-tenancy

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- MariaDB: 11.4 (contenedor `notarial-db`)
- Docker: corriendo
- Tokens JWT firmados con el secreto local para notarías 1 y 3
