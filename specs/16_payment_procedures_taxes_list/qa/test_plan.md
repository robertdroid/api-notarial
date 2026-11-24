# Plan de Pruebas: 16_payment_procedures_taxes_list

## Alcance
`GET /api/v3/payment-procedures/taxes`: lista de procedimientos activos de la notaría con sus impuestos activos y `defaultValue`, en una sola consulta.

Fuera de alcance: filtros, paginación, endpoints de escritura.

## Documentos base
- `po/acceptance_criteria.md` (escenarios 1-8)
- `arch/technical_acceptance_criteria.md` (ADR-001 array plano, ADR-004 `defaultValue` numérico)

## Tipos de pruebas
| Tipo | Detalle |
|------|---------|
| Unitarias | Suite completa PHPUnit + `tests/Module/PaymentProcedureTax` |
| API funcional | curl contra `localhost:8080` con JWT de las notarías 1, 3 y 999 (sin datos) |
| Escenarios de datos | Esc. 3, 4, 5, caso edge y cruce de tenant, ejecutados con el `PaymentProcedureTaxRepository` real sobre **tablas temporales de sesión** (las tablas son MyISAM, sin rollback). No se modificó ningún dato real |
| Multi-tenancy | Notaría 1 vs 3; tax de otra notaría ligado a un procedimiento |
| Regresión | `GET /payment-procedures`, `/{id}` (válido, `abc`, `0`), `/{id}/taxes` |
| Coherencia | Para cada procedimiento, `taxes` == `GET /{id}/taxes` (ids, orden, valores) |
| Seguridad | Sin token, token inválido, método no permitido |

## Entorno
Docker local (api, MariaDB 11.4). Los JWT de prueba se generaron con el secreto local de la app (solo entorno dev).
