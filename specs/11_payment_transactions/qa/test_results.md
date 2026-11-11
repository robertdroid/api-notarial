# Resultados de Pruebas: Payment Transactions CRUD

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests Ejecutados:** 430 unitarios + ~95 requests funcionales/integración
- **Tests Pasados:** todos
- **Tests Fallidos:** 0
- **Bugs Encontrados:** 0 (3 observaciones no bloqueantes, ver abajo)

## Pruebas Unitarias
- **Resultado:** PASS
- **Comando:** `docker exec notarial-api ./vendor/bin/phpunit` → `OK (430 tests, 797 assertions)`, 1 deprecation preexistente
- **Cobertura:** no medida (sin driver). Existen los tests requeridos por el arquitecto (TransactionManager, Entity, Error, FieldRules, DetailsParser, Create/Update/Response, Service).

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Crear sin detalles | PASS | POST proc 2 (modo manual) → 201, `total` 5000, `clientName`/`procedureName` expandidos |
| 2 | Crear con detalles | PASS | fee 3000 + (1×500) + (2×250) → `taxes` 1000, `total` 4000, `taxName` expandido (`taxes: 999` del request ignorado) |
| 3 | Idempotencia por uuid | PASS | POST repetido con datos distintos → 200, misma transacción, sin cambios; también tras desactivar al cliente → 200 |
| 4 | Cliente inexistente/ajeno | PASS | 404 `Client not found` (id 9999 y cliente de notaría 3) |
| 5 | Cliente inactivo | PASS | 422 `Client is inactive` |
| 6 | Procedimiento inexistente/ajeno | PASS | 404 `Procedure not found` |
| 7 | Procedimiento inactivo | PASS | 422 `Procedure is inactive` |
| 8 | Impuesto inactivo ignorado | PASS | POST con tax 2 inactivo → 201, solo tax 1 guardado |
| 9 | Impuesto de otra notaría ignorado | PASS | tax 9 (notaría 3) ignorado, 201 |
| 10 | taxId repetido | PASS | Se conserva el primero (amount 10, no 99) |
| 11 | GET por id | PASS | 200 con detalles y nombres |
| 12 | GET por uuid | PASS | 200, un objeto |
| 13 | No encontrada / ajena | PASS | 404 `Transaction not found` con token de otra notaría (GET id, GET uuid, PUT) |
| 14 | PUT reemplaza detalles | PASS | 200, detalles reemplazados, totales recalculados |
| 15 | PUT no cambia clientId | PASS | 400 `Cannot change client` |
| 16 | PUT no cambia uuid | PASS | 400 `Cannot change UUID` |
| 17 | PUT cambia procedureId | PASS | 200 (calculado→calculado y calculado→manual) |
| 18 | Atomicidad | PASS | Tabla de detalles renombrada: POST → 500 y 0 filas nuevas (51→51); PUT → 500 y cabecera/detalles previos intactos |
| 19 | Negativos rechazados | PASS | 400 `Validation failed`: `fee`, `details.0.amount` (ADR-005) |
| 20 | quantity < 1 | PASS | 400 `details.0.quantity: Detail quantity must be at least 1` |
| E | Casos edge | PASS | uuid de 20 chars → 201; 21 o vacío → 400; fecha inexistente → 400; `details: []` en PUT borra todo; sin detalles → total = fee |
| R | Criterios de rechazo | PASS | Ninguno ocurre |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Ambas tablas InnoDB | PASS | `SHOW TABLE STATUS` |
| 2 | Índices | PASS | `uq_payments_transactions_notary_uuid` (Non_unique 0), `idx_payments_transactions_notary_client`, PK `(id_transaction, id_tax)` |
| 3 | 0 uuids duplicados / 0 huérfanos | PASS | Consultas de verificación = 0 |
| 4 | Migraciones en `.db/applied/` | PASS | Ambas presentes |
| 5 | GET por uuid usa el índice único | PASS | EXPLAIN: `type=const`, key `uq_payments_transactions_notary_uuid` |
| 6 | GET sin uuid / uuid inválido | PASS | 400 `uuid query parameter is required` / `Invalid uuid format` (vacío, 21 chars, espacio) |
| 7 | `{id}` inválido | PASS | `abc` → 400 `Invalid ID format`; `0` → 404 |
| 8 | Auth en las rutas | PASS | Sin token → 401 (GET, POST, PUT) |
| 9 | DELETE/PATCH | PASS | 405 |
| 10 | Tipos estrictos | PASS | `clientId: "5"` → 400 |
| 11 | Modo manual | PASS | taxes del request guardado; detalles ignorados |
| 12 | PUT: cliente inactivo OK | PASS | 200 |
| 13 | PUT: impuesto inactivo ya existente se conserva; nuevo inactivo se ignora | PASS | tax 2 conservado (60), tax 3 nuevo ignorado → taxes 80 |
| 14 | Concurrencia mismo uuid | PASS | 6 POST simultáneos: un 201 y cinco 200; 1 fila, 1 detalle |
| 15 | Multi-tenant mismo uuid | PASS | Notaría 3 crea con uuid de notaría 1 → 201 |
| 16 | uuid sensible a mayúsculas | PASS | `abc` y `ABC` → filas distintas |
| 17 | Rendimiento | PASS | ~250 ms por GET (primer request en frío 700 ms) |
| 18 | OpenAPI | PASS | 4 operaciones documentadas |

## Observaciones (no bloqueantes)
1. **Doc del arquitecto contradictoria:** el encabezado de `technical_acceptance_criteria.md` (ADR-002) dice que en modo manual los detalles dan 422, pero el ADR-002 del plan y los criterios del Service dicen "ignorar silenciosamente". La implementación sigue lo segundo (201, detalles ignorados). Recomendado corregir el encabezado.
2. **Mensaje 500 con detalle SQL:** ante un fallo interno la respuesta incluye el mensaje de PDOException (nombre de tabla). Aceptable en local; verificar que producción tenga debug apagado.
3. **Interoperabilidad con v1** (crear/editar en v1 tras la migración) no se pudo probar en este ambiente; verificar manualmente antes de aplicar la migración en producción.

## Conclusión
La implementación cumple los criterios del PO y los técnicos. Atomicidad, idempotencia (incluida la carrera concurrente) y multi-tenancy verificados. Los datos de prueba se eliminaron de la BD local (40 transacciones, estado original). **Se aprueba.**

## Fecha de Validación
2026-11-11
