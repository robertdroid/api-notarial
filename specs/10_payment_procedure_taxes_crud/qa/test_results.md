# Resultados de Pruebas: Payment Procedure Taxes CRUD

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests Ejecutados:** 311 unitarios + ~85 requests funcionales/API
- **Tests Pasados:** todos
- **Tests Fallidos:** 0
- **Bugs Encontrados:** 0 (3 observaciones menores no bloqueantes, ver abajo)

## Pruebas Unitarias
- **Resultado:** PASS
- **Comando:** `docker exec notarial-api ./vendor/bin/phpunit` → `OK (311 tests, 567 assertions)`, 1 deprecation de PHPUnit (preexistente)
- **Cobertura:** no medida (sin driver de cobertura configurado); los tests requeridos por el arquitecto existen para Entity, DefaultValueRule, AddProcedureTaxesRequest, UpdateProcedureTaxRequest, ProcedureTaxResponse, ProcedureTaxError y Service

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Listar impuestos activos con nombre y default | PASS | GET /5/taxes → 200 `[{procedureId,taxId,taxName,defaultValue}]` |
| 2 | Agregar un impuesto (default omitido/neg/0 → 0) | PASS | POST sin valor, `-50`, `0`, `null` → 201; BD guarda 0 |
| 3 | Bulk | PASS | bulk de 3 items → 201; 3 filas activas |
| 4 | Rehabilitar impuesto deshabilitado | PASS | DELETE + POST → 201; fila única, status=1, nuevo valor 55, `created_at` conservado |
| 5 | Modificar default (≤0 → 0) | PASS | PUT 777 → 204 y BD=777; PUT -3 → 204 y BD=0 |
| 6 | Soft delete | PASS | DELETE → 204; `id_status=2`; COUNT total sin cambios (20 → 20) |
| 7 | Procedimiento inexistente/ajeno → 404 | PASS | 4 endpoints con id 9999 y con token de otra notaría → 404 `Procedure not found` |
| 8 | Impuesto inexistente/ajeno → 404 | PASS | POST/PUT con taxId 99999 y 9 (notaría 3) → 404 `Tax not found` |
| 9 | Impuesto ya activo → 409 | PASS | POST duplicado → 409 `Tax is already assigned to this procedure` |
| 10 | Impuesto inactivo → 422 | PASS | POST con tax inactivo → 422 `Tax is inactive` |
| 11 | Procedimiento inactivo → 422 | PASS | POST/PUT en procedimiento 11 → 422 `Procedure is inactive` |
| 12 | PUT/DELETE de impuesto no asignado o status=2 → 404 | PASS | PUT/DELETE sobre taxId 7/8 y sobre uno ya deshabilitado → 404 |
| E1 | Bulk con duplicados → 400 | PASS | `errors["taxes.1.taxId"]: Duplicate tax ID in request` |
| E2 | Bulk parcial inválido → 400 y nada insertado | PASS | items 1 y 2 inválidos reportados juntos; `COUNT(*)` del procedimiento = 0 antes y después |
| E3 | Procedimiento sin impuestos → `[]` | PASS | GET /20/taxes → 200 `[]` |
| E4 | defaultValue muy grande | PASS | 999999999999.99 aceptado y devuelto |
| R | Criterios de rechazo (inactivos en listado, cross-tenant, borrado físico, bulk parcial) | PASS | Ninguno ocurre |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Migración con índice UNIQUE aplicada y en `.db/applied/` | PASS | `SHOW INDEX`: `uq_payment_procedure_taxes_procedure_tax`, Non_unique=0, `(id_procedure,id_tax)`; `.db/pending` vacío |
| 2 | `Response::conflict` / `unprocessable` | PASS | Existen; 409/422 observados con `{ "error": ... }` |
| 3 | POST → 201 sin body; PUT → 204; GET array plano | PASS | Body vacío en 201/204 |
| 4 | GET de procedimiento inactivo permitido | PASS | GET /11/taxes → 200 |
| 5 | GET excluye taxes inactivos en catálogo (ADR-006) | PASS | tax 5 desactivado → GET `[]` |
| 6 | PUT con tax inactivo → 422; DELETE con tax inactivo → 204 | PASS | Observado |
| 7 | IDs: no entero → 400; ≤0 → 404 | PASS | `/abc/taxes` → 400 `Invalid ID format`; `/0/taxes` → 404; `/taxes/abc` → 400 `Invalid tax ID format`; `/taxes/0` → 404 |
| 8 | Bulk reporta todos los errores por índice | PASS | `taxes.1.taxId` y `taxes.2.taxId` en una sola respuesta |
| 9 | Validaciones DTO (tipos, ambos/ninguno, vacío, objeto, >50) | PASS | Todos 400 con mensajes del catálogo |
| 10 | Sin rutas extra (PATCH, GET single) | PASS | 405 |
| 11 | 4 rutas con auth | PASS | Sin token → 401 |
| 12 | `declare(strict_types=1)` en todos los archivos | PASS | Verificado con grep |
| 13 | Service sin imports de `Core\Http`; excepciones `DomainException` | PASS | grep sin resultados |
| 14 | Sin `DELETE FROM` físico | PASS | grep sin resultados; COUNT sin cambios |
| 15 | No se modifican Controller/Service de PaymentProcedure ni PaymentTax | PASS | `git diff origin/develop...HEAD` vacío para esas rutas (sólo cambia `PaymentTaxRepository`, previsto) |
| 16 | Tests unitarios requeridos | PASS | Presentes y en verde |
| 17 | Respuesta exacta `{procedureId,taxId,taxName,defaultValue}` | PASS | Sin `status` |
| 18 | Orden alfabético por nombre | PASS | Respuestas ordenadas por `taxName` |

## Pruebas de API

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| GET sin token (×4 métodos) | — | 401 | 401 | PASS |
| GET vacío | /20/taxes | 200 `[]` | 200 `[]` | PASS |
| POST single válido | `{taxId:1}` | 201 | 201 | PASS |
| POST duplicado | `{taxId:1}` | 409 | 409 | PASS |
| POST tax ajeno / inexistente | 9 / 99999 | 404 | 404 | PASS |
| POST tax inactivo | 19 | 422 | 422 | PASS |
| POST procedimiento inactivo | /11 | 422 | 422 | PASS |
| POST taxId string/negativo | `"1"`, -1 | 400 | 400 | PASS |
| POST defaultValue string | `"10"` | 400 | 400 | PASS |
| POST ambos / ninguno | — | 400 `errors.body` | 400 | PASS |
| Bulk OK (nuevo) | 3 items | 201 | 201 | PASS |
| Bulk mixto (rehab + nuevo) | taxId 2 (deshab.) + 8 | 201, ambos activos | 201, ambos activos | PASS |
| Bulk con existente activo | — | 400 por índice | 400 `taxes.1.taxId` | PASS |
| Bulk vacío / objeto / item no objeto / 51 | — | 400 | 400 | PASS |
| PUT válido / negativo | 777 / -3 | 204; BD 777 / 0 | 204; 777 / 0 | PASS |
| PUT sin valor / string | — | 400 | 400 | PASS |
| PUT no asignado / tax 404 / ajeno | — | 404 | 404 | PASS |
| PUT procedimiento inactivo | /11 | 422 | 422 | PASS |
| DELETE OK / repetido / nunca asignado | — | 204 / 404 / 404 | 204 / 404 / 404 | PASS |
| PATCH, GET /taxes/{id} | — | 405 | 405 | PASS |

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Acceso a datos propios (notaría 3, proc. 13) | PASS | GET → 200 con su impuesto |
| Procedimiento de notaría 1 con token de notaría 3 (GET/POST/PUT/DELETE) | PASS | 404 en los 4, idéntico a inexistente |
| Tax de notaría 1 en procedimiento de notaría 3 (single) | PASS | 404 `Tax not found` |
| Tax de notaría 1 en procedimiento de notaría 3 (bulk) | PASS | 400 con `taxes.1.taxId: Tax not found` |

## Pruebas de Integración / Concurrencia
- **Atomicidad del bulk:** bulks inválidos no insertan filas (COUNT = 0).
- **Soft delete / rehabilitación:** sin borrados físicos; `created_at` conservado.
- **Doble POST simultáneo:** 10 POST paralelos del mismo par → 2×201 y 8×409, **una sola fila** en BD (el UNIQUE + `ON DUPLICATE KEY UPDATE` garantiza integridad).

## Observaciones (no bloqueantes, sin bug)
1. Dos POST concurrentes pueden devolver ambos 201 (carrera entre la verificación de estado y el upsert). La integridad de datos no se ve afectada (una fila); es inherente al diseño elegido (ADR) y se considera aceptable.
2. JSON inválido en el body devuelve 400 `errors.body: Either taxId or taxes is required` (mensaje poco específico, pero código correcto).
3. El middleware `auth` responde `"Token no proporcionado"` en español; es comportamiento preexistente del módulo Auth, fuera del alcance de este feature (convención del proyecto: errores en inglés). Sugerido para un ticket aparte.

## Conclusión
La implementación cumple los 12 escenarios y casos edge del PO y todos los criterios técnicos verificados. Multi-tenancy, atomicidad del bulk, soft delete y rehabilitación se comportan según lo especificado, sin regresiones en los endpoints vecinos. **Recomendación: APROBAR y mergear PR #9.**

## Fecha de Validación
2026-11-10
