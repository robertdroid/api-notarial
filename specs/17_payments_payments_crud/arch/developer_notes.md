# Notas para el Developer

## Contexto Rápido

Hay que poder registrar, corregir y consultar **pagos** (abonos) de un cliente: dinero que el cliente entrega. Son 4 endpoints sobre `payments_payments`. No es lo mismo que una transacción (`payments_transactions`, spec 11), que es un cargo.

Es **spec 11 simplificado**: mismo UUID idempotente, mismas inmutabilidades, mismo manejo de carrera, pero **sin detalles ni `TransactionManager`**. Copia el módulo `PaymentTransaction` y quita lo de detalles/impuestos/procedimiento.

Lo nuevo respecto a spec 11:

- **Utilidad compartida `ClientUuid`** en `src/Shared/Uuid/`. El frontend genera UUID de **6 caracteres**; el backend valida de **1 a 20** (la columna es `varchar(20)`). Hoy está duplicada en `TransactionFieldRules::uuid()`: se mueve y se delega.
- **`paymentDate` puede ser `NULL`** en datos legacy: se devuelve `null`, y un PUT sin fecha la conserva.
- **PUT con `null`/omitido conserva el valor**; spec 11 trata `null` como vacío. Para vaciar los comentarios se manda `""`.
- **PUT no valida cliente activo** (escenario 18). Solo el POST lo hace.
- **La v1 sigue escribiendo unos días** en esta tabla: lee la tarea 0.1 del plan antes de aplicar la migración en producción.

Ante la duda, mandan los ADRs del plan.

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Shared/Uuid/ClientUuid.php` | **Nuevo.** Formato de UUID compartido (6 por defecto, máx 20) |
| `src/Module/PaymentTransaction/DTO/TransactionFieldRules.php` | **Modificar.** `uuid()` delega en `ClientUuid` |
| `src/Module/Payment/Controller/PaymentController.php` | `store`, `show`, `showByUuid`, `update`; traduce excepciones a HTTP |
| `src/Module/Payment/Service/PaymentService.php` | Idempotencia, validación de cliente, inmutabilidad |
| `src/Module/Payment/Service/CreatePaymentResult.php` | `{payment, created}` para decidir 201 o 200 |
| `src/Module/Payment/Repository/PaymentRepository.php` | SELECT con `LEFT JOIN` a clientes, INSERT (captura 1062), UPDATE |
| `src/Module/Payment/Entity/Payment.php` | Read model (incluye `clientName`) |
| `src/Module/Payment/Exception/PaymentError.php` | Enum: mensaje + código HTTP |
| `src/Module/Payment/Exception/PaymentException.php` | Excepción de dominio |
| `src/Module/Payment/Exception/DuplicatePaymentUuidException.php` | Choque con el UNIQUE |
| `src/Module/Payment/DTO/PaymentFieldRules.php` | Reglas de validación compartidas |
| `src/Module/Payment/DTO/{Create,Update}PaymentRequest.php` | Body de POST / PUT |
| `src/Module/Payment/DTO/PaymentResponse.php` | Entity → array de API |
| `src/bootstrap.php` | Registrar `PaymentRepository` y `PaymentService` |
| `src/Config/routes.php` | 4 rutas |
| `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql` | Migración |
| `docs/openapi.yaml` | Paths y schemas |

## Patrones a Seguir

- `src/Module/PaymentTransaction/`: referencia directa. Copia `TransactionError`/`TransactionException`/`DuplicateTransactionUuidException`, el `errorResponse()` y `parseId()` del Controller, `CreateTransactionResult`, y el `catch` del 1062 en el Repository.
- `tests/Module/PaymentTransaction/Service/PaymentTransactionServiceTest.php`: cómo mockear repositorios y verificar `never()`.
- `.db/applied/20261111_100100_payments_transactions_innodb_and_indexes.sql`: formato de migración (pre-checks, UP, DOWN comentado).

Método existente que reutilizas **sin modificar**:

| Método | Uso |
|--------|-----|
| `PaymentClientRepository::findByIdAndNotary()` | Existencia, tenencia y `statusId` del cliente (el nombre sale del `JOIN` de `PaymentRepository`) |

## Código de Referencia

### ClientUuid

```php
namespace App\Shared\Uuid;

final class ClientUuid
{
    /** Length the frontend generates (ShortUniqueId). */
    public const DEFAULT_LENGTH = 6;
    /** Column limit (varchar(20)); the backend accepts up to this. */
    public const MAX_LENGTH = 20;
    public const ERROR_MESSAGE = 'UUID must be 1 to 20 characters (letters, digits, - or _)';

    /** Returns an error message, or null when valid. No trim and no case change (utf8mb3_bin column). */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_LENGTH . '}$/D', $value) !== 1) {
            return self::ERROR_MESSAGE;
        }

        return null;
    }
}
```

```php
// TransactionFieldRules
public static function uuid(mixed $value): ?string
{
    return ClientUuid::validate($value);
}
```

### PaymentService::create

```php
public function create(CreatePaymentRequest $dto, int $notaryId): CreatePaymentResult
{
    // Idempotency first: a retry must succeed even if the client was deactivated meanwhile (ADR-003)
    $existing = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId);
    if ($existing !== null) {
        return new CreatePaymentResult($existing, created: false);
    }

    $this->requireActiveClient($dto->clientId, $notaryId);

    try {
        $id = $this->repository->insert($dto, $notaryId);
    } catch (DuplicatePaymentUuidException) {
        // Lost the race against a concurrent POST with the same uuid: return the winner
        $winner = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId)
            ?? throw new \LogicException('Duplicate uuid reported but payment not found');

        return new CreatePaymentResult($winner, created: false);
    }

    return new CreatePaymentResult($this->getById($id, $notaryId), created: true);
}
```

### PaymentService::update

```php
public function update(int $id, UpdatePaymentRequest $dto, int $notaryId): Payment
{
    $current = $this->repository->findByIdAndNotary($id, $notaryId)
        ?? throw new PaymentException(PaymentError::PaymentNotFound);

    if ($dto->clientId !== null && $dto->clientId !== $current->clientId) {
        throw new PaymentException(PaymentError::CannotChangeClient);
    }
    if ($dto->uuid !== null && $dto->uuid !== $current->uuid) {
        throw new PaymentException(PaymentError::CannotChangeUuid);
    }

    // No active-client check on purpose: only POST requires it (scenario 18)
    $this->repository->update(
        $id,
        $notaryId,
        $dto->amount,
        $dto->paymentDate ?? $current->paymentDate,
        $dto->comments ?? $current->comments,
    );

    return $this->getById($id, $notaryId);
}
```

### Repository (SELECT base e INSERT)

```php
private const SELECT_SQL = 'SELECT p.id_payment, p.uuid, p.id_notary, p.id_client, p.amount,
           p.payment_date, p.comments, c.full_name AS client_name
    FROM payments_payments p
    LEFT JOIN payments_clients c ON c.id_client = p.id_client AND c.id_notary = p.id_notary';

// insert(): same try/catch as PaymentTransactionRepository
if (($e->errorInfo[1] ?? null) === self::MYSQL_DUP_ENTRY) {
    throw new DuplicatePaymentUuidException($e);
}
```

`payment_date` se guarda como `$date . ' 00:00:00'`. Si en el PUT `$paymentDate` viene `null` (registro legacy sin fecha), el `UPDATE` debe escribir `NULL`, no `' 00:00:00'`: arma el valor con `$date !== null ? $date . ' 00:00:00' : null`.

### Rutas

```php
// Payments routes (protected)
$router->get('/api/v3/payments', [PaymentController::class, 'showByUuid'])->withMiddleware('auth');
$router->get('/api/v3/payments/{id}', [PaymentController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payments', [PaymentController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payments/{id}', [PaymentController::class, 'update'])->withMiddleware('auth');
```

### Registro en bootstrap

```php
ServiceLocator::register(PaymentRepository::class, function () {
    return new PaymentRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentService::class, function () {
    return new PaymentService(
        ServiceLocator::get(PaymentRepository::class),
        ServiceLocator::get(PaymentClientRepository::class)
    );
});
```

## Cuidado con

1. **Orden del POST**: validar body (400) → buscar uuid → cliente (404/422) → INSERT. Si inviertes los dos pasos del medio, un reintento tras desactivar el cliente devuelve 422 en vez de 200.
2. **Mensajes de validación**: `amount <= 0` va en `errors.amount` dentro de `{"error": "Validation failed", ...}` (ADR-006), no como `error` plano. Los de dominio (`Cannot change UUID`, `Cannot change client`, `Payment not found`) sí van planos.
3. **`amount` como string**: `"5000"` se rechaza (solo int/float), igual que en spec 11.
4. **Fecha por defecto**: usa `new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'))`; no dependas del timezone del servidor.
5. **`uuid` en PUT**: compara con `!==` estricto. `abc` y `ABC` son distintos (columna `utf8mb3_bin`); si el front reenvía el mismo uuid es 200.
6. **`GET /api/v3/payments?uuid[]=x`**: `getQueryParam` devuelve un array; `ClientUuid::validate()` lo rechaza por no ser string → 400 `Invalid uuid format`.
7. **No expongas `notaryId`** en `PaymentResponse`.
8. **Collation**: no envuelvas la columna `uuid` en funciones o `CONVERT` en el `WHERE`.

## Verificación manual rápida

```bash
# Crear (201), repetir con otro amount (200 y el amount original)
curl -X POST localhost:8080/api/v3/payments -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"clientId":5,"uuid":"Us2Gvq","amount":5000,"comments":"Adelanto"}'

# Por uuid / por id
curl "localhost:8080/api/v3/payments?uuid=Us2Gvq" -H "Authorization: Bearer $TOKEN"
curl localhost:8080/api/v3/payments/19 -H "Authorization: Bearer $TOKEN"

# PUT: omitir paymentDate la conserva; uuid distinto → 400
curl -X PUT localhost:8080/api/v3/payments/19 -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"amount":5500}'
```

## Diferencias con el PO

| PO | Plan | Motivo |
|----|------|--------|
| Índice por `id_client` | `(id_notary, id_client)` | Toda query filtra por notaría; igual que spec 11 (ADR-004) |
| Errores 400 como `{"error": "<mensaje>"}` para validación de campos | `{"error": "Validation failed", "errors": {...}}` | Formato del proyecto y de spec 11 (ADR-006) |
| UUID: no vacío, máx 20 | Regex `^[A-Za-z0-9_-]{1,20}$` en utilidad compartida | Consistencia con spec 11 y UUID de 6 del frontend (ADR-005) |
| `amount` > 0 sin máximo | `<= 999999999.99` | Mismo tope que spec 11 (ADR-007) |
| Sin mención de `null` en PUT | `null` = conservar | Evita borrar datos legacy por accidente (ADR-007) |
| Estimación 6–7 h | ~7 h | +45 min de la utilidad compartida y +30 min de pre-checks |
