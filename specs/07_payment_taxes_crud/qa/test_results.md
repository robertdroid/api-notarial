# Resultados de Pruebas: Payment Taxes CRUD

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests Ejecutados:** 123 unitarios (suite completa) + pruebas de API/BD por escenario
- **Tests Pasados:** todos
- **Tests Fallidos:** 0
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (`Tests: 123, Assertions: 241`, 1 deprecation preexistente de PHPUnit)
- **Detalles:** Incluye `tests/Module/PaymentTax/` (Entity, Create/Update/Response DTO, Service). Sin regresiones en otros módulos.

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Listar todos (200) | PASS | 8 impuestos de notaría 1, orden `tax_name ASC` |
| 2 | Filtro `status` | PASS | `status=1` → 8; `status=2` → `[]` |
| 3 | Filtro `name` parcial | PASS | `name=subdivision` → "Derechos Subdivisión", "Pago de Subdivisión" |
| 4 | Filtros combinados | PASS | `status=1&name=gastos` → Gastos |
| 5 | Obtener por ID | PASS | `/1` → 200 `{taxId,taxName,status}` |
| 6 | ID inexistente | PASS | 404 "Tax not found" |
| 7 | ID de otra notaría | PASS | `/9` (notaría 3) → 404 idéntico |
| 8 | Crear (default status 1) | PASS | 201 sin body; fila con `id_status=1` |
| 9 | Crear con status 2 | PASS | 201; fila con `id_status=2` |
| 10 | Nombre duplicado (case-insens.) | PASS | 400 "Tax name already exists" |
| 11 | Sin `taxName` | PASS | 400 "Tax name is required" |
| 12 | `taxName` vacío | PASS | 400 |
| 13 | > 500 caracteres | PASS | 501 → 400; 500 → 201 |
| 14 | Status inválido | PASS | `3` y `"1"` → 400 "Status must be 1 or 2" |
| 15 | Actualizar | PASS | 204 sin body, `modified_at` actualizado |
| 16 | Desactivar | PASS | `{"status":2}` → 204, fila en 2 |
| 17 | Reactivar | PASS | `{"status":1}` → 204 |
| 18 | PUT otra notaría | PASS | 404; fila de notaría 3 intacta |
| 19 | PUT nombre duplicado | PASS | 400 "Tax name already exists" |
| 20 | PUT mismo nombre | PASS | 204 (incluso cambiando mayúsculas) |
| 21 | Sin token | PASS | 401 en GET/POST/PUT |
| 22 | Token expirado | PASS | 401 (mensaje en español, fuera de alcance ADR-005) |
| 23 | Token inválido | PASS | 401 |
| NF | Índices id_notary/id_status | PASS | EXPLAIN usa `idx_payment_taxes_notary_status` |
| Edge | trim, caracteres especiales, id 0/negativo/no numérico | PASS | `"  gastos  "` duplicado; `QA-ISR (10%)` → 201; `/0`, `/-1` → 404; `abc`, `1.5`, `1e3` → 400 "Invalid ID format" |
| Rechazo | DELETE no existe | PASS | `DELETE /payment-taxes/1` → 405 |
| Rechazo | `notaryId` no expuesto ni aceptado | PASS | Respuesta solo `taxId,taxName,status`; `notaryId` en body ignorado en POST y PUT |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | 4 rutas con middleware `auth` | PASS | `src/Config/routes.php:58-61` |
| 2 | `status` inválido en listado → 400 | PASS | `status=3` → "Invalid status value" |
| 3 | `?name=` vacío ignorado | PASS | Devuelve los 8 |
| 4 | Comodines `%`/`_` escapados | PASS | `name=%` y `name=_` → `[]` |
| 5 | Unicidad completa, no parcial | PASS | "Subdivision" → 201 con "Derechos Subdivisión" existente |
| 6 | Unicidad insensible a acentos/mayúsculas | PASS | "derechos subdivision" → 400 |
| 7 | Emoji / no-BMP → 400, no 500 | PASS | `😀` en POST y PUT → 400 "unsupported characters" |
| 8 | Query siempre con `id_notary`; prepared statements | PASS | Revisión de `PaymentTaxRepository` |
| 9 | Service sin PDO; DTOs/Entity `final`; `strict_types` en todo | PASS | grep sin hallazgos |
| 10 | Migración con UP/DOWN e índice aplicado | PASS | `SHOW INDEX` confirma `(id_notary, id_status)` |
| 11 | OpenAPI actualizado | PASS | Tag, paths y schemas en `docs/openapi.yaml` |
| 12 | Sin `var_dump`/`print_r`; sin dependencias a otros módulos | PASS | grep sin hallazgos |

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Acceso a datos propios | PASS | Notaría 3 solo lista sus propios impuestos |
| GET/PUT de registro ajeno | PASS | 404 idéntico a "no existe"; `id_tax=9` sin cambios |
| Mismo nombre en otra notaría | PASS | "QA-ISR" creado en notaría 1 y 3 sin conflicto |
| `notaryId` en body | PASS | Ignorado; se usa el del token |

## Observaciones (no bloquean)
- Un body con bytes UTF-8 inválidos se rechaza con 400 pero con mensaje genérico "Tax name is required" (el parser JSON lo descarta). Comportamiento del `Request` compartido, no de este módulo.
- Los mensajes 401 están en español (ADR-005, fuera de alcance).
- Nota de método: en Git Bash/Windows un emoji literal en `curl -d` llega como `??`; se verificó con escapes JSON `\uXXXX`.

## Conclusión
La implementación cumple los 23 escenarios del PO y los criterios técnicos. Sin bugs; datos de prueba eliminados. Se recomienda aprobar y mergear el PR #6.

## Fecha de Validación
2026-11-10
