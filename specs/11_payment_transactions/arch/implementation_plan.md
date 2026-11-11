# Plan de Implementación: Transacciones de Pago (Payment Transactions)

## Resumen

Nuevo módulo `PaymentTransaction` con 4 endpoints (`POST`, `PUT`, `GET` por id, `GET` por uuid) sobre `payments_transactions` (cabecera) y `payments_transaction_details` (detalles). Es el primer módulo del proyecto que escribe **dos tablas de forma atómica**, así que requiere migrar ambas a InnoDB e introduce un `TransactionManager` en Core.

Diferencias clave respecto a los specs 07–10:

1. **Transacciones de BD reales** (InnoDB + `TransactionManager`). El Service define el límite transaccional; el Repository solo ejecuta sentencias.
2. **Dos modos de cálculo de `taxes`**, decididos por el procedimiento (ADR-002, regla confirmada con el equipo):
   - **Manual**: el procedimiento **no** tiene impuestos configurados activos → `taxes` es el valor que envía el cliente en la cabecera y no se aceptan detalles.
   - **Calculado**: el procedimiento **sí** tiene impuestos configurados → `taxes = Σ(quantity × amount)` de los detalles válidos; el `taxes` del request se ignora.
3. **POST idempotente por `uuid`** (offline-first): si el uuid ya existe en la notaría, responde 200 con la transacción existente sin modificarla. La unicidad la garantiza un índice `UNIQUE (id_notary, uuid)`.
4. **Respuestas con body** (201/200 con la transacción completa). Es una excepción deliberada al ADR-005 de los specs 08/09/10 (ADR-004).
5. **El PUT conserva lo que ya existía** aunque hoy esté inactivo (cliente, procedimiento sin cambio, impuestos de detalles previos) (ADR-006).
6. **La v1 sigue escribiendo en estas tablas en producción.** La migración es el punto de mayor riesgo del feature (ver Riesgos y Fase 0).

### Hallazgos en la BD local (2026-11-11)

| Dato | Valor |
|------|-------|
| Transacciones / detalles | 41 / 13 (solo 3 transacciones tienen detalles) |
| Motor y collation | MyISAM, `utf8mb3_bin` (el `uuid` es case-sensitive) |
| uuid duplicado | 1: `(id_notary=1, uuid='NdARZM')`, ids 16 y 17, filas idénticas, ninguna con detalles |
| uuid vacío / longitud máx. | 0 / 6 caracteres |
| Pares `(id_transaction, id_tax)` duplicados | 0 |
| Detalles huérfanos | 0 |
| Transacciones con `taxes > 0` y **sin** detalles | 20, **todas** de procedimientos sin impuestos configurados |
| Transacciones de procedimientos con impuestos | 6 (3 con detalles, 3 con `taxes = 0`) |
| Detalles con impuesto fuera de la configuración del procedimiento | 0 |
| Transacciones de clientes inactivos o inexistentes | 8 |
| `total ≠ fee + taxes` | 0 |
| `date` con hora distinta de 00:00:00 | 0 |

Los datos coinciden exactamente con la regla de los dos modos: la cabecera manda cuando el procedimiento no tiene impuestos.

## Arquitectura Propuesta

### Diagrama de Componentes

```
HTTP  POST /api/v3/payment-transactions
      GET  /api/v3/payment-transactions?uuid=...
      GET  /api/v3/payment-transactions/{id}
      PUT  /api/v3/payment-transactions/{id}
    │
    ▼
Router ──► AuthMiddleware (JWT → notaryId)  ── 401
               │
               ▼
     PaymentTransactionController
       │  parseId('id')           → 400 "Invalid ID format" | 404 "Transaction not found"
       │  uuid query param        → 400 "uuid query parameter is required" | "Invalid uuid format"
       │  DTO::fromArray()        → 400 "Validation failed" + errors
       │  catch TransactionException → match(httpStatus) → 400 | 404 | 422
       ▼
     PaymentTransactionService
       │  create():  uuid existente → devuelve la existente (created = false)
       │             cliente (404/422) → procedimiento (404/422) → modo de taxes
       │             → filtrar detalles → TransactionManager { INSERT cabecera + INSERT detalles }
       │             → uuid duplicado por carrera (1062) → relee y devuelve la existente
       │  update():  TransactionManager { SELECT ... FOR UPDATE → reglas → DELETE detalles
       │                                   → INSERT detalles → UPDATE cabecera }
       ├──► PaymentClientRepository::findByIdAndNotary()
       ├──► PaymentProcedureRepository::findByIdAndNotary()
       ├──► PaymentProcedureTaxRepository::findActiveByProcedure()   (¿modo manual o calculado?)
       ├──► PaymentTaxRepository::findByIdsAndNotary()
       ├──► TransactionManager::transactional()                       (Core/Database)
       ▼
     PaymentTransactionRepository ──► payments_transactions          (InnoDB, UNIQUE id_notary+uuid)
                                  ──► payments_transaction_details   (InnoDB, PK id_transaction+id_tax)
       ▼
     PaymentTransaction + PaymentTransactionDetail[] (read models con clientName / procedureName / taxName)
       ▼
     TransactionResponse
```

### Módulos Afectados

- [ ] `src/Module/PaymentTransaction/` - **Nuevo** módulo (Entity×2, DTO×6, Exception×3, Repository, Service + resultado, Controller)
- [ ] `src/Core/Database/TransactionManager.php` - **Nuevo**. Envuelve `beginTransaction/commit/rollBack` de PDO
- [ ] `src/bootstrap.php` - Registrar `TransactionManager`, Repository y Service
- [ ] `src/Config/routes.php` - 4 rutas protegidas
- [ ] `.db/pending/` - 2 migraciones (limpieza de duplicados; InnoDB + índices)
- [ ] `docs/openapi.yaml` - Paths `/payment-transactions` y `/payment-transactions/{id}`
- [ ] `tests/Module/PaymentTransaction/` y `tests/Core/Database/` - Tests unitarios

**No se modifican:** `PaymentClient*`, `PaymentProcedure*`, `PaymentTax*`, `PaymentProcedureTax*`. Todos los métodos de repositorio que se necesitan ya existen (`findByIdAndNotary`, `findByIdsAndNotary`, `findActiveByProcedure`).

## Reglas de Negocio Consolidadas

### Modo de cálculo de `taxes` (ADR-002)

El modo se decide con `PaymentProcedureTaxRepository::findActiveByProcedure($procedureId, $notaryId)` sobre el **procedimiento final** de la transacción (en PUT, el que viene en el request):

| | Modo manual (lista vacía) | Modo calculado (lista no vacía) |
|---|---|---|
| `taxes` del request | Se guarda tal cual (default 0) | Se ignora |
| `details` del request | Se ignoran silenciosamente (el frontend normalmente los envía vacíos o ausentes) | Se filtran y se guardan |
| `taxes` guardado | `round(request.taxes, 2)` | `round(Σ round(quantity × amount, 2), 2)` |
| `total` guardado | `round(fee + taxes, 2)` | `round(fee + taxes, 2)` |

### Filtrado de detalles (solo modo calculado)

Se recorren en el orden del request:

1. `taxId` ya visto en el mismo request → se ignora (gana el primero).
2. Impuesto inexistente o de otra notaría → se ignora silenciosamente.
3. Impuesto inactivo → se ignora silenciosamente, **excepto en PUT** cuando ese `taxId` ya era un detalle de la transacción (se conserva, ADR-006).
4. El resto se guarda. No se exige que el impuesto esté en la configuración del procedimiento (el PO acepta cualquier impuesto activo de la notaría).

Si todos los detalles se ignoran, la transacción es válida con `taxes = 0`.

### Precedencia de validaciones

**POST:** 401 → 400 body (DTO) → uuid existente → **200** (idempotencia) → cliente 404/422 → procedimiento 404/422 → escritura → 201.

**PUT:** 401 → 400 formato de `{id}` → 400 body (DTO) → 404 transacción → 400 `Cannot change client` → 400 `Cannot change UUID` → procedimiento 404/422 (solo si cambia) → escritura → 200.

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 2 h (incluye revisión de la v1)

#### 0.1 Revisar cómo escribe la v1 en estas tablas
- **Archivo(s):** Código de la v1 (fuera de este repo)
- **Tipo:** Investigación. **Bloquea el deploy de 0.3 a producción, no el desarrollo.**
- **Descripción:** Responder por escrito en el PR:
  1. ¿El guardado de la v1 hace INSERT nuevo cuando el uuid ya existe (origen probable del duplicado 16/17) o hace UPDATE?
  2. ¿Cómo guarda los detalles al editar: `DELETE` + `INSERT`, o `INSERT` sin borrar? ¿Puede insertar dos veces el mismo `id_tax` en una transacción?
  3. ¿Puede insertar transacciones con `uuid = ''`?
  4. ¿Lee `SHOW TABLE STATUS`, usa `LOCK TABLES`, `INSERT DELAYED` o algo específico de MyISAM?
- **Dependencias:** Ninguna
- **Resultado esperado:** Si (1), (2) o (3) son "sí", la v1 fallará con el UNIQUE/PK. En ese caso hay que corregir la v1 **antes** de aplicar 0.3 en producción, o avisar al arquitecto para replantear los índices.

#### 0.2 Migración de limpieza de duplicados
- **Archivo:** `.db/pending/20261111_100000_dedupe_payments_transactions_uuid.sql`
- **Tipo:** Crear
- **Descripción:** Borra primero los **detalles** de las transacciones duplicadas que se van a eliminar y después las transacciones, conservando el menor `id_transaction` de cada `(id_notary, uuid)`. El script del PO no borraba los detalles y dejaría huérfanos. Incluye los pre-checks en comentarios (SQL completo en `developer_notes.md`).
- **Dependencias:** Ninguna

#### 0.3 Migración InnoDB + índices
- **Archivo:** `.db/pending/20261111_100100_payments_transactions_innodb_and_indexes.sql`
- **Tipo:** Crear
- **Descripción:**
  - `ALTER TABLE payments_transactions ENGINE=InnoDB`
  - `ALTER TABLE payments_transaction_details ENGINE=InnoDB`
  - `UNIQUE INDEX uq_payments_transactions_notary_uuid (id_notary, uuid)`
  - `PRIMARY KEY (id_transaction, id_tax)` en detalles
  - `INDEX idx_payments_transactions_notary_client (id_notary, id_client)` (en lugar del `idx_client (id_client)` del PO: todas las consultas filtran por notaría)
- **Dependencias:** 0.2

### Fase 1: Core
Estimación: 30 min

#### 1.1 `TransactionManager`
- **Archivo:** `src/Core/Database/TransactionManager.php`
- **Tipo:** Crear (clase **no** `final`, para poder mockearla)
- **Descripción:** Constructor con `PDO`. Un método `transactional(callable $operation): mixed`: `beginTransaction()` → ejecuta → `commit()` y devuelve el resultado; ante cualquier `\Throwable` hace `rollBack()` (si `inTransaction()`) y relanza. Si ya hay una transacción abierta, ejecuta el callable sin abrir otra (sin transacciones anidadas).
- **Dependencias:** Ninguna

### Fase 2: Dominio (Entities, errores, DTOs)
Estimación: 2.5 h

#### 2.1 Entity `PaymentTransactionDetail`
- **Archivo:** `src/Module/PaymentTransaction/Entity/PaymentTransactionDetail.php`
- **Tipo:** Crear
- **Descripción:** `final` readonly: `taxId`, `taxName` (string, `''` si el impuesto ya no existe), `quantity` (int), `amount` (float), `date` (`?string` `Y-m-d`), `comments` (string). `fromRow()` mapea `id_tax`, `tax_name`, `quantity` (NULL → 1), `amount` (NULL → 0.0), `date` (datetime → `Y-m-d` o null), `comments` (NULL → `''`).
- **Dependencias:** Ninguna

#### 2.2 Entity `PaymentTransaction`
- **Archivo:** `src/Module/PaymentTransaction/Entity/PaymentTransaction.php`
- **Tipo:** Crear
- **Descripción:** `final` readonly: `id`, `uuid`, `notaryId`, `clientId`, `clientName`, `procedureId`, `procedureName`, `date` (`Y-m-d`), `fee`, `taxes`, `total`, `reference`, `responsible`, `comments`, `statusId`, `details` (`PaymentTransactionDetail[]`). `fromRow(array $row, array $details)`. `STATUS_ACTIVE = 1`. Método `detailTaxIds(): int[]`.
- **Dependencias:** 2.1

#### 2.3 Enum `TransactionError` + `TransactionException` + `DuplicateTransactionUuidException`
- **Archivos:** `src/Module/PaymentTransaction/Exception/{TransactionError,TransactionException,DuplicateTransactionUuidException}.php`
- **Tipo:** Crear
- **Descripción:** Mismo patrón que `ProcedureTaxError` (spec 10, ADR-004). Casos:

  | Caso | Mensaje | HTTP |
  |------|---------|------|
  | `TransactionNotFound` | `Transaction not found` | 404 |
  | `ClientNotFound` | `Client not found` | 404 |
  | `ClientInactive` | `Client is inactive` | 422 |
  | `ProcedureNotFound` | `Procedure not found` | 404 |
  | `ProcedureInactive` | `Procedure is inactive` | 422 |
  | `CannotChangeClient` | `Cannot change client` | 400 |
  | `CannotChangeUuid` | `Cannot change UUID` | 400 |

  `TransactionException(TransactionError $error)` extiende `\DomainException`. `DuplicateTransactionUuidException` extiende `\RuntimeException`; la lanza **solo** el Repository cuando el INSERT choca con el UNIQUE, y la consume el Service.
- **Dependencias:** Ninguna

#### 2.4 Reglas `TransactionFieldRules`
- **Archivo:** `src/Module/PaymentTransaction/DTO/TransactionFieldRules.php`
- **Tipo:** Crear
- **Descripción:** Métodos estáticos reutilizados por los DTOs de POST y PUT (estilo `ClientFieldRules`):
  - `positiveInt(mixed $v): bool` → `is_int` y `> 0`.
  - `uuid(mixed $v): ?string` → string, `^[A-Za-z0-9_-]{1,20}$`. **Sin** trim ni cambio de mayúsculas.
  - `date(mixed $v): bool` → string `Y-m-d` real (round-trip con `DateTimeImmutable::createFromFormat('!Y-m-d')`).
  - `money(mixed $v): bool` → `is_int|is_float`, finito, `0 ≤ v ≤ 999999999.99`.
  - `text(mixed $v): bool` → string con `strlen($v) ≤ 65535` (bytes, por ser columna `TEXT`).
  - Constantes `MAX_DETAILS = 50`, `MAX_QUANTITY = 9999`, `MAX_MONEY`.
- **Dependencias:** Ninguna

#### 2.5 VO `TransactionDetailItem`
- **Archivo:** `src/Module/PaymentTransaction/DTO/TransactionDetailItem.php`
- **Tipo:** Crear
- **Descripción:** `final readonly class TransactionDetailItem(int $taxId, int $quantity, float $amount, ?string $date, string $comments)`. Método `subtotal(): float` → `round($quantity * $amount, 2)`.
- **Dependencias:** Ninguna

#### 2.6 Parser `TransactionDetailsParser`
- **Archivo:** `src/Module/PaymentTransaction/DTO/TransactionDetailsParser.php`
- **Tipo:** Crear
- **Descripción:** `parse(mixed $details, array &$errors): TransactionDetailItem[]`. `null`/ausente → `[]`. Debe ser lista (`array_is_list`) de máx. 50 objetos. Por item: `taxId` requerido (`positiveInt`), `quantity` opcional (int, 1..9999, default 1), `amount` opcional (`money`, default 0.0), `date` opcional (`null` o `Y-m-d`), `comments` opcional (`null` → `''`, `text`). Claves de error `details`, `details.N`, `details.N.taxId`, etc. Los `taxId` repetidos **no** son error aquí (los resuelve el Service: gana el primero).
- **Dependencias:** 2.4, 2.5

#### 2.7 DTO `CreateTransactionRequest`
- **Archivo:** `src/Module/PaymentTransaction/DTO/CreateTransactionRequest.php`
- **Tipo:** Crear
- **Descripción:** `fromArray()/isValid()/getErrors()`. Requeridos: `clientId`, `procedureId` (`positiveInt`), `uuid`, `date`. Opcionales: `fee` (default 0.0), `taxes` (default 0.0), `reference`, `responsible`, `comments` (default `''`, se aplica `trim`), `details`. Montos normalizados con `round(…, 2)`.
- **Dependencias:** 2.4, 2.6

#### 2.8 DTO `UpdateTransactionRequest`
- **Archivo:** `src/Module/PaymentTransaction/DTO/UpdateTransactionRequest.php`
- **Tipo:** Crear
- **Descripción:** Igual que 2.7 con estas diferencias: `procedureId` y `date` requeridos; `clientId` (`?int`) y `uuid` (`?string`) **opcionales**, pero si vienen se validan de formato y el Service exige que coincidan con los actuales. Reemplazo completo: un campo opcional omitido toma su default (`fee = 0`, `taxes = 0`, textos `''`, `details = []`).
- **Dependencias:** 2.4, 2.6

#### 2.9 DTO `TransactionResponse`
- **Archivo:** `src/Module/PaymentTransaction/DTO/TransactionResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity(PaymentTransaction): array` con exactamente: `transactionId`, `uuid`, `clientId`, `clientName`, `procedureId`, `procedureName`, `date`, `fee`, `taxes`, `total`, `reference`, `responsible`, `comments`, `details[]` (`taxId`, `taxName`, `quantity`, `amount`, `date`, `comments`). No expone `notaryId` ni `status`.
- **Dependencias:** 2.2

### Fase 3: Repository
Estimación: 1.5 h

#### 3.1 `PaymentTransactionRepository`
- **Archivo:** `src/Module/PaymentTransaction/Repository/PaymentTransactionRepository.php`
- **Tipo:** Crear (clase no `final`). **No abre transacciones.**
- **Métodos:**
  - `findByIdAndNotary(int $id, int $notaryId, bool $forUpdate = false): ?PaymentTransaction` → cabecera con `LEFT JOIN` a clientes y procedimientos para los nombres + detalles con `LEFT JOIN` a impuestos. Con `$forUpdate` primero ejecuta `SELECT id_transaction ... FOR UPDATE` sobre la cabecera.
  - `findByUuidAndNotary(string $uuid, int $notaryId): ?PaymentTransaction`
  - `insert(...)`: INSERT de la cabecera con `taxes` y `total` ya calculados, `id_status = 1`. Devuelve el id. Si PDO lanza error 1062 → `DuplicateTransactionUuidException`.
  - `update(int $id, int $notaryId, ...)`: UPDATE de `id_procedure`, `date`, `fee`, `taxes`, `total`, `reference`, `responsible`, `comments` con `WHERE id_transaction = :id AND id_notary = :notaryId`. No toca `uuid`, `id_client`, `id_notary` ni `id_status`.
  - `deleteDetails(int $transactionId): void`
  - `insertDetails(int $transactionId, TransactionDetailItem[] $items): void` → un solo INSERT multi-row; lista vacía → no ejecuta SQL.
- **Dependencias:** 2.x, 0.3

### Fase 4: Service
Estimación: 3 h

#### 4.1 `CreateTransactionResult`
- **Archivo:** `src/Module/PaymentTransaction/Service/CreateTransactionResult.php`
- **Tipo:** Crear
- **Descripción:** `final readonly class CreateTransactionResult(PaymentTransaction $transaction, bool $created)`. Permite al Controller elegir 201 o 200 sin que el Service conozca HTTP.
- **Dependencias:** 2.2

#### 4.2 `PaymentTransactionService`
- **Archivo:** `src/Module/PaymentTransaction/Service/PaymentTransactionService.php`
- **Tipo:** Crear
- **Constructor:** `PaymentTransactionRepository`, `PaymentClientRepository`, `PaymentProcedureRepository`, `PaymentTaxRepository`, `PaymentProcedureTaxRepository`, `TransactionManager`.
- **Métodos:**
  - `getById(int $id, int $notaryId): PaymentTransaction` → `TransactionNotFound`.
  - `getByUuid(string $uuid, int $notaryId): PaymentTransaction` → `TransactionNotFound`.
  - `create(CreateTransactionRequest $dto, int $notaryId): CreateTransactionResult`
    1. `findByUuidAndNotary` → si existe, `new CreateTransactionResult($existing, created: false)`.
    2. Cliente: `ClientNotFound` / `ClientInactive`.
    3. Procedimiento: `ProcedureNotFound` / `ProcedureInactive`.
    4. `resolveDetails()` (modo + filtrado) y `calculateTotals()`.
    5. `transactional { insert cabecera; insertDetails }`.
    6. Si captura `DuplicateTransactionUuidException` (carrera): relee por uuid y devuelve `created: false`.
    7. Relee con nombres y devuelve `created: true`.
  - `update(int $id, UpdateTransactionRequest $dto, int $notaryId): PaymentTransaction` — todo dentro de `transactional`:
    1. `findByIdAndNotary($id, $notaryId, forUpdate: true)` → `TransactionNotFound`.
    2. `dto.clientId` presente y distinto → `CannotChangeClient`; `dto.uuid` presente y distinto → `CannotChangeUuid`.
    3. Si `dto.procedureId` ≠ actual: procedimiento nuevo debe existir y estar activo. Si no cambia, no se valida su status.
    4. `resolveDetails()` pasando `existing.detailTaxIds()` como impuestos a conservar.
    5. `deleteDetails` → `insertDetails` → `update` cabecera.
    6. Relee y devuelve.
  - Privados: `resolveDetails(int $procedureId, int $notaryId, array $items, array $keepTaxIds = []): array` y `calculateTotals(bool $manual, float $fee, float $requestTaxes, array $details): array{taxes: float, total: float}`.
- **Dependencias:** 1.1, 2.x, 3.1

### Fase 5: Controller, rutas y wiring
Estimación: 1 h

#### 5.1 `PaymentTransactionController`
- **Archivo:** `src/Module/PaymentTransaction/Controller/PaymentTransactionController.php`
- **Tipo:** Crear
- **Descripción:** `store`, `show`, `showByUuid`, `update`. `store` → `Response::created(body)` si `created`, si no `Response::json(body)` (200). `update`/`show`/`showByUuid` → 200 con body. `showByUuid`: sin `uuid` → 400 `uuid query parameter is required`; formato inválido → 400 `Invalid uuid format`. Mapper privado `errorResponse(TransactionError)` con `match` sobre `httpStatus()`: 400 → `badRequest`, 404 → `notFound`, 422 → `unprocessable`.
- **Dependencias:** 4.2

#### 5.2 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `TransactionManager` (con `Connection::getInstance()`), `PaymentTransactionRepository` y `PaymentTransactionService` con sus 6 dependencias. Es indispensable que el `TransactionManager` y los repositorios compartan la **misma** instancia de PDO (`Connection::getInstance()` ya es singleton).
- **Dependencias:** 1.1, 3.1, 4.2

#### 5.3 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:**
  ```php
  // Payment Transactions
  $router->get('/api/v3/payment-transactions', [PaymentTransactionController::class, 'showByUuid'])->withMiddleware('auth');
  $router->get('/api/v3/payment-transactions/{id}', [PaymentTransactionController::class, 'show'])->withMiddleware('auth');
  $router->post('/api/v3/payment-transactions', [PaymentTransactionController::class, 'store'])->withMiddleware('auth');
  $router->put('/api/v3/payment-transactions/{id}', [PaymentTransactionController::class, 'update'])->withMiddleware('auth');
  ```
  El Router empareja solo por path (`Request::getUri()` no incluye el query string), así que `?uuid=` se lee en el Controller con `getQueryParam('uuid')`.
- **Dependencias:** 5.1

### Fase 6: Tests y documentación
Estimación: 3.5 h

#### 6.1 Tests unitarios
- **Archivos:** `tests/Core/Database/TransactionManagerTest.php`, `tests/Module/PaymentTransaction/{Entity,DTO,Exception,Service}/...Test.php`
- **Tipo:** Crear
- **Descripción:** Casos mínimos en `technical_acceptance_criteria.md`. En el Service, el mock de `TransactionManager` ejecuta el callable (`willReturnCallback(fn (callable $op) => $op())`).
- **Dependencias:** Fases 1–4

#### 6.2 OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Tag `PaymentTransactions`, 2 paths, schemas `PaymentTransaction`, `PaymentTransactionDetail`, `CreatePaymentTransactionRequest`, `UpdatePaymentTransactionRequest`, `PaymentTransactionDetailInput`. Documentar explícitamente: los dos modos de `taxes`, la idempotencia del POST (200 vs 201), los detalles ignorados silenciosamente y que el PUT es reemplazo completo.
- **Dependencias:** 5.3

## Orden de Implementación

1. **Revisión de la v1 (0.1)**: en paralelo desde el primer día. Su resultado puede cambiar los índices.
2. **Migraciones en local (0.2, 0.3)**: sin InnoDB no hay atomicidad que probar.
3. **TransactionManager (1.1)** + test: pieza pequeña y aislada.
4. **Entities, enum y excepciones (2.1–2.3)**.
5. **Reglas, parser y DTOs (2.4–2.9)** + tests: es donde hay más casos de validación.
6. **Repository (3.1)**: probar contra la BD local, incluido el 1062.
7. **Service (4.1, 4.2)** + tests con mocks: el núcleo del feature.
8. **bootstrap (5.2) → Controller (5.1) → rutas (5.3)**: probar con curl/Postman, incluida la prueba de rollback.
9. **OpenAPI (6.2)**.

**Estimación total: 14 h** (el PO estimó 11–12 h; la diferencia es la revisión de la v1 y los dos modos de `taxes`).

## Catálogo de Errores (para frontend)

| HTTP | Campo en body | Mensaje | Causa |
|------|---------------|---------|-------|
| 400 | `error` | `Invalid ID format` | `{id}` no es entero |
| 400 | `error` | `uuid query parameter is required` | GET a la colección sin `?uuid=` |
| 400 | `error` | `Invalid uuid format` | `?uuid=` vacío, > 20 caracteres o con caracteres no permitidos |
| 400 | `error` | `Validation failed` | Body inválido; ver `errors` |
| 400 | `errors.clientId` | `Client ID is required` / `Client ID must be a positive integer` | POST |
| 400 | `errors.procedureId` | `Procedure ID is required` / `Procedure ID must be a positive integer` | POST y PUT |
| 400 | `errors.uuid` | `UUID is required` / `UUID must be 1 to 20 characters (letters, digits, - or _)` | POST (requerido); PUT (solo formato) |
| 400 | `errors.date` | `Date is required` / `Date must be a valid date in YYYY-MM-DD format` | POST y PUT |
| 400 | `errors.fee` | `Fee must be a number` / `Fee cannot be negative` / `Fee is too large` | |
| 400 | `errors.taxes` | `Taxes must be a number` / `Taxes cannot be negative` / `Taxes is too large` | |
| 400 | `errors.reference` / `responsible` / `comments` | `<Field> must be a string` / `<Field> is too long` | |
| 400 | `errors.details` | `Details must be an array` / `Details must not exceed 50 items` | |
| 400 | `errors.details.N` | `Each detail must be an object` | |
| 400 | `errors.details.N.taxId` | `Tax ID must be a positive integer` | Falta o tipo incorrecto |
| 400 | `errors.details.N.quantity` | `Detail quantity must be an integer` / `Detail quantity must be at least 1` / `Detail quantity is too large` | |
| 400 | `errors.details.N.amount` | `Detail amount must be a number` / `Detail amount cannot be negative` / `Detail amount is too large` | |
| 400 | `errors.details.N.date` | `Detail date must be a valid date in YYYY-MM-DD format` | |
| 400 | `errors.details.N.comments` | `Detail comments must be a string` / `Detail comments is too long` | |
| 400 | `error` | `Cannot change client` | PUT con `clientId` distinto al actual |
| 400 | `error` | `Cannot change UUID` | PUT con `uuid` distinto al actual |
| 401 | `error` | (mensaje del AuthMiddleware) | Token ausente, inválido o expirado |
| 404 | `error` | `Transaction not found` | No existe o es de otra notaría (GET/PUT) |
| 404 | `error` | `Client not found` | POST: cliente inexistente o de otra notaría |
| 404 | `error` | `Procedure not found` | POST, o PUT cambiando de procedimiento |
| 422 | `error` | `Client is inactive` | POST |
| 422 | `error` | `Procedure is inactive` | POST, o PUT cambiando de procedimiento |

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| La v1 inserta un uuid repetido (o detalles repetidos) después de crear el UNIQUE/PK → la v1 falla al guardar en producción | **Baja** (known issue: la v1 no debería duplicar bajo ninguna circunstancia; el caso 16/17 es un bug existente) | **Medio** | Tarea 0.1 obligatoria antes del deploy de la migración. Si la v1 duplica, es un bug de la v1 que hay que corregir independientemente. DOWN documentado |
| Producción tiene más duplicados que local, y no son filas idénticas | Media | Alto | Pre-checks obligatorios. Si las filas duplicadas difieren o alguna tiene detalles, **detenerse y avisar**; no ejecutar el DELETE a ciegas |
| Producción tiene muchas filas con `uuid = ''` (chocan entre sí con el UNIQUE) | Baja (local: 0) | Alto | Pre-check. Si existen, avisar al arquitecto (opción: rellenarlas con un uuid generado) |
| `ALTER ... ENGINE=InnoDB` bloquea la tabla mientras la v1 está en uso | Baja (tablas pequeñas) | Medio | Ejecutar en ventana de bajo uso; respaldo de ambas tablas antes |
| El frontend omite `taxes` en un PUT de modo manual → `taxes` queda en 0 | Media | Alto | PUT es reemplazo completo y se documenta en OpenAPI. El GET siempre devuelve `taxes`; el frontend debe reenviarlo. Caso en el test plan de QA |
| Se configuran impuestos a un procedimiento que ya tiene transacciones en modo manual → al editarlas pasan a modo calculado y `taxes` se recalcula desde los detalles (0 si no hay) | Media | Medio | Comportamiento definido por la regla de negocio; documentado en ADR-002 y FAQ. Las transacciones no editadas no cambian |
| Carrera: dos POST simultáneos con el mismo uuid | Media (doble clic, reintento) | Bajo | UNIQUE + captura del 1062 + relectura → el segundo responde 200 con la existente |
| Carrera: dos PUT simultáneos sobre la misma transacción | Baja | Medio | `SELECT ... FOR UPDATE` de la cabecera al inicio de la transacción |
| Precisión de `double` en sumas de dinero | Media | Bajo | Redondeo a 2 decimales en cada subtotal y en los totales. Migrar a `DECIMAL` queda fuera de alcance (la v1 comparte las columnas) |
| Excepción dentro de la transacción deja la conexión con una transacción abierta | Baja | Alto | `TransactionManager` hace `rollBack()` ante cualquier `\Throwable`; cubierto por test |
| Detalles ignorados silenciosamente confunden al usuario (envió 3, se guardaron 2) | Media | Bajo | Requerimiento explícito del PO. La respuesta devuelve los detalles guardados, así que el frontend puede comparar |
| POST con uuid existente y payload distinto devuelve la versión vieja | Baja | Bajo | Comportamiento idempotente pedido por el PO; para modificar se usa PUT. Documentado |

## Decisiones de Arquitectura

### ADR-001: `TransactionManager` en Core en lugar de inyectar `PDO` al Service
- **Contexto:** El PO propone inyectar `PDO` en el Service para `beginTransaction/commit/rollBack`. Eso acopla la lógica de negocio a la infraestructura, repite el try/catch en cada método y obliga a mockear PDO en los tests.
- **Decisión:** `App\Core\Database\TransactionManager::transactional(callable): mixed`. El Service decide **qué** es atómico; el manager sabe **cómo**. El Repository nunca abre transacciones.
- **Consecuencias:** Reutilizable por features futuros (pagos). El mock en tests es una línea. Todos los repositorios deben compartir la misma conexión PDO, lo cual ya es cierto por el singleton de `Connection`.

### ADR-002: Dos modos de cálculo de `taxes` según el procedimiento
- **Contexto:** El PO pide que el servidor siempre calcule `taxes = Σ detalles`. En la BD local, 20 de 41 transacciones tienen `taxes > 0` sin detalles, y todas pertenecen a procedimientos sin impuestos configurados. Con la regla del PO, editar cualquiera de ellas pondría `taxes = 0`.
- **Decisión (confirmada con el equipo):** Si el procedimiento **no** tiene impuestos configurados activos, la cabecera manda: `taxes` es un campo de entrada (opcional, ≥ 0, default 0) y los detalles se ignoran silenciosamente (el frontend normalmente los envía vacíos). Si **sí** tiene, `taxes` se calcula y el valor del request se ignora. "Tiene impuestos" significa que `findActiveByProcedure()` devuelve al menos una fila (asignación activa de un impuesto activo, misma definición que el ADR-006 del spec 10).
- **Consecuencias:** Se agrega `taxes` a los requests de POST y PUT (cambio respecto al contrato del PO). En modo calculado se acepta cualquier impuesto activo de la notaría, no solo los configurados en el procedimiento. Una transacción puede cambiar de modo al editarse si cambió su procedimiento o la configuración de este; el modo siempre se evalúa en el momento de escribir.

### ADR-003: Idempotencia del POST: el uuid se revisa **antes** que cliente y procedimiento
- **Contexto:** El PO ordena: validar cliente → validar procedimiento → revisar uuid. Con ese orden, un reintento offline que llega después de que alguien desactivó al cliente respondería 422 aunque la transacción ya se había creado, y el cliente offline no sabría que su operación tuvo éxito.
- **Decisión:** Orden: validación de body (400) → uuid existente (200 con la existente) → cliente → procedimiento. Además, la carrera entre dos POST simultáneos se resuelve con el índice UNIQUE: el Repository traduce el error 1062 a `DuplicateTransactionUuidException` y el Service relee y devuelve la existente.
- **Consecuencias:** Un reintento es exitoso siempre que el uuid exista, sin importar el estado actual del catálogo. No se compara el payload: un POST con uuid existente y datos distintos devuelve la transacción original (escenario 3 del PO). El uuid es único por notaría; dos notarías pueden tener el mismo uuid.

### ADR-004: Respuestas con body (excepción al ADR-005 de los specs 08/09/10)
- **Contexto:** El patrón del proyecto es 201 sin body y 204. Aquí el servidor genera `transactionId`, calcula `taxes`/`total`, puede descartar detalles y expande nombres. El cliente offline-first necesita ese estado final para reconciliar.
- **Decisión (confirmada con el equipo):** POST → 201 con la transacción (200 si ya existía); PUT → 200 con la transacción; GET → 200. Siempre el mismo schema `PaymentTransaction`.
- **Consecuencias:** El Service relee la transacción después de escribir (2 queries extra). Los catálogos 07–10 conservan su patrón sin body.

### ADR-005: Errores de validación en el formato del proyecto, no en el del PO
- **Contexto:** El PO muestra `{"error": "Fee cannot be negative"}` y `{"error": "Required fields: clientId, procedureId, uuid, date"}`. El proyecto usa `{"error": "Validation failed", "errors": {campo: mensaje}}`, que permite reportar varios campos a la vez y ubicar el detalle con error.
- **Decisión:** Formato del proyecto. Los textos del PO se conservan como mensaje del campo (`errors.fee = "Fee cannot be negative"`, `errors["details.0.quantity"] = "Detail quantity must be at least 1"`). `Cannot change client` y `Cannot change UUID` sí son `{"error": "..."}` planos, porque son errores de dominio que dependen del estado guardado.
- **Consecuencias:** Los escenarios 19 y 20 del PO se verifican con `errors.<campo>` en lugar de `error`. QA debe ajustar esas aserciones.

### ADR-006: El PUT conserva lo existente aunque hoy esté inactivo
- **Contexto:** 8 transacciones locales pertenecen a clientes inactivos. Con las reglas del POST aplicadas al PUT, esas transacciones no se podrían corregir, y un detalle cuyo impuesto se desactivó después desaparecería al editar cualquier otro campo.
- **Decisión (confirmada con el equipo):** En PUT: (a) no se valida el status del cliente; (b) el procedimiento solo se valida (404/422) si cambia; (c) un detalle con impuesto inactivo se conserva si ese `taxId` ya estaba en la transacción, y se ignora solo si es nuevo.
- **Consecuencias:** El Service necesita los `taxId` actuales antes de borrar los detalles. Un impuesto inactivo que el usuario quita en un PUT ya no se puede volver a agregar.

### ADR-007: `clientId` y `uuid` opcionales en el PUT
- **Contexto:** El PO envía `clientId` y `uuid` en el body del PUT solo para rechazar su cambio (escenarios 15 y 16). La identidad del recurso ya está en el path.
- **Decisión:** Ambos son opcionales en PUT. Si vienen, deben tener formato válido y coincidir con los valores guardados; si no coinciden → 400.
- **Consecuencias:** El frontend puede reenviar el objeto completo que recibió del GET sin limpiarlo, o enviar solo los campos editables.

### ADR-008: Índices únicos sin llaves foráneas; se conserva `double` y `utf8mb3_bin`
- **Contexto:** Con InnoDB se podrían agregar FKs y pasar los montos a `DECIMAL`, pero la v1 sigue escribiendo en las tablas y las tablas padre (`payments_clients`, `payment_procedures`, `payment_taxes`) siguen en MyISAM, que no admite FKs.
- **Decisión:** Solo motor InnoDB + `UNIQUE (id_notary, uuid)` + `PK (id_transaction, id_tax)` + `INDEX (id_notary, id_client)`. Sin FKs, sin cambio de tipos ni de collation. El índice de cliente lleva `id_notary` como prefijo porque todas las consultas filtran por notaría.
- **Consecuencias:** La integridad referencial sigue siendo responsabilidad de la aplicación. El `uuid` sigue siendo case-sensitive (`utf8mb3_bin`), por lo que el DTO no debe normalizar mayúsculas. `DECIMAL` y FKs quedan como deuda técnica para cuando la v1 se retire.

### ADR-009: GET por uuid sobre la ruta de colección
- **Contexto:** El PO define `GET /payment-transactions?uuid=...` y deja el listado para otro spec.
- **Decisión:** La ruta de colección queda registrada con `showByUuid`, que devuelve **un objeto** (no un array). Sin `uuid` responde 400 `uuid query parameter is required`.
- **Consecuencias:** Cuando se diseñe el listado, esa misma ruta tendrá que distinguir "búsqueda por uuid → objeto" de "listado → array". Se recomienda que ese spec evalúe mover la búsqueda a `/payment-transactions/by-uuid/{uuid}` o devolver siempre un array.
