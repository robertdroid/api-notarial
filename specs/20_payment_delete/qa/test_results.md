# Resultados de Pruebas: 20_payment_delete

## Resumen
- **Estado:** APROBADO
- **Tests Ejecutados:** 989 unitarios (2 de la feature) + ~60 peticiones de API (incluye 24 `DELETE` concurrentes) + comparación de checksums de BD
- **Tests Pasados:** todos. 9/9 escenarios del PO en PASS; criterios técnicos en PASS
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (989 tests, 1939 aserciones)
- **Feature:** `PaymentServiceTest::testDeleteRemovesPayment` y `testDeleteThrowsNotFoundWhenNothingDeleted`
- **Cobertura:** no medida (no hay driver de cobertura configurado)
- **Nota:** 1 deprecación de PHPUnit por el esquema del XML de configuración. Ya existía antes de esta feature (specs 17 y 19).

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Eliminar un abono existente | PASS | `DELETE /payments/9102` → `204 No Content`, sin cuerpo (solo cabeceras); `COUNT(*)` en BD = 0 |
| 2 | El abono eliminado deja de consultarse | PASS | `GET /payments/9102` y `GET /payments?uuid=QA20B` → 404 `{"error":"Payment not found"}` |
| 3 | El estado de cuenta se recalcula | PASS | Cliente con abonos 5000 + 2000 + 100 + 50 + 77 (sin fecha) y cargo 10000: `totalPayments` 7227 / `balance` 2773. Tras eliminar el de 2000: lista sin él, `totalPayments` 5227, `balance` 4773. El cargo de otra notaría con el mismo `id_client` no influyó |
| 4 | Recibo PDF inexistente | PASS | Antes: ambos recibos → 200 `application/pdf`. Después: `/payments/9102/receipt` y `/payments/uuid/QA20B/receipt` → 404 |
| 5 | Abono inexistente | PASS | `DELETE /payments/99999` → 404 `Payment not found` |
| 6 | Abono de otra notaría | PASS | Tokens de notaría 3 y 2 sobre el abono 9107 de la notaría 1 → 404 `Payment not found` (idéntico a inexistente); el abono **sigue existiendo** en BD |
| 7 | Eliminar dos veces | PASS | Segundo `DELETE` del mismo id → 404 `Payment not found` |
| 8 | Cliente inactivo | PASS | Abono 9103 de cliente con `id_status = 2` → 204 y la fila desaparece |
| 9 | Sin autenticación | PASS | Sin token → 401 `Token no proporcionado`; `abc.def.ghi` → 401 `Token inválido o expirado` |

### Validaciones y casos edge del PO
| Criterio | Estado | Notas |
|----------|--------|-------|
| `abc`, `1.5`, `5abc` | PASS | 400 `Invalid ID format` |
| Entero desbordado (20 dígitos) | PASS | 400 `Invalid ID format`, sin 500 |
| `0` y `-1` | PASS | 404 `Payment not found` |
| Inyección en `{id}` (`9107 OR 1=1`, `9107' OR '1'='1`) | PASS | 400 `Invalid ID format`; el abono 9107 sigue existiendo |
| `notaryId` solo del JWT | PASS | No hay forma de enviarlo en el request; confirmado en código (`$request->getNotaryId()`) |
| Reintento de `POST` con uuid borrado | PASS | `POST /payments` con uuid `QA20B` → 201 con nuevo `paymentId` (9601). Riesgo aceptado por el PO, documentado en OpenAPI |
| `POST` sigue exigiendo cliente activo | PASS | Sin regresión: cliente inactivo → 422 `Client is inactive` |
| Abono legacy sin fecha (`payment_date` NULL) | PASS | 204 y se elimina |
| Eliminación concurrente | PASS | 6 rondas × 4 `DELETE` simultáneos sobre el mismo id: en cada ronda exactamente un 204 y tres 404; la fila queda eliminada |
| Último abono del cliente | PASS | Estado de cuenta responde 200 con `transactions: []`, `payments: []` y todo en 0 |
| Mismo `uuid` en otra notaría | PASS | `uuid` `QA20A` existía en notarías 1 (id 9101) y 2 (id 9201), ambas con `id_client` 9101. Borrar el de la notaría 1 no tocó el de la 2, que la notaría 2 sigue consultando (200) |

### No funcionales y criterios de rechazo
| Criterio | Estado | Notas |
|----------|--------|-------|
| Una sola sentencia, sin transacción | PASS | Revisado en Repository y Service: sin `beginTransaction` ni `TransactionManager` |
| `DELETE` filtra por `id_payment` **y** `id_notary` | PASS | Confirmado en código y por los escenarios 6 y de uuid homónimo |
| Responde `204` sin cuerpo (nunca `200`) | PASS | Cabeceras sin `Content-Length` ni `Content-Type`; cuerpo vacío |
| No se exige rol, estado de cliente ni antigüedad | PASS | Escenario 8; el token de prueba no lleva `features` |
| Sin migraciones, status ni auditoría nuevos | PASS | El diff de la rama contra `develop` no agrega SQL; el único cambio en `.db/` es el **traslado** de la migración de spec 17 de `pending/` a `applied/` (ya estaba aplicada), incluido en el commit de specs |
| No se modifican `payments_clients` ni `payments_transactions` | PASS | `CHECKSUM TABLE` idéntico a la línea base tras sembrar y limpiar (ver abajo) |

### Técnicos (arch)
| # | Área | Estado | Notas |
|---|------|--------|-------|
| Controller | `destroy` reutiliza `parseId()` y `errorResponse()`; `Response::noContent()` | PASS | 16 líneas; sin lógica de negocio ni SQL |
| Controller | Ruta con `auth`, sin colisión con `/receipt` | PASS | `DELETE /payments/{id}/receipt` y `/payments/uuid/{uuid}/receipt` → 405; `GET /payments/{id}/receipt` sigue en 200 `application/pdf` para un abono vivo (ambos recibos daban 200 antes del borrado). `GET` y `PUT /payments/{id}` siguen respondiendo 200 |
| Service | `delete(): void`, lanza `PaymentNotFound` si el repository retorna `false` | PASS | Sin validar cliente, sin `findByIdAndNotary` previo (ADR-001) |
| Repository | Prepared statement con parámetros nombrados; `rowCount() > 0`; docblock explica diferencia con `update` | PASS | |
| Entity/DTO | Sin Entity ni DTO nuevos; sin cambios en `bootstrap.php` | PASS | El diff solo toca `routes.php`, Controller, Service, Repository, test y OpenAPI |
| Tests | 2 casos de `delete` + suite completa | PASS | Éxito con `->with(ID, NOTARY)`, `once()`, y `never()` en `findByIdAndNotary` de ambos repositories; no encontrado → `PaymentNotFound` |
| Código | `declare(strict_types=1)`, tipos de retorno, `php -l` | PASS | Los 4 archivos sin errores de sintaxis |
| Seguridad | JWT requerido; 404 (no 403) entre notarías; parámetros enlazados | PASS | |
| Performance | `DELETE` por PK + `id_notary`, una sentencia | PASS | Sin N+1; paginación no aplica |
| Documentación | OpenAPI `deletePayment` | PASS | `204`, `400`, `401`, `404`; nota de borrado irreversible y uuid reutilizable. El YAML parsea con `js-yaml`, junto a `get` y `put` bajo `/payments/{id}`, sin `operationId` duplicados y con `$ref` resueltos |

## Pruebas de API

Todas las llamadas fueron contra `http://localhost:8080/api/v3` con tokens de las notarías 1, 2 y 3.

### DELETE /payments/{id}
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Sin token | — | 401 | 401 `Token no proporcionado` | PASS |
| Token inválido | `abc.def.ghi` | 401 | 401 `Token inválido o expirado` | PASS |
| id no entero | `abc`, `1.5`, `5abc`, 20 dígitos | 400 | 400 `Invalid ID format` | PASS |
| id ≤ 0 | `0`, `-1` | 404 | 404 `Payment not found` | PASS |
| Inyección | `9107 OR 1=1`, `9107' OR '1'='1` | 400 | 400 `Invalid ID format` | PASS |
| Inexistente | 99999 | 404 | 404 `Payment not found` | PASS |
| Otra notaría | token n3 y n2 → 9107 (n1) | 404, fila intacta | 404; fila intacta | PASS |
| Éxito | token n1 → 9102 | 204 sin cuerpo | 204 sin cuerpo | PASS |
| Repetido | 9102 otra vez | 404 | 404 | PASS |
| Cliente inactivo | 9103 | 204 | 204 | PASS |
| Sin fecha | 9104 | 204 | 204 | PASS |
| Último abono | 9105 | 204; statement en 0 | 204; statement en 0 | PASS |
| Mismo uuid en otra notaría | 9101 (n1) | 204; 9201 (n2) intacto | 204; 9201 intacto | PASS |
| Concurrencia | 4 simultáneos × 6 ids | 1×204 + 3×404 | 1×204 + 3×404 en las 6 | PASS |
| Rutas vecinas | `DELETE /payments`, `/payments/{id}/receipt`, `/payments/uuid/{uuid}/receipt` | 405 | 405 | PASS |
| Barra final | `/payments/9107/` | comportamiento del proyecto | 301 de Apache | PASS (ver O2) |

### Efectos en otros módulos
| Caso | Expected | Actual | Estado |
|------|----------|--------|--------|
| `GET /payments/{id}` y `?uuid=` del borrado | 404 | 404 | PASS |
| `GET /payment-clients/{id}/statement` | Sin el abono; totales recalculados | 5227 / 4773 | PASS |
| `GET` ambos recibos PDF del borrado | 404 | 404 | PASS |
| `POST /payments` con el uuid borrado | 201, nuevo id | 201, `paymentId` 9601 | PASS |

## Integridad de BD
| Medición | Línea base | Tras sembrar y limpiar |
|----------|-----------|------------------------|
| `CHECKSUM payments_payments` | 3457777915 | 3457777915 |
| `CHECKSUM payments_clients` | 318868036 | 318868036 |
| `CHECKSUM payments_transactions` | 3571601708 | 3571601708 |
| Filas (abonos / clientes / cargos) | 27 / 23 / 46 | 27 / 23 / 46 |

Los datos reales (abonos con id < 9000) no se tocaron. Solo se eliminaron filas `QA20*` sembradas por esta ronda. El `AUTO_INCREMENT` de `payments_payments` quedó más alto por el `POST` de prueba; no afecta a ningún dato.

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Token n3 → abono de n1 | PASS | 404 `Payment not found`, fila intacta |
| Token n2 → abono de n1 | PASS | 404 `Payment not found`, fila intacta |
| Token n1 borra abono con uuid y `id_client` iguales a uno de n2 | PASS | Solo se borra el de n1; el de n2 sigue y lo ve su notaría |
| Nunca 403 entre notarías | PASS | No se revela que el abono existe |

## Observaciones (no bloqueantes)

- **O1 — Sin tests automáticos del SQL ni del Controller:** es la decisión ADR-002 (el proyecto no tiene infraestructura de integración y `PaymentService` es `final`). Por eso el filtro `id_notary` real y el HTTP se validaron aquí contra Docker. Una regresión futura en el `WHERE` solo la detectaría una nueva ronda de QA. Si se quiere cobertura automática, requiere una spec aparte de infraestructura de tests.
- **O2 — Barra final:** `DELETE /payments/9107/` responde 301 de Apache. Es el comportamiento de todo el proyecto (ya señalado en spec 19), no de esta feature.
- **O3 — Borrado irreversible y sin rastro:** riesgo aceptado por el PO (no se sabe quién borró qué ni se puede recuperar). Está documentado en OpenAPI. Cualquier usuario autenticado de la notaría puede borrar cualquier abono, también por decisión del PO.
- **O4 — Re-creación por reintento:** un `POST` tardío con el uuid borrado crea un abono nuevo (verificado, 201). Riesgo aceptado y documentado.
- **O5 — Deprecación de PHPUnit:** ya existía antes de esta feature.

## Bugs
Ninguno. No se creó `specs/20_payment_delete/bugs/`.

## Conclusión
La feature cumple los 9 escenarios del PO y los criterios técnicos de arch. El `DELETE` responde 204 sin cuerpo, el filtro por notaría funciona (404 sin revelar existencia y fila intacta), el borrado concurrente da exactamente un 204, y el estado de cuenta y los recibos reflejan el borrado. No hay cambios de esquema y la BD quedó idéntica a la línea base tras limpiar los datos de prueba. La suite unitaria está en verde (989 tests).

**Estado: APROBADO.** Sin migraciones en esta feature.

## Fecha
2026-11-20
