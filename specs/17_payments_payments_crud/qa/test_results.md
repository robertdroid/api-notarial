# Resultados de Pruebas: 17_payments_payments_crud

## Resumen
- **Estado:** APROBADO
- **Tests Ejecutados:** 865 unitarios + 70 casos de API/BD
- **Tests Pasados:** 865 + 70
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (865 tests, 1641 aserciones; 125 tests en `Payment` + `ClientUuid`; 240 con `PaymentTransaction`)
- **Cobertura:** no medida (no hay driver de cobertura configurado). Cubren `ClientUuid`, `PaymentFieldRules`, `Create/UpdatePaymentRequest`, `PaymentResponse`, `Payment`, `PaymentError` y `PaymentService` (21 tests, con `never()` en idempotencia).
- **Nota:** 1 deprecación de PHPUnit: el XML de configuración usa un esquema obsoleto (`--migrate-configuration`). Es de configuración, no de esta feature.

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Crear pago | PASS | 201 con `paymentId`, `uuid`, `clientId`, `clientName`, `amount`, `paymentDate`, `comments` |
| 2 | Crear sin fecha ni comentarios | PASS | 201, `paymentDate` = 2026-11-18 (hoy en México), `comments` = `""` |
| 3 | POST con uuid existente | PASS | 200 con el pago original; no cambió `amount`, fecha ni comentarios |
| 4 | Mismo uuid en otra notaría | PASS | 201, pago independiente (id 31 vs 29), sin exponer el otro |
| 5 | Cliente inexistente / de otra notaría | PASS | 404 `Client not found` en ambos casos |
| 6 | Cliente inactivo | PASS | 422 `Client is inactive` |
| 7 | GET por id | PASS | 200 con `clientName` |
| 8 | GET por uuid | PASS | 200; cada notaría ve solo su pago |
| 9 | Pago no encontrado (GET id, GET uuid, PUT) | PASS | 404 `Payment not found` |
| 10 | GET sin uuid | PASS | 400 `uuid query parameter is required` |
| 11 | PUT actualiza | PASS | 200; `uuid`, `clientId`, `paymentId` sin cambio |
| 12 | PUT omite campos | PASS | Conserva `paymentDate` y `comments` |
| 13 | PUT cambia uuid | PASS | 400 `Cannot change UUID` (también con solo cambio de mayúsculas) |
| 14 | PUT cambia clientId | PASS | 400 `Cannot change client` |
| 15 | PUT reenvía uuid y clientId iguales | PASS | 200 |
| 16 | Monto ≤ 0 | PASS | 400 `Validation failed` con `errors.amount = "Amount must be greater than zero"` en POST y PUT |
| 17 | Aislamiento multi-tenant | PASS | GET/PUT de otra notaría y de la 999 → 404; el pago de la notaría 1 no cambió |
| 18 | PUT con cliente inactivo | PASS | 200 |
| NF | Rendimiento < 500 ms | PASS | GET por id 0.14 s, por uuid 0.16 s |
| NF | Concurrencia | PASS | 10 POST paralelos: 1×201 + 9×200, 1 sola fila en BD |
| Edge 1-8 | uuid de 20 / 21 / vacío / espacios; fechas pasada y futura; `paymentDate` y `comments` null legacy; mayúsculas; 3 decimales | PASS | uuid 20 → 201; 21, vacío y espacios → 400; `paymentDate` null → `null`; `comments` null → `""`; `qa17I` y `QA17I` son pagos distintos; `10.123` se devuelve sin redondear |
| Rechazo | Ninguno de los 5 criterios de rechazo ocurre | PASS | Ver multi-tenancy y escenarios 3, 6, 13, 14, 16 |

### Técnicos
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| Controller | 4 rutas con `auth`; `GET /payments` → `showByUuid`; 400 `Invalid uuid format` (formato y `uuid[]`); `Invalid ID format` / `id<=0` → 404; `match` por `httpStatus()` | PASS | Verificado en código y por API; `notaryId` viene de `$request->getNotaryId()` |
| Service | Busca uuid antes de validar cliente; reintento con cliente inactivo → 200; carrera 1062 → recarga; `LogicException` si falla la recarga; PUT sin validar cliente activo; fusión de omitidos/`null` | PASS | `PaymentServiceTest` con `never()`; verificado por API (reintento con cliente inactivo → 200) |
| Service | Sin `TransactionManager`; no conoce HTTP | PASS | `grep` sin `beginTransaction`/`TransactionManager` en el módulo; usa `CreatePaymentResult` |
| Repository | Filtro por `id_notary` y `JOIN` con `c.id_notary = p.id_notary`; `WHERE uuid` sin funciones; 1062 por `errorInfo[1]`; `update` no toca uuid/cliente/notaría; `NULL` si no hay fecha; prepared statements | PASS | Revisión de `PaymentRepository.php`; fila legacy sin fecha sigue en `null` tras PUT |
| Entity/DTO | `Payment` readonly con `fromRow()`; `amount` solo int/float, `> 0`, `<= 999999999.99`, string rechazado; fecha con round-trip; `PaymentResponse` sin `notaryId` | PASS | `"5000"` → 400; `1000000000` → 400; `2024-02-30` y `2024-1-5` → 400 |
| `ClientUuid` | `final`, `DEFAULT_LENGTH=6`, `MAX_LENGTH=20`, regex `^[A-Za-z0-9_-]{1,20}$`; `TransactionFieldRules::uuid()` delega; spec 11 en verde | PASS | `tests/Module/PaymentTransaction` sin regresiones |
| BD | `payments_payments` InnoDB, `UNIQUE uq_payments_payments_notary_uuid`, índice `idx_payments_payments_notary_client`; migración con pre-checks, UP y DOWN comentado | PASS | Aplicada en dev; `SHOW CREATE TABLE` confirma esquema |
| BD | Pre-checks A–D de producción ejecutados y documentados | PENDIENTE (compuerta de deploy) | No ejecutables en local; no hay resultados documentados. Ver Conclusión |
| Tests | Unit tests de las clases indicadas; 18 escenarios con dos notarías | PASS | Ver secciones anteriores |
| Código | PSR-12, `strict_types`, clases `final`, sin código muerto ni TODO | PASS | Ningún archivo sin `strict_types`; sin `TODO`/`var_dump` |
| Seguridad | JWT en los 4 endpoints; 404 y nunca 403 entre notarías; uuid validado por regex antes de la BD | PASS | Sin token → 401 en GET, POST y PUT |
| Documentación | OpenAPI con paths y schemas | PASS | `/payments` y `/payments/{id}` con `PaymentResponse`, `CreatePaymentRequest`, `UpdatePaymentRequest` |

## Pruebas de API

### POST /api/v3/payments
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Crear completo | clientId 5, uuid, amount, fecha, comments | 201 | 201 | PASS |
| Sin fecha/comentarios | clientId 5, uuid, amount | 201, hoy, `""` | 201, 2026-11-18, `""` | PASS |
| Reintento uuid con otros datos | mismo uuid, amount 1 | 200 original | 200 original | PASS |
| Mismo uuid, otra notaría | token notaría 3, clientId 15 | 201 | 201 | PASS |
| Cliente inexistente / de otra notaría | 99999 / 15 con notaría 1 | 404 | 404 / 404 | PASS |
| Cliente inactivo | clientId 1 | 422 | 422 | PASS |
| amount 0 / -5 / `"5000"` / 1000000000 | — | 400 | 400 en los 4 | PASS |
| uuid 20 chars | — | 201 | 201 | PASS |
| uuid 21 chars / vacío / espacios / con espacio interno | — | 400 | 400 en los 4 | PASS |
| clientId ausente / 0 | — | 400 | 400 / 400 | PASS |
| Fecha `2024-02-30` / `2024-1-5` | — | 400 | 400 / 400 | PASS |
| Fecha 2099-12-31 / 1999-01-01 | — | 201 | 201 / 201 | PASS |
| 3 decimales | 10.123 | 201 sin redondeo | 201, 10.123 | PASS |
| Mayúsculas distintas | `qa17I` y `QA17I` | 201 y 201 | 201 y 201 | PASS |
| comments numérico | `123` | 400 | 400 | PASS |
| Sin token | — | 401 | 401 | PASS |
| JSON inválido | `{bad` | 400 | 400 (errores de campos requeridos) | PASS |
| 10 POST paralelos mismo uuid | — | 1×201 + 9×200, 1 fila | 1×201 + 9×200, 1 fila | PASS |

### GET /api/v3/payments/{id} y ?uuid=
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Por id | 29 | 200 | 200 | PASS |
| Por uuid | `qa17A` | 200 | 200 | PASS |
| Id inexistente | 999999 | 404 | 404 | PASS |
| Id de otra notaría / notaría 999 | 29 | 404 | 404 / 404 | PASS |
| Id `abc` | — | 400 `Invalid ID format` | 400 | PASS |
| Id 0 / -1 | — | 404 | 404 / 404 | PASS |
| Uuid inexistente | `nope` | 404 | 404 | PASS |
| Sin uuid | — | 400 | 400 | PASS |
| `uuid[]=x`, `a b`, vacío | — | 400 `Invalid uuid format` | 400 en los 3 | PASS |
| Fila legacy con nulls | — | `paymentDate: null`, `comments: ""` | igual | PASS |
| Sin token | — | 401 | 401 | PASS |

### PUT /api/v3/payments/{id}
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Actualizar todo | amount, fecha, comments | 200 | 200 | PASS |
| Omitir fecha y comentarios | solo amount | 200 y conserva | 200, conserva | PASS |
| `comments: null` | — | conserva | conserva | PASS |
| `comments: ""` | — | vacía | vacía | PASS |
| Cambiar uuid / solo mayúsculas | — | 400 `Cannot change UUID` | 400 / 400 | PASS |
| Cambiar clientId | 7 | 400 `Cannot change client` | 400 | PASS |
| Reenviar uuid y clientId iguales | — | 200 | 200 | PASS |
| amount 0 / negativo / ausente | — | 400 | 400 en los 3 | PASS |
| Fecha inválida | `2024-13-01` | 400 | 400 | PASS |
| Id inexistente / de otra notaría | — | 404 | 404 / 404 | PASS |
| Id `abc` | — | 400 | 400 | PASS |
| Fila legacy sin fecha | solo amount | 200, `null` | 200, `null` | PASS |
| Cliente inactivo | — | 200 | 200 | PASS |
| Sin token | — | 401 | 401 | PASS |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| GET por id de otra notaría | PASS | 404, nunca 403 |
| PUT por id de otra notaría | PASS | 404; el pago original no cambió (monto 5700 intacto) |
| GET por uuid con el mismo uuid en dos notarías | PASS | Cada notaría ve solo el suyo (ids 29 y 31) |
| POST con el mismo uuid en otra notaría | PASS | 201, pago independiente |
| POST con cliente de otra notaría | PASS | 404 `Client not found` |
| Notaría sin datos (999) | PASS | 404 en GET por id |

## Conclusión
La feature cumple los 18 escenarios del PO, los casos edge y los criterios técnicos, y no se encontraron bugs. La idempotencia por uuid y la protección contra carrera se comportan como se especificó, no hay acceso cruzado entre notarías y la suite completa (incluido spec 11) pasa sin regresiones.

Observaciones, ninguna bloquea la aprobación:
1. **Compuerta de deploy:** los pre-checks A–D de producción (tarea 0.1) deben ejecutarse y documentarse antes de aplicar `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql` en producción. Si v1 inserta filas con uuid vacío o repetido, el índice único fallará.
2. La deprecación de PHPUnit viene del esquema XML de `phpunit.xml.dist` y es previa a esta feature.
3. Un body que no es JSON válido responde 400 con los errores de campos requeridos, no con un mensaje de JSON inválido. Es el comportamiento común del framework.

## Fecha
2026-11-18
