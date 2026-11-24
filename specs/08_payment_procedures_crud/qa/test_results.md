# Resultados de Pruebas: Payment Procedures CRUD

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests Ejecutados:** 166 unitarios (suite completa, 43 del feature) + ~95 casos de API/BD
- **Tests Pasados:** todos
- **Tests Fallidos:** 0
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (`Tests: 166, Assertions: 329`, 1 deprecation preexistente de PHPUnit)
- **Feature:** `tests/Module/PaymentProcedure/` → 43 tests, 88 assertions (Entity, Create/Update/Response DTO, Service). Sin regresiones en otros módulos.
- **Cobertura:** no se mide (sin driver de cobertura); los casos requeridos por Arquitectura están todos cubiertos.

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Listar procedimientos | PASS | 200, 18 filas de notaría 1, orden `procedure_name ASC` |
| 2 | Filtro `status` | PASS | `status=1` → activos; `status=2` → solo id 11 |
| 3 | Filtro `name` (case-insens.) | PASS | `poder` / `PODER` → 3 "Poder…"; `subdivision` encuentra "SUBDIVISIÓN" (insensible a acentos) |
| 4 | Obtener por ID | PASS | `/1` → 200 `{procedureId, procedureName, status}` |
| 5 | ID de otra notaría | PASS | `/13` con token notaría 1 → 404 "Procedure not found", idéntico a inexistente (`/9999`) |
| 6 | Crear | PASS | 201 sin body; fila con `id_notary` del token y `id_status=1` |
| 7 | Nombre duplicado (case-insens.) | PASS* | `compraventa`, `"   compraventa   "`, y variante sin acento → 400 "Procedure name already exists" |
| 8 | Nombre vacío / ausente | PASS | `""`, `{}`, `null`, solo espacios → 400 "Procedure name is required" |
| 9 | Actualizar | PASS* | 204 sin body; `modified_at` actualizado |
| 10 | Desactivar (soft delete) | PASS* | `{"status":2}` → 204, fila con `id_status=2`; reactivar `{"status":1}` → 204 |
| 11 | PUT otra notaría | PASS | `/13` con token notaría 1 → 404; fila 13 intacta |
| 12 | Token ausente/inválido/expirado | PASS | 401 en GET, GET/id, POST y PUT |
| 13 | PUT nombre duplicado | PASS* | `Compraventa` y `" qa inactivo "` → 400 "Procedure name already exists" |
| NF | Índice `(id_notary, id_status)` | PASS | `SHOW INDEX` lo confirma; `EXPLAIN` con 27 filas hace full scan por decisión del optimizador, con `FORCE INDEX` usa `ref` sobre `idx_payment_procedures_notary_status` |
| NF | Formato consistente con otros catálogos | PASS | Mismo contrato que `payment-taxes` (201 sin body, 204 en PUT, 400 en duplicado) |
| Edge | Solo espacios | PASS | 400 |
| Edge | 501 caracteres / 500 caracteres | PASS | 501 → 400 "must not exceed 500 characters"; 500 → 201 |
| Edge | ID `abc`, `1.5`, `1e3` | PASS | 400 "Invalid ID format" (GET y PUT) |
| Edge | ID `0`, `-1`, `9999` | PASS | 404 "Procedure not found" |
| Edge | `status=3` / `status=abc` en listado | PASS | 400 "Invalid status value" |
| Rechazo | No expone `notaryId`, `createdAt`, `modifiedAt` | PASS | Respuesta solo `procedureId, procedureName, status` |
| Rechazo | Trim aplicado | PASS | `"  QA  Doble   espacio  "` guardado como `QA  Doble   espacio` (espacios internos conservados) |

\* **Discrepancia entre specs (no es bug):** `po/acceptance_criteria.md` indica 409 para duplicados y 200 para PUT, mientras `arch/technical_acceptance_criteria.md` (que prevalece y es lo implementado) indica 400 y 204. La implementación sigue el criterio técnico y es idéntica a `payment-taxes` y demás catálogos. Se recomienda alinear el documento del PO.

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | 4 rutas con middleware `auth` | PASS | `src/Config/routes.php:65-68` |
| 2 | `status` inválido → 400; `name` vacío ignorado | PASS | `?status=3` → "Invalid status value"; `?name=` devuelve todos |
| 3 | ID inválido → 400 "Invalid ID format"; ID ≤ 0 → 404 | PASS | Probado en GET y PUT |
| 4 | DELETE → 405 | PASS | Con y sin token |
| 5 | Comodines `%`, `_`, `!` escapados | PASS | `name=%` y `name=_` → `[]` |
| 6 | Unicidad insensible a mayúsculas/acentos; excluye propio ID en UPDATE | PASS | PUT mismo nombre y mismo nombre con otra capitalización → 204 |
| 7 | Emoji → 400 (no 500) | PASS | `😀` → 400 "unsupported characters" |
| 8 | `notaryId` en body ignorado | PASS | POST y PUT con `notaryId:3` → fila permanece en notaría 1 |
| 9 | Queries con `id_notary` y prepared statements | PASS | Revisión de `PaymentProcedureRepository` |
| 10 | Entity `final`, readonly, `fromRow()`, default status 1 | PASS | `PaymentProcedure.php` |
| 11 | Response DTO sin campos internos | PASS | `PaymentProcedureResponse.php` |
| 12 | Service sin PDO; sin dependencias entre módulos | PASS | grep sin hallazgos |
| 13 | `declare(strict_types=1)`; sin `var_dump`/`print_r`/`echo`/`die` | PASS | grep sin hallazgos |
| 14 | Migración con UP/DOWN, aplicada en `.db/applied/`; `pending/` vacío | PASS | `20261110_150000_add_notary_status_index_to_payment_procedures.sql` |
| 15 | OpenAPI: tag, paths, schemas, respuestas 200/201/204/400/401/404, query params | PASS | `docs/openapi.yaml` (405 documentado en la descripción del PUT) |
| 16 | Tests requeridos por Arquitectura | PASS | 43 tests, todos los casos listados presentes |

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Acceso a datos propios | PASS | Notaría 3 lista solo sus 2 procedimientos |
| GET de registro ajeno | PASS | 404 idéntico a "no existe" |
| PUT de registro ajeno | PASS | 404; fila ajena sin cambios |
| Mismo nombre en otra notaría | PASS | "QA Nuevo trámite" creado en notaría 3 aunque existía en notaría 1 |
| `notaryId` en body | PASS | Ignorado; se usa el del token |

## Observaciones (no bloquean)
- Discrepancia PO vs Arquitectura en códigos de duplicado/PUT (ver nota arriba).
- Los datos existentes contienen un duplicado legado ("RATIFICACION", ids 11 y 12) previo a la validación de unicidad.
- Los mensajes 401 están en español (ADR-005, fuera de alcance), igual que en features previos.
- Un body con JSON inválido o sustituto UTF-16 aislado responde 400 con el mensaje genérico "Procedure name is required" (comportamiento del `Request` compartido).
- Nota de método: en Git Bash/Windows un emoji o acento literal en `curl -d` llega como `??`/bytes inválidos; los casos Unicode se verificaron con escapes JSON `\uXXXX` desde archivo. Un primer resultado aparentemente anómalo (201 con emoji) fue un artefacto de esto y se descartó al repetirlo con escapes.

## Conclusión
La implementación cumple los 13 escenarios del PO, los casos edge y todos los criterios técnicos, sin bugs ni regresiones. Se aprueba y se mergea el PR #7.

## Fecha de Validación
2026-11-10
