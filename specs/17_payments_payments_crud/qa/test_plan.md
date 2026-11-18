# Plan de Pruebas: 17_payments_payments_crud

## Alcance
CRUD de pagos (`payments_payments`): `POST /api/v3/payments` (idempotente por uuid), `GET /api/v3/payments/{id}`, `GET /api/v3/payments?uuid=`, `PUT /api/v3/payments/{id}`. Incluye la utilidad compartida `ClientUuid` y la delegación desde `TransactionFieldRules::uuid()`.

Fuera de alcance: listado de pagos, borrado, redondeo de montos.

## Documentos base
- `po/acceptance_criteria.md` (18 escenarios, validaciones, casos edge, criterios de rechazo)
- `arch/technical_acceptance_criteria.md` (Controller, Service, Repository, DTO, `ClientUuid`, BD, tests, seguridad)

## Tipos de pruebas
| Tipo | Detalle |
|------|---------|
| Unitarias | Suite completa PHPUnit (865 tests) + `tests/Module/Payment`, `tests/Shared/Uuid`, `tests/Module/PaymentTransaction` (regresión spec 11) |
| API funcional | curl contra `localhost:8080`: happy path, validaciones, casos edge, 401 |
| Escenarios de datos | Filas legacy insertadas directamente en BD: `payment_date` y `comments` en `NULL`; pago cuyo cliente está inactivo (esc. 18 y reintento idempotente) |
| Concurrencia | 10 POST paralelos con el mismo uuid |
| Multi-tenancy | Notaría 1 vs 3 (mismo uuid en ambas) y notaría 999 sin datos |
| Revisión de código | Capas, `final`/`strict_types`, prepared statements, sin transacciones en el módulo, `PaymentResponse` sin `notaryId`, rutas, bootstrap, OpenAPI |
| Esquema | `SHOW CREATE TABLE payments_payments` (InnoDB, UNIQUE `(id_notary, uuid)`, índice `(id_notary, id_client)`) |
| Rendimiento | Tiempo de GET por id y por uuid |
| Seguridad | Sin token (401), notaría cruzada (404, nunca 403), uuid como array |

## Entorno
Docker local (api, MariaDB 11.4). Los JWT se generaron con el secreto local de la app (solo dev) para las notarías 1, 3 y 999. Los datos de prueba usan el prefijo de uuid `qa17` y se eliminaron al terminar; la BD quedó con las mismas filas del inicio (notaría 1: 15, notaría 3: 1).

## Limitaciones
- Los pre-checks A–D de producción (tarea 0.1) no se pueden ejecutar desde el entorno local; quedan como compuerta del deploy de la migración.
