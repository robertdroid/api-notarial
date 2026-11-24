# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
`specs/11_payment_transactions/po/acceptance_criteria.md`, con los ajustes de `implementation_plan.md`:

- **ADR-002**: `taxes` tiene dos modos. Los escenarios 1, 2 y 8–10 del PO aplican tal cual en **modo calculado** (procedimiento con impuestos configurados). En modo manual, `taxes` viene del request y los detalles no se aceptan (422).
- **ADR-003**: en POST el uuid existente se revisa **antes** que el cliente y el procedimiento.
- **ADR-005**: los errores de validación (escenarios 19 y 20) usan `400 "Validation failed"` + `errors.<campo>`.
- **ADR-006**: el PUT no valida el status del cliente, valida el procedimiento solo si cambia y conserva detalles existentes con impuesto inactivo.
- **ADR-007**: `clientId` y `uuid` son opcionales en PUT.

## Criterios por Capa

### Database
- [ ] Tarea 0.1 (revisión de la v1) respondida por escrito en el PR
- [ ] Migración `20261111_100000_dedupe_payments_transactions_uuid.sql` con pre-checks en comentario, UP y DOWN
- [ ] Migración `20261111_100100_payments_transactions_innodb_and_indexes.sql` con UP y DOWN
- [ ] `SHOW TABLE STATUS LIKE 'payments_transaction%'` → `Engine = InnoDB` en ambas tablas
- [ ] `SHOW INDEX FROM payments_transactions` → `uq_payments_transactions_notary_uuid` (Non_unique = 0) e `idx_payments_transactions_notary_client`
- [ ] `SHOW INDEX FROM payments_transaction_details` → `PRIMARY (id_transaction, id_tax)`
- [ ] Tras la limpieza: 0 uuids duplicados por notaría y 0 detalles huérfanos
- [ ] Ambas migraciones aplicadas en local y movidas a `.db/applied/`

### Core
- [ ] `TransactionManager::transactional()` hace commit y devuelve el resultado del callable
- [ ] Ante cualquier `\Throwable` hace `rollBack()` y relanza la **misma** excepción
- [ ] Con una transacción ya abierta, no llama a `beginTransaction()` de nuevo

### Controller Layer
- [ ] `POST /api/v3/payment-transactions` → **201** con la transacción completa
- [ ] `POST` con uuid ya existente en la notaría → **200** con la transacción existente, sin modificarla
- [ ] `GET /api/v3/payment-transactions/{id}` → 200
- [ ] `GET /api/v3/payment-transactions?uuid=X` → 200 con **un objeto** (no array)
- [ ] `GET /api/v3/payment-transactions` sin `uuid` → 400 `uuid query parameter is required`
- [ ] `GET ?uuid=` vacío, de más de 20 caracteres o con caracteres inválidos → 400 `Invalid uuid format`
- [ ] `PUT /api/v3/payment-transactions/{id}` → **200** con la transacción actualizada
- [ ] `{id}` no entero → 400 `Invalid ID format`; `{id}` ≤ 0 → 404 `Transaction not found`
- [ ] Body inválido → 400 `Validation failed` + `errors`, reportando **todos** los campos con error
- [ ] `TransactionException` → 400 / 404 / 422 según `TransactionError::httpStatus()`
- [ ] Las 4 rutas tienen `->withMiddleware('auth')`: sin token → 401
- [ ] Sin rutas extra: `DELETE` y `PATCH` → 405 del router
- [ ] El Controller no contiene lógica de negocio ni SQL

### Service Layer
- [ ] `PaymentTransactionService` recibe por constructor los 5 repositorios y el `TransactionManager`; **no** recibe `PDO`
- [ ] El Service no importa nada de `App\Core\Http`
- [ ] **create**
  - [ ] uuid existente → devuelve la existente con `created = false`, **sin** consultar cliente ni procedimiento y **sin** escribir
  - [ ] cliente inexistente o de otra notaría → `ClientNotFound`; inactivo → `ClientInactive`
  - [ ] procedimiento inexistente o de otra notaría → `ProcedureNotFound`; inactivo → `ProcedureInactive`
  - [ ] cabecera y detalles se insertan dentro de **una** llamada a `transactional()`
  - [ ] `DuplicateTransactionUuidException` del Repository → relee por uuid y devuelve `created = false`
  - [ ] `id_status` siempre 1 al crear
- [ ] **update**
  - [ ] todo (lectura con bloqueo, validaciones y escrituras) ocurre dentro de `transactional()`
  - [ ] la transacción se lee con `forUpdate: true`
  - [ ] transacción inexistente o de otra notaría → `TransactionNotFound`
  - [ ] `clientId` presente y distinto → `CannotChangeClient`; `uuid` presente y distinto → `CannotChangeUuid`
  - [ ] `clientId`/`uuid` ausentes o iguales → sin error
  - [ ] cliente inactivo → **no** es error
  - [ ] procedimiento sin cambio e inactivo → **no** es error
  - [ ] procedimiento nuevo inexistente → `ProcedureNotFound`; inactivo → `ProcedureInactive`
  - [ ] orden de escritura: `deleteDetails` → `insertDetails` → `update`
  - [ ] nunca modifica `uuid`, `id_client`, `id_notary` ni `id_status`
- [ ] **Modo de taxes** (create y update)
  - [ ] Modo manual (`findActiveByProcedure` → `[]`): `taxes = dto.taxes`; los detalles enviados se ignoran silenciosamente; no se insertan detalles
  - [ ] Modo calculado: `dto.taxes` se ignora; `taxes = round(Σ round(quantity × amount, 2), 2)`
  - [ ] En ambos: `total = round(fee + taxes, 2)`
  - [ ] En PUT el modo se decide con el procedimiento del request, no con el anterior
- [ ] **Filtrado de detalles** (modo calculado)
  - [ ] `taxId` repetido → se conserva el primero
  - [ ] impuesto inexistente o de otra notaría → ignorado, sin error
  - [ ] impuesto inactivo → ignorado en POST; en PUT se conserva si ya era detalle de la transacción
  - [ ] todos ignorados → transacción válida con `taxes = 0`
  - [ ] los impuestos se consultan con **una** llamada a `findByIdsAndNotary()`

### Repository Layer
- [ ] El Repository no llama a `beginTransaction`, `commit` ni `rollBack`
- [ ] `findByIdAndNotary` y `findByUuidAndNotary` filtran por `t.id_notary = :notaryId`
- [ ] Los nombres se obtienen con `LEFT JOIN` (una transacción con cliente, procedimiento o impuesto inexistente se devuelve con nombre `''`, no desaparece)
- [ ] `forUpdate = true` emite `SELECT ... FOR UPDATE` sobre la cabecera, filtrando por id y notaría
- [ ] `insert` traduce el error MySQL 1062 a `DuplicateTransactionUuidException`; cualquier otro `PDOException` se propaga
- [ ] `update` incluye `AND id_notary = :notaryId` en el WHERE
- [ ] `insertDetails` usa **un** INSERT multi-row; con `[]` no ejecuta SQL
- [ ] Las fechas se guardan como `YYYY-MM-DD 00:00:00`
- [ ] Todos los valores van por prepared statements; ningún placeholder con nombre se repite en una misma sentencia

### Entity/DTO
- [ ] Entities `PaymentTransaction` y `PaymentTransactionDetail` `final`, readonly, con `fromRow()`
- [ ] `fromRow()` tolera datos legacy: `quantity` NULL → 1, `amount` NULL → 0.0, `comments` NULL → `''`, `id_status` NULL → 1
- [ ] `date` se expone como `YYYY-MM-DD` (sin hora); `date` de detalle NULL → `null`
- [ ] `TransactionDetailItem` es VO `final readonly`
- [ ] `clientId`, `procedureId`, `taxId`: solo `int > 0` (`"5"`, `5.0`, `true` → 400)
- [ ] `uuid`: string de 1 a 20 caracteres `[A-Za-z0-9_-]`; sin trim ni cambio de mayúsculas
- [ ] `date`: `2024-02-30`, `2024-1-5`, `15/01/2024`, `""` → 400
- [ ] `fee`, `taxes`, `amount`: `int|float`, `0 ≤ v ≤ 999999999.99`; string, bool, `null` explícito → 400
- [ ] `quantity`: `int` entre 1 y 9999; `0`, `-1`, `1.5`, `"2"` → 400
- [ ] `reference`, `responsible`, `comments`: string ≤ 65535 bytes; se aplica `trim`
- [ ] `details`: ausente, `null` o `[]` → `[]`; objeto (no lista) → 400; más de 50 → 400
- [ ] `TransactionResponse` devuelve exactamente los campos del contrato, sin `notaryId` ni `status`
- [ ] `fee`, `taxes`, `total` y `amount` se serializan como números, no como strings

## Tests Requeridos

### Unit
- [ ] `Core/Database/TransactionManagerTest`: commit + valor de retorno; rollback + relanza; no anida
- [ ] `Entity/PaymentTransactionTest` y `PaymentTransactionDetailTest`: `fromRow` con strings de PDO, NULLs legacy, formato de fechas, `detailTaxIds()`
- [ ] `Exception/TransactionErrorTest`: cada caso → mensaje y código HTTP
- [ ] `DTO/TransactionFieldRulesTest`: límites de cada regla (uuid de 20 y 21 caracteres, fecha inexistente, monto máximo, texto de 65535 y 65536 bytes)
- [ ] `DTO/TransactionDetailsParserTest`: ausente, `[]`, no lista, 51 items, item no objeto, defaults (`quantity = 1`, `amount = 0.0`, `comments = ''`), cada tipo inválido, claves `details.N.*`, `taxId` repetido **sin** error
- [ ] `DTO/CreateTransactionRequestTest`: mínimo válido, completo válido, cada requerido faltante, varios errores a la vez, redondeo de `fee` a 2 decimales
- [ ] `DTO/UpdateTransactionRequestTest`: `clientId`/`uuid` ausentes → `null`; presentes con formato inválido → error; defaults de reemplazo completo
- [ ] `DTO/TransactionResponseTest`: forma exacta y tipos numéricos
- [ ] `Service/PaymentTransactionServiceTest` (mocks de 5 repos + `TransactionManager`):
  - create: uuid existente → `created = false`, `insert` **never()**, repos de cliente y procedimiento **never()**
  - create: `ClientNotFound`, `ClientInactive`, `ProcedureNotFound`, `ProcedureInactive` → `insert` **never()**
  - create modo calculado: fee 3000 + detalles (1 × 500) y (2 × 250) → taxes 1000, total 4000 (escenario 2 del PO)
  - create modo calculado: `dto.taxes = 999` se ignora
  - create modo calculado sin detalles → taxes 0, total = fee
  - create modo manual: `taxes = 1430`, sin detalles → taxes 1430, `insertDetails` con `[]` o never
  - create modo manual con detalles → detalles ignorados, taxes = dto.taxes, `insertDetails` con `[]` o never
  - create: impuesto inactivo, de otra notaría y `taxId` repetido → solo se insertan los válidos y los totales los excluyen
  - create: redondeo (`3 × 33.335`, `0.1 + 0.2`) → 2 decimales exactos
  - create: `insert` lanza `DuplicateTransactionUuidException` → devuelve la existente con `created = false`
  - update: `TransactionNotFound`, `CannotChangeClient`, `CannotChangeUuid`
  - update: cliente inactivo → OK; procedimiento igual e inactivo → OK; procedimiento nuevo inactivo → `ProcedureInactive`
  - update: detalle existente con impuesto inactivo → se conserva; detalle nuevo con impuesto inactivo → se ignora
  - update: `details = []` en modo calculado → `deleteDetails` llamado, taxes 0
  - update: cambio de procedimiento de calculado a manual → detalles borrados, `taxes = dto.taxes`
  - update: se llama a `findByIdAndNotary` con `forUpdate: true`

### Manual / Integración (QA)
- [ ] **Atomicidad (escenario 18):** provocar un fallo al insertar detalles (por ejemplo, renombrar temporalmente la tabla de detalles en local, o un detalle que viole el PK) → respuesta 500 y **ninguna** cabecera nueva en `payments_transactions`
- [ ] **Atomicidad en PUT:** con el mismo fallo, los detalles y la cabecera anteriores quedan intactos
- [ ] Cross-tenant: transacción de la notaría A con token de B → 404 en GET por id, GET por uuid y PUT
- [ ] Cross-tenant: POST con `clientId` o `procedureId` de otra notaría → 404
- [ ] Cross-tenant: detalle con impuesto de otra notaría → ignorado, la transacción se crea
- [ ] Mismo uuid en dos notarías distintas → ambas crean (201)
- [ ] Doble POST simultáneo con el mismo uuid → una sola fila; respuestas 201 y 200
- [ ] POST repetido con el mismo uuid y datos distintos → 200 y la fila en BD **no** cambia
- [ ] POST repetido después de desactivar al cliente → 200 (no 422)
- [ ] uuid `abc` y `ABC` son transacciones distintas
- [ ] PUT sobre una transacción legacy en modo manual reenviando `taxes` → `taxes` se conserva
- [ ] PUT sobre una transacción de cliente inactivo → 200
- [ ] La v1 sigue pudiendo crear, editar y consultar movimientos después de la migración
- [ ] Una transacción creada por la v3 se ve correctamente en la v1, y viceversa

## Criterios de Código
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] PSR-12; typed properties y return types
- [ ] Clases `final` excepto el Repository y `TransactionManager` (mockeables)
- [ ] Sin dependencias circulares: `PaymentTransaction` → `PaymentClient` / `PaymentProcedure` / `PaymentTax` / `PaymentProcedureTax`, nunca al revés
- [ ] No se modifica ningún archivo de los módulos `PaymentClient`, `PaymentProcedure`, `PaymentTax` ni `PaymentProcedureTax`
- [ ] Mensajes de error en inglés, idénticos al catálogo
- [ ] `docs/openapi.yaml` actualizado

## Performance
- [ ] POST: máximo 10 queries, sin importar el número de detalles
- [ ] Sin N+1: una sola consulta de impuestos y un solo INSERT de detalles
- [ ] GET por uuid usa `uq_payments_transactions_notary_uuid` (verificar con `EXPLAIN`)
- [ ] Las transacciones de BD son cortas: las validaciones del POST que no requieren bloqueo ocurren antes de `transactional()`
- [ ] Respuesta en menos de 500 ms en local

## Seguridad
- [ ] JWT requerido en las 4 rutas
- [ ] `notaryId` siempre del token, nunca del body, path ni query
- [ ] Toda lectura y escritura de `payments_transactions` filtra por `id_notary`
- [ ] Cliente, procedimiento e impuestos validados contra la notaría del token antes de escribir
- [ ] Los 404 de un recurso ajeno son indistinguibles de un inexistente
- [ ] El POST idempotente solo devuelve transacciones de la notaría del token
- [ ] Prepared statements en todos los queries, incluidos los `IN` y el INSERT multi-row
- [ ] `uuid` validado contra una lista blanca de caracteres antes de usarse
- [ ] `details` limitado a 50 items; textos limitados a 65535 bytes
