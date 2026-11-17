# Resultados de Pruebas: 16_payment_procedures_taxes_list

## Resumen
- **Estado:** APROBADO
- **Tests Ejecutados:** 740 unitarios (77 del módulo PaymentProcedureTax) + 6 llamadas API + 17 comparaciones de coherencia + 10 escenarios de datos
- **Tests Pasados:** todos
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (`OK, but there were issues!`: 740 tests, 1413 assertions, 1 PHPUnit Deprecation)
- **Cobertura:** no medida (sin Xdebug/PCOV configurado)
- La deprecation aparece en la suite completa; no es específica de esta feature.

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Procedimiento con impuestos incluye `taxId`, `taxName`, `defaultValue` | PASS | Proc. 8 (5 taxes), proc. 5 (3 taxes) |
| 2 | Procedimiento sin impuestos → `taxes: []` | PASS | 14 de 17 procedimientos de la notaría 1 |
| 3 | Relación `id_status = 2` no aparece | PASS | Tablas temporales: tax 5 desaparece, procedimiento se conserva |
| 4 | Impuesto inactivo en catálogo no aparece | PASS | Tablas temporales: tax 4 desaparece, procedimiento se conserva |
| 5 | Procedimiento inactivo no aparece | PASS | Datos reales: 11, 28, 29 ausentes; también con tablas temporales |
| 6 | Aislamiento multi-tenant | PASS | Ver sección Multi-tenancy |
| 7 | Notaría sin procedimientos | PASS | Notaría 999 → 200 `[]` (ADR-001, array plano) |
| 8 | Sin autenticación | PASS | Sin token → 401; token inválido → 401 |
| NF | Una sola consulta, camelCase, DTOs, orden determinista | PASS | Ver técnicos |
| Edge | Único impuesto inactivo → `taxes: []` | PASS | Por relación y por catálogo |
| Edge | `defaultValue` 0 no se omite | PASS | Notaría 3, proc. 13 → `"defaultValue": 0` |
| Edge | `/taxes` no se interpreta como `/{id}` | PASS | Ruta devuelve la lista, no 400/404 |

### Técnicos
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Ruta registrada antes de `/{id}` con middleware `auth` | PASS | `routes.php:74` antes de `:75` |
| 2 | `POST /payment-procedures/taxes` → 405 | PASS | |
| 3 | Controller usa `getNotaryId()`, sin capturar excepciones | PASS | Revisión de código |
| 4 | Service delega al repository, constructor sin cambios | PASS | Sin cambios en `ServiceLocator` |
| 5 | Repository: 1 query, LEFT JOINs con filtros en `ON`, `:notaryId`/`:notaryId2`, `pt.id_tax` NULL descarta, no usa la vista | PASS | Revisión de código + escenarios de datos |
| 6 | Entity `final` readonly; DTO con forma exacta, `taxes` siempre lista | PASS | `[]` serializado como `[]`; sin `procedureId`/`status` dentro de taxes |
| 7 | `defaultValue` numérico sin formato forzado (ADR-004) | PASS | `1200`, `200`, `0` |
| 8 | Orden sin distinguir mayúsculas/acentos | PASS | Real: `Cancelación` < `COMPRAVENTA` < `Constitución`; `Poder Amplísimo` < `Poder Especial`. Con `adjudicación` queda primero |
| 9 | Tests unitarios requeridos (DTO y Service) | PASS | Presentes y pasando |
| 10 | OpenAPI actualizado | PASS | Path `/payment-procedures/taxes` + schemas `ProcedureWithTaxesResponse`, `ProcedureTaxSummary` |
| 11 | `declare(strict_types=1)`, clases `final` (salvo Repository) | PASS | |
| 12 | Pre-check `id_status IS NULL` en relaciones | PASS | 0 filas. Además, una relación con NULL no se lista (comportamiento igual a `/{id}/taxes`) |

## Pruebas de API

### GET /api/v3/payment-procedures/taxes
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Notaría 1 | JWT notaría 1 | 200, 17 procedimientos activos | 200, 17 | PASS |
| Notaría 3 | JWT notaría 3 | 200, solo sus 2 procedimientos | 200, procs. 14 y 13 | PASS |
| Notaría sin datos | JWT notaría 999 | 200 `[]` | 200 `[]` | PASS |
| Sin token | — | 401 | 401 "Token no proporcionado" | PASS |
| Token inválido | `abc.def.ghi` | 401 | 401 "Token inválido o expirado" | PASS |
| Método no permitido | POST | 405 | 405 | PASS |

### Regresión
| Caso | Expected | Actual | Estado |
|------|----------|--------|--------|
| `GET /payment-procedures/5` | 200 | 200 | PASS |
| `GET /payment-procedures/abc` | 400 | 400 | PASS |
| `GET /payment-procedures/0` | 404 | 404 | PASS |
| `GET /payment-procedures` | 200 | 200 | PASS |
| `GET /payment-procedures/5/taxes` | 200 | 200 | PASS |
| Coherencia con `/{id}/taxes` (17 procedimientos) | mismos ids, orden y valores | 0 diferencias | PASS |

### Escenarios de datos (repository real, tablas temporales)
| Caso | Resultado | Estado |
|------|-----------|--------|
| Base: proc. 8 | 5 taxes | PASS |
| Relación inactiva (tax 5) | tax 5 ausente, proc. presente | PASS |
| Tax inactivo en catálogo (tax 4) | tax 4 ausente, proc. presente | PASS |
| Único tax inactivo (relación o catálogo) | proc. presente con `taxes: []` | PASS |
| Procedimiento inactivo | ausente | PASS |
| Tax movido a otra notaría con relación activa | no aparece en notaría 1 | PASS |
| Relación con `id_status` NULL | no se lista | PASS |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Notaría 1 no ve procedimientos de la 3 y viceversa | PASS | Conjuntos de procedimientos disjuntos |
| Notaría 3 solo ve sus taxes (IVA) | PASS | |
| Tax de otra notaría ligado a un procedimiento propio | PASS | No se filtra; el procedimiento se conserva |
| `notaryId` proviene solo del token | PASS | Se ignoran query params y body |

## Notas
- Las tablas involucradas son MyISAM (sin transacciones). Por eso los escenarios 3, 4 y edge se probaron con tablas temporales de sesión sobre el repository real; los datos locales no se modificaron.
- No se verificó el conteo de queries por request con un log de MariaDB; la garantía de una sola query se confirma por revisión de código (una sola `prepare`/`execute`, agrupación en PHP).

## Conclusión
La feature cumple los 8 escenarios del PO y los criterios técnicos (con los ajustes ADR-001 y ADR-004). Sin bugs abiertos ni resueltos pendientes de verificar. **APROBADO.**

## Fecha
2026-11-17
