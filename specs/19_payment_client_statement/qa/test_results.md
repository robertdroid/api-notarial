# Resultados de Pruebas: 19_payment_client_statement

## Resumen
- **Estado:** APROBADO (con observaciones no bloqueantes)
- **Tests Ejecutados:** 987 unitarios (41 de la feature) + ~75 peticiones de API + 88 comparaciones SQL vs API + 4 EXPLAIN + pruebas de rendimiento
- **Tests Pasados:** todos. 21/21 escenarios del PO en PASS; criterios técnicos en PASS (rendimiento con salvedad, ver O1)
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (987 tests, 1935 aserciones; módulo `PaymentStatement`: 41 tests, 104 aserciones)
- **Cobertura:** no medida (no hay driver de cobertura configurado)
- **Nota:** 1 deprecación de PHPUnit por el esquema del XML de configuración. Ya existía antes de esta feature (ver spec 17).

## Criterios de Aceptación

### Del PO
Los valores esperados de los escenarios con datos sembrados se calcularon a mano antes de llamar a la API. Cliente 9001 (notaría 1): trámites activos 500 (feb), 1160 (10-mar), 10 (12-mar, procedimiento eliminado), 200 (31-mar 14:35), 50 (31-mar 23:59:59), 300 (1-abr 00:00); cancelados 1000 (feb) y 700 (mar); cobros 100 (feb), 400 (1-mar 00:00), 500 (15-mar), 11 (31-mar 23:59:59), 250 (2-abr) y 77 sin fecha; más un trámite y un cobro de 5000 de la notaría 2 con el mismo `id_client`.

| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Estado de cuenta completo sin filtros | PASS | 6 trámites activos, 6 cobros en orden ascendente; `pendingAmount` 0, `totalCharges` 2220, `totalPayments` 1338, `balance` 882; `fromDate`/`toDate` null. Cliente real 6 (5 trámites, 3 cobros): 62500 / 37500 / 25000 |
| 2 | Rango `from` y `to` | PASS | `from=2026-03-01&to=2026-03-31`: trámites 9003, 9008, 9004, 9007; cobros 9002, 9003, 9006; `pendingAmount` 400 (500 − 100), cargos 1420, abonos 911, `balance` 909 |
| 3 | Último día del rango completo | PASS | Trámite del 31-mar 14:35 y el de 23:59:59 incluidos; el del 1-abr 00:00:00 excluido |
| 4 | Primer día del rango completo | PASS | Cobro del 1-mar 00:00:00 incluido con `from=2026-03-01` |
| 5 | Solo `from` | PASS | Todo lo posterior inclusive; `toDate` null; `pendingAmount` 400; `balance` 959 |
| 6 | Solo `to` | PASS | Todo lo anterior inclusive; `fromDate` null; `pendingAmount` 0; `balance` 909 |
| 7 | Cancelado excluido de todo | PASS | Cancelados de 1000 y 700 no aparecen en `transactions` ni en `totalCharges`. Cliente solo con cancelados: listas vacías y todo en 0 |
| 8 | Cancelado excluido del saldo anterior | PASS | Cancelado de 1000 en feb no suma: `pendingAmount` = 500 − 100 = 400 |
| 9 | Cliente inactivo consultable | PASS | Cliente 9002 (`id_status` 2): 200, cargos 800, abonos 300, `balance` 500. Clientes reales inactivos (1 a 4, 11) también responden 200 |
| 10 | Cliente inexistente | PASS | id=999 → 404 `{"error":"Client not found"}` |
| 11 | Cliente de otra notaría | PASS | Ver multi-tenancy. 404 con el mismo cuerpo que un inexistente |
| 12 | Cliente sin movimientos | PASS | 200, `transactions: []`, `payments: []`, todo en 0 |
| 13 | Saldo a favor | PASS | Cargos 100, abonos 250 → `balance` −150 |
| 14 | Cobro sin fecha sin filtros | PASS | Cobro 9005 aparece al final con `date: null` y suma a `totalPayments` (1338 incluye los 77). Cliente solo con cobro sin fecha: `balance` −40 |
| 15 | Cobro sin fecha con filtro | PASS | Con `from` solo, con `to` solo y con ambos: no aparece ni suma, tampoco en `pendingAmount` |
| 16 | Montos a 2 decimales | PASS | 0.1 + 0.2 + 3 × 33.333 → `totalCharges` 100.29; 0.7 + 0.1 → `totalPayments` 0.8; `balance` 99.49. Ver O2 |
| 17 | `clientId` no numérico, cero o negativo | PASS | `abc`, `1.5`, `5abc` y un entero desbordado → 400 `Invalid ID format`; `0` y `-3` → 404 `Client not found` |
| 18 | Fecha con formato inválido | PASS | `01/03/2026`, `2026-3-1` → 400 `Validation failed` con `errors.from` (o `errors.to`) |
| 19 | Fecha inexistente | PASS | `2026-02-31` y `2026-02-30` → 400; `2025-02-29` → 400; `2028-02-29` (bisiesto) → 200 |
| 20 | Rango invertido | PASS | `from=2026-04-01&to=2026-03-01` → 400 con `errors.to` = `To must not be before from` |
| 21 | `from` y `to` iguales | PASS | `2026-03-31`: trámites 9004 y 9007, cobro 9006, `pendingAmount` 670, `balance` 909. `2026-03-15`: cobro 9003, `pendingAmount` 1170 |

### No funcionales y casos edge
| Criterio | Estado | Notas |
|----------|--------|-------|
| Solo lectura | PASS | `CHECKSUM TABLE` de `payments_clients`, `payments_transactions`, `payments_payments` y `payment_procedures` idéntico antes y después de toda la ronda |
| Multi-tenant en las 4 consultas | PASS | Las filas de la notaría 2 con `id_client` 9001 (5000 en cargo y en cobro) no alteraron lista ni totales, ni con filtros (`pendingAmount`) ni sin ellos |
| Rendimiento (p95 < 500 ms con 500 movimientos) | PASS con salvedad | Ver O1. Costo propio del endpoint ≈ 10 ms; p50 ≈ 160 ms por HTTP; el p95 no es medible en este entorno |
| Montos | PASS | Se usa el `total` guardado; no se recalcula `fee + taxes` |
| Zona horaria / formato de fecha | PASS | `date` en `Y-m-d`; límite `to + 1 día` con `America/Mexico_City` |
| Sin caché | PASS | Sin cabeceras de caché; cada consulta lee la BD |
| Parámetro vacío (`from=&to=`) | PASS | Tratado como no enviado (equivale a sin filtros) |
| Parámetros desconocidos | PASS | Ignorados |
| Rango sin movimientos pero con saldo previo | PASS | `from=2026-05-01&to=2026-05-31`: listas vacías, `pendingAmount` 959, `balance` 959 |
| Procedimiento eliminado | PASS | Trámite con `id_procedure` inexistente: `procedureName: ""`, 200 |
| `clientName` | PASS | Nombre actual del cliente (viene de `payments_clients`) |
| Orden | PASS | Trámites por fecha y luego id; cobros por fecha, sin fecha al final |
| Bordes de fecha extremos | PASS | `to=9999-12-31`, `from=0001-01-01`, `from=1000-01-01&to=9999-12-31` → 200, sin 500 |
| Parámetro repetido (`from=a&from=b`) | PASS | PHP toma el último valor; sin error |
| Criterios de rechazo del PO | PASS | Ninguno se cumple: sin cancelados, sin datos de otra notaría, último día completo, fecha inválida → 400, sin escrituras, campos v1 intactos |

### Técnicos (arch)
| # | Área | Estado | Notas |
|---|------|--------|-------|
| Controller | Ruta con `auth`, orden `{id}` → `from`/`to` → cliente | PASS | `abc?from=zzz` → 400 `Invalid ID format`; `0?from=zzz` → 404; `999?from=zzz` → 400 `Validation failed` (la validación de fechas va antes de buscar el cliente, como pide arch) |
| Controller | Sin colisión con `/payment-clients/{id}` | PASS | Ambas rutas responden lo suyo |
| Controller | `from[]`, `to[]`, `from[a]` → 400, nunca 500 | PASS | |
| Controller | Sin lógica de cálculo ni SQL, sin `try/catch` propio | PASS | Revisado en código (58 líneas) |
| Service | `null` si el cliente no existe y no consulta movimientos; no filtra estatus; centavos enteros; sin `from` no ejecuta las sumas previas | PASS | Código revisado; cubierto por `PaymentStatementServiceTest` |
| Repository | `id_notary` e `id_client` en las 4 consultas; `id_status = 1` en lista **y** suma previa; `LEFT JOIN` con `id_notary` en el `ON`; límites `>= from` y `< to + 1 día` | PASS | Sin `BETWEEN` ni `DATE(`; PDO preparado; solo `SELECT`; sin transacciones |
| Repository | Devuelve entities, sin N+1; `COALESCE(SUM(...), 0)` | PASS | 1 consulta por lista |
| Entity/DTO | `final`, `readonly`, `?string $date` en cobro, `StatementQuery` con constructor privado | PASS | `StatementRepository` es `class` a propósito (mockeable) |
| Entity/DTO | Nombres exactos de v1; `comment` singular | PASS | Verificado en la respuesta real |
| Tests | Lista de `StatementQueryTest`, `PaymentStatementServiceTest`, `StatementResponseTest` | PASS | 12 + 13 + 2 métodos (con data providers: 41 tests) |
| Tests | `composer test` completo | PASS | 987 tests |
| Código | `declare(strict_types=1)` en todos los archivos | PASS | |
| Código | Sin cambios en módulos existentes | PASS | El commit solo toca `routes.php`, `bootstrap.php`, README, OpenAPI y la feature |
| Performance | EXPLAIN de las 4 consultas | PASS | Todas usan `idx_payments_transactions_notary_client` / `idx_payments_payments_notary_client`; sin full scan |
| Performance | Máximo 5 consultas (3 sin `from`) | PASS | Revisado en Service |
| Performance | Migración spec 17 aplicada | PASS | `payments_payments` es InnoDB con el índice. Sigue siendo prerrequisito de deploy en otros entornos (ver O1) |
| Seguridad | JWT requerido | PASS | Sin token → 401 `Token no proporcionado`; token inválido → 401 `Token inválido o expirado` |
| Seguridad | `notaryId` solo del token; sin valores sin parametrizar | PASS | Intento con `' OR 1=1--` en `from` → 400 |
| Documentación | OpenAPI, README (fila + Changelog) | PASS | Endpoint, parámetros `format: date`, respuestas 200/400/401/404, ejemplo de 400 con `errors`, schema `ClientStatementResponse`. La sintaxis YAML no se validó con un parser (ver O5) |
| Documentación | `specs/_index.md` con la fila 19 | PASS | Falta marcar la columna QA y el PR al cerrar |

## Pruebas de API

Todas las llamadas fueron contra `http://localhost:8080/api/v3` con tokens de las notarías 1, 2 y 3.

### GET /payment-clients/{id}/statement
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Sin filtros | 9001 | 200; 2220 / 1338 / 882 | 200; 2220 / 1338 / 882 | PASS |
| Rango completo | `from=2026-03-01&to=2026-03-31` | pend 400, cargos 1420, abonos 911, bal 909 | igual | PASS |
| Solo `from` | `from=2026-03-01` | pend 400, bal 959 | igual | PASS |
| Solo `to` | `to=2026-03-31` | pend 0, bal 909 | igual | PASS |
| Un solo día | `from=to=2026-03-31` y `2026-03-15` | pend 670 / 1170 | igual | PASS |
| Rango vacío con saldo previo | mayo 2026 | bal 959 | 959 | PASS |
| Cliente inactivo | 9002 | 200, bal 500 | igual | PASS |
| Cliente vacío | 9003 | 200, todo en 0 | igual | PASS |
| Solo cancelados | 9005 | 200, todo en 0 | igual | PASS |
| Saldo a favor | 9006 | bal −150 | −150 | PASS |
| Redondeo | 9007 | 100.29 / 0.8 / 99.49 | igual | PASS |
| Solo cobro sin fecha | 9008 sin filtros / con `from` / con `to` | −40 / 0 / 0 | igual | PASS |
| `from=&to=` | vacíos | igual a sin filtros | igual | PASS |
| Parámetros desconocidos | `foo=bar&page=2` | ignorados | igual | PASS |
| id inexistente | 999 | 404 `Client not found` | igual | PASS |
| id no numérico | `abc`, `1.5`, `5abc`, 20 dígitos | 400 `Invalid ID format` | igual | PASS |
| id ≤ 0 | `0`, `-3` | 404 `Client not found` | igual | PASS |
| Formato inválido | `01/03/2026`, `2026-3-1`, espacio inicial, con hora, `0000-00-00` | 400 `errors.from` | igual | PASS |
| Fecha inexistente | `2026-02-31`, `to=2026-02-30`, `2025-02-29` | 400 | igual | PASS |
| Bisiesto válido | `2028-02-29` | 200 | 200 | PASS |
| Rango invertido | `from=2026-04-01&to=2026-03-01` | 400 `errors.to` | igual | PASS |
| Ambos inválidos | `from=x&to=y` | 400 con ambos errores, sin error de rango | igual | PASS |
| Arrays | `from[]=…`, `to[]=x&to[]=y`, `from[a]=…` | 400 | igual | PASS |
| Inyección | `from=2026-03-01' OR 1=1--` | 400 | 400 | PASS |
| Extremos | `to=9999-12-31`, `from=0001-01-01` | 200 | 200 | PASS |
| Sin token | — | 401 | 401 `Token no proporcionado` | PASS |
| Token inválido | `abc.def.ghi` | 401 | 401 `Token inválido o expirado` | PASS |
| Métodos | POST, PUT, DELETE, PATCH | 405 | 405 | PASS |
| `/payment-clients/{id}` | 9001 | sigue respondiendo el cliente | 200 | PASS |

### Verificación cruzada SQL vs API (clientes reales, notaría 1)
SQL independiente (límites datetime, `id_status = 1`) contra `pendingAmount`, `totalCharges` y `totalPayments` de la API para los 22 clientes reales:

| Filtro | Coinciden | Difieren |
|--------|-----------|----------|
| sin filtros | 22 | 0 |
| `from=2024-01-01` | 22 | 0 |
| `from=2023-06-01&to=2024-06-30` | 22 | 0 |
| `to=2023-12-31` | 22 | 0 |

### Rendimiento (cliente con 450 trámites activos + 500 cobros en el rango, respuesta de 217 KB)
| Medición | Resultado |
|----------|-----------|
| Totales | 450 × 116.58 = 52461.00; 500 × 50.25 = 25125.00; coinciden con SQL |
| PHP sin Apache (Service + JSON, 20 corridas) | min 6 ms, p50 10 ms |
| HTTP, 80 peticiones secuenciales | p50 ≈ 160 ms; p95 ≈ 0.8–2.0 s por una cola de valores atípicos |
| Línea base: 401 sin token y sin BD | p50 48 ms; 6/80 peticiones > 500 ms |
| Línea base: `GET /payment-clients/{id}` (1 consulta) | p50 54 ms; 7/80 > 500 ms |
| Statement con 950 movimientos | p50 160 ms; 8/80 > 500 ms |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Token notaría 1 → cliente 9004 (notaría 2) | PASS | 404 `Client not found`, igual que un inexistente |
| Token notaría 1 → cliente 15 (notaría 3) | PASS | 404 |
| Token notaría 2 → cliente 9001 (notaría 1) | PASS | 404 |
| Token notaría 3 → cliente 6 (notaría 1) | PASS | 404 |
| Token notaría 2 → su propio cliente 9004 | PASS | 200 |
| Movimientos de otra notaría con el mismo `id_client` | PASS | No aparecen ni alteran `totalCharges`, `totalPayments`, `pendingAmount` ni `balance` |
| Nunca 403 entre notarías | PASS | No se revela que el cliente existe |

## Observaciones (no bloqueantes)

- **O1 — p95 de rendimiento no medible en este entorno:** el criterio pide p95 < 500 ms con 500 movimientos. Aquí el p95 sale entre 0.8 y 2 s, pero el mismo ruido aparece en un 401 que no toca la BD (6/80 peticiones > 500 ms) y en un GET simple. La causa es el entorno: `/var/www/html` es un montaje 9p de Windows (`drvfs`) y opcache revalida con `revalidate_freq=2`. Lo que sí se midió es el costo propio del endpoint: ≈ 10 ms en PHP con 950 movimientos, p50 ≈ 160 ms por HTTP, EXPLAIN con los índices correctos y sin full scan. Se recomienda repetir la medición en el servidor Linux de staging o producción. **Prerrequisito de deploy:** la migración `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql` (spec 17) debe estar aplicada donde se despliegue; aquí ya lo está.
- **O2 — Redondeo por fila vs suma en BD:** los totales se suman en centavos a partir de cada fila ya redondeada, así `totalCharges` coincide con lo que ve el usuario en la lista (3 × 33.333 → 99.99, no 100.00). Si v1 sumaba el valor crudo, podría diferir 1 centavo, y solo con datos legacy de más de 2 decimales. La BD real no tiene ningún caso (la comparación SQL contra API dio 88/88 sin diferencias). Es el riesgo R3 de arch, aceptado.
- **O3 — Formato de los números en JSON:** PHP serializa `12500` y no `12500.0` (las notas de dev dicen `1160.0`). Es el mismo valor numérico en JSON y no afecta a ningún cliente. Se deja constancia solo por si alguien compara texto exacto.
- **O4 — Cobertura de datos reales:** la BD de desarrollo no tiene trámites cancelados, cobros sin fecha ni fechas con hora, por eso esos escenarios se probaron con datos sembrados (eliminados al terminar; la BD quedó en 23 clientes, 46 trámites y 27 cobros, igual que antes). Tampoco hay trámites con `total ≠ fee + taxes`: el hallazgo que pide la sección 6.2 de arch es **ninguno**. La comparación directa contra el endpoint v1 (repo `notarial`) no se hizo en vivo; se sustituyó por la verificación cruzada SQL vs API de los 22 clientes. En esta BD las únicas diferencias esperables contra v1 serían los clientes inactivos (ahora consultables) y el último día del rango.
- **O5 — YAML de OpenAPI sin validar con parser:** se revisó el contenido del endpoint y el schema, pero no se ejecutó un validador de sintaxis (no hay parser YAML en el contenedor ni en el proyecto).
- **O6 — Barra final:** `/payment-clients/9001/statement/` responde 301 de Apache. Es el comportamiento de todo el proyecto (`/payment-clients/9001/` también), no de esta feature.
- **O7 — Cobros sin fecha:** quedan al final de `payments` (decisión de dev pendiente de confirmar por el PO, punto 3 de "Para devolver al PO") y con filtros también se excluyen de `pendingAmount`, por lo que el `balance` filtrado puede diferir del no filtrado en ese monto. Es consecuencia directa del default 1 aprobado y ya está explicado en OpenAPI y README.
- **O8 — Deprecación de PHPUnit:** ya existía antes de esta feature.

## Bugs
Ninguno. No se creó `specs/19_payment_client_statement/bugs/`.

## Conclusión
La feature cumple los 21 escenarios del PO y los criterios técnicos de arch. Los cálculos coinciden con los valores calculados a mano y con un SQL independiente sobre los 22 clientes reales; los cancelados no suman a nada; el rango incluye el último día completo; las fechas inválidas dan 400; el aislamiento entre notarías funciona en las 4 consultas incluso con filas de otra notaría con el mismo `id_client`; la BD no cambia (checksums idénticos) y la suite unitaria está en verde.

El único punto sin cerrar con medición real es el p95 de 500 ms (O1), que no se puede evaluar en este entorno de Docker sobre Windows; el costo propio del endpoint es de ~10 ms y las consultas usan índices, así que no se considera bloqueante.

**Estado: APROBADO.** Sin migraciones en esta feature. Para el deploy, aplicar antes la migración de spec 17 si el entorno aún no la tiene.

## Fecha
2026-11-19
