# Criterios de Aceptación Técnicos

## Controller Layer
- [ ] `POST /api/v3/payments` → 201 con el pago creado, 200 si el uuid ya existía
- [ ] `GET /api/v3/payments/{id}` → 200 / 404
- [ ] `GET /api/v3/payments?uuid=` → 200 / 404
- [ ] `GET /api/v3/payments` sin `uuid` → 400 `{"error": "uuid query parameter is required"}`
- [ ] `GET /api/v3/payments?uuid=<formato inválido o uuid[]=x>` → 400 `{"error": "Invalid uuid format"}`
- [ ] `PUT /api/v3/payments/{id}` → 200 con el pago actualizado
- [ ] `id` no entero → 400 `Invalid ID format`; `id <= 0` → 404 `Payment not found`
- [ ] Body inválido → 400 `{"error": "Validation failed", "errors": {...}}`
- [ ] `PaymentError` se traduce con `match` por `httpStatus()` (400 / 404 / 422)
- [ ] Todas las rutas con middleware `auth`; `notaryId` siempre desde `$request->getNotaryId()`, nunca del body
- [ ] `GET /api/v3/payments` registrado como `showByUuid` (no hay listado)

## Service Layer
- [ ] `create`: busca uuid en la notaría **antes** de validar el cliente
- [ ] `create` con uuid existente retorna `CreatePaymentResult(created: false)` sin modificar ni consultar el cliente
- [ ] `create`: cliente inexistente o de otra notaría → `ClientNotFound` (404); `statusId !== PaymentClient::STATUS_ACTIVE` → `ClientInactive` (422)
- [ ] `create`: `DuplicatePaymentUuidException` → recarga por uuid y retorna `created: false`; si la recarga falla lanza `LogicException`
- [ ] `update`: pago inexistente o de otra notaría → `PaymentNotFound`
- [ ] `update`: `clientId` presente y distinto → `CannotChangeClient`; `uuid` presente y distinto (comparación estricta, sensible a mayúsculas) → `CannotChangeUuid`
- [ ] `update`: `uuid` y `clientId` iguales a los almacenados → no es cambio (200)
- [ ] `update`: `paymentDate`/`comments` omitidos o `null` conservan el valor actual
- [ ] `update` **no** valida que el cliente esté activo
- [ ] Sin `TransactionManager` (una sola sentencia por operación)
- [ ] El Service no conoce HTTP (el 201/200 se decide con `CreatePaymentResult`)

## Repository Layer
- [ ] Todas las queries filtran por `id_notary`; el `JOIN` a `payments_clients` también (`c.id_notary = p.id_notary`)
- [ ] `findByIdAndNotary` y `findByUuidAndNotary` retornan `?Payment` con `clientName`
- [ ] El `WHERE uuid = :uuid` no transforma la columna (usa el índice y respeta `utf8mb3_bin`)
- [ ] `insert` lanza `DuplicatePaymentUuidException` solo cuando `errorInfo[1] === 1062`; otros errores se relanzan
- [ ] `update` no modifica `uuid`, `id_client` ni `id_notary`; no verifica `rowCount()`
- [ ] `payment_date` se guarda como `YYYY-MM-DD 00:00:00`
- [ ] Prepared statements en todo; sin placeholders con nombre repetidos en una misma sentencia
- [ ] El Repository nunca abre transacciones

## Entity/DTO
- [ ] `Payment` `final`, readonly, con `fromRow()`; `paymentDate` es `?string` (`YYYY-MM-DD` o `null` en legacy); `comments` `null` → `""`
- [ ] `CreatePaymentRequest`: requeridos `clientId` (int > 0), `uuid`, `amount`; defaults `paymentDate` = hoy `America/Mexico_City`, `comments` = `""`
- [ ] `UpdatePaymentRequest`: `amount` requerido; `paymentDate`, `comments`, `uuid`, `clientId` opcionales
- [ ] `amount`: solo int/float finito, `> 0`, `<= 999999999.99`; string numérico (`"5000"`) rechazado; 0 y negativos → `errors.amount = "Amount must be greater than zero"`
- [ ] `paymentDate`: formato `YYYY-MM-DD` con round-trip (rechaza `2024-02-30` y `2024-1-5`); pasado y futuro aceptados
- [ ] `comments`: string, máx 65535 bytes
- [ ] `PaymentResponse` expone `paymentId, uuid, clientId, clientName, amount, paymentDate, comments`; nunca expone `notaryId`
- [ ] Amount con más de 2 decimales se guarda y devuelve sin redondear

## Utilidad compartida `ClientUuid`
- [ ] `src/Shared/Uuid/ClientUuid.php`, `final`, con `DEFAULT_LENGTH = 6`, `MAX_LENGTH = 20`
- [ ] `validate()` acepta `^[A-Za-z0-9_-]{1,20}$` (6 y 20 caracteres válidos)
- [ ] Rechaza: vacío, solo espacios, 21 caracteres, espacios al inicio/fin (sin `trim`), no-string, caracteres fuera del conjunto
- [ ] `abc` y `ABC` son valores distintos (sin cambio de mayúsculas)
- [ ] `TransactionFieldRules::uuid()` delega en `ClientUuid`; la suite de spec 11 sigue en verde sin modificar sus aserciones

## Base de datos
- [ ] Migración en `.db/pending/` con pre-checks, UP y DOWN comentado
- [ ] `payments_payments` en InnoDB
- [ ] `UNIQUE uq_payments_payments_notary_uuid (id_notary, uuid)` e índice `idx_payments_payments_notary_client (id_notary, id_client)`
- [ ] Pre-checks de producción A–D (tarea 0.1) ejecutados y documentados antes del deploy

## Tests
- [ ] Unit tests: `ClientUuid`, `PaymentFieldRules`, `Create/UpdatePaymentRequest`, `PaymentResponse`, `Payment`, `PaymentError`
- [ ] Unit tests de `PaymentService` con mocks: idempotencia (`PaymentClientRepository` con `never()`), carrera 1062, 404/422 de cliente, PUT sin validar cliente activo, `CannotChange*`, reenvío de uuid/clientId iguales, fusión de omitidos
- [ ] Suite de `PaymentTransaction` sin regresiones
- [ ] Verificación de los 18 escenarios del PO contra Docker con dos notarías

## Código
- [ ] PSR-12, `declare(strict_types=1)`
- [ ] Typed properties, clases `final` y readonly donde aplique
- [ ] SOLID: Controller → Service → Repository, sin lógica de negocio en el Controller
- [ ] Comentarios en inglés, sin código muerto

## Performance
- [ ] GET por id: PK; GET por uuid: índice `uq_payments_payments_notary_uuid`
- [ ] Un solo query por lectura (`LEFT JOIN`), sin N+1
- [ ] Operaciones en menos de 500 ms
- [ ] Sin paginación (no hay listado)

## Seguridad
- [ ] JWT requerido en los 4 endpoints
- [ ] Multi-tenant: pago o cliente de otra notaría → 404, nunca 403
- [ ] El mismo uuid en otra notaría crea un pago independiente y no expone el de la otra
- [ ] Input por prepared statements; `uuid` validado por regex antes de llegar a la BD
