# Notas para el Developer

## Contexto Rápido

Implementar el CRUD de clientes de pago (`payments_clients`) copiando la estructura de `PaymentProcedure`. Diferencias clave:
1. **Sin unicidad de nombre** → no hay `existsByNameAndNotary()`; el Service no devuelve errores de negocio.
2. **3 campos de texto** (`fullName`, `phone`, `email`) → la validación vive en una sola clase `ClientFieldRules`.
3. **PUT parcial con limpieza**: campo ausente = no cambiar; `""`/`null` en `phone`/`email` = borrar.
4. POST → **201 sin body**, PUT → **204 sin body** (ADR-004, igual que los otros catálogos).

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/PaymentClient/Entity/PaymentClient.php` | Entidad de dominio |
| `src/Module/PaymentClient/DTO/ClientFieldRules.php` | Reglas de validación por campo (compartidas) |
| `src/Module/PaymentClient/DTO/CreatePaymentClientRequest.php` | Validación de POST |
| `src/Module/PaymentClient/DTO/UpdatePaymentClientRequest.php` | Validación de PUT |
| `src/Module/PaymentClient/DTO/PaymentClientResponse.php` | Transformación a JSON |
| `src/Module/PaymentClient/Repository/PaymentClientRepository.php` | Acceso a BD |
| `src/Module/PaymentClient/Service/PaymentClientService.php` | Lógica de negocio |
| `src/Module/PaymentClient/Controller/PaymentClientController.php` | Endpoints HTTP |
| `src/Config/routes.php` | Registro de rutas |
| `src/bootstrap.php` | Registro en ServiceLocator |

## Patrones a Seguir

**Base:** `src/Module/PaymentProcedure/` (Controller casi 1:1).

**Cambios de nombre:**
- `PaymentProcedure` → `PaymentClient`
- `procedureId` → `clientId`, `procedureName` → `fullName`
- `id_procedure` → `id_client`, `procedure_name` → `full_name`
- `payment_procedures` → `payments_clients` (**ojo: `payments` en plural**)
- `Procedure not found` → `Client not found`

**Lo que NO se copia:** `existsByNameAndNotary()`, la constante `DUPLICATE_NAME` ni los retornos `true|string`.

## Código de Referencia

### ClientFieldRules

```php
<?php

declare(strict_types=1);

namespace App\Module\PaymentClient\DTO;

final class ClientFieldRules
{
    public const FULL_NAME_MAX = 250;
    public const PHONE_MAX = 20;
    public const EMAIL_MAX = 250;

    public static function fullName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Full name must be a string';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'Full name is required';
        }

        if (mb_strlen($trimmed) > self::FULL_NAME_MAX) {
            return 'Full name must not exceed ' . self::FULL_NAME_MAX . ' characters';
        }

        if (self::hasUnsupportedChars($trimmed)) {
            return 'Full name contains unsupported characters';
        }

        return null;
    }

    /** null is accepted and means "empty" (ADR-003). */
    public static function phone(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return 'Phone must be a string';
        }

        $trimmed = self::normalizeOptional($value);

        if (mb_strlen($trimmed) > self::PHONE_MAX) {
            return 'Phone must not exceed ' . self::PHONE_MAX . ' characters';
        }

        if (self::hasUnsupportedChars($trimmed)) {
            return 'Phone contains unsupported characters';
        }

        return null;
    }

    /** null is accepted and means "empty" (ADR-003). */
    public static function email(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return 'Email must be a string';
        }

        $trimmed = self::normalizeOptional($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::EMAIL_MAX) {
            return 'Email must not exceed ' . self::EMAIL_MAX . ' characters';
        }

        // Rejects any non-ASCII character, so 4-byte chars are covered too.
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return 'Email format is invalid';
        }

        return null;
    }

    public static function status(mixed $value): ?string
    {
        return is_int($value) && in_array($value, [1, 2], true) ? null : 'Status must be 1 or 2';
    }

    /** Call only after the matching rule returned null. */
    public static function normalizeOptional(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function hasUnsupportedChars(string $value): bool
    {
        // Columns are utf8mb3. preg_match returns false on invalid UTF-8 → also rejected.
        return preg_match('/[\x{10000}-\x{10FFFF}]/u', $value) !== 0;
    }
}
```

> **Ojo con `hasUnsupportedChars('')`:** `preg_match` sobre string vacío devuelve `0` → válido. Correcto.

### UpdatePaymentClientRequest (lo interesante: ausente vs vacío)

```php
public static function fromArray(array $data): self
{
    $errors = [];
    $fullName = $phone = $email = $status = null;

    $fields = ['fullName', 'phone', 'email', 'status'];
    if (array_intersect($fields, array_keys($data)) === []) {
        $errors['body'] = 'At least one field (fullName, phone, email, status) is required';
    }

    if (array_key_exists('fullName', $data)) {
        $error = ClientFieldRules::fullName($data['fullName']);
        $error === null ? $fullName = trim($data['fullName']) : $errors['fullName'] = $error;
    }

    if (array_key_exists('phone', $data)) {
        $error = ClientFieldRules::phone($data['phone']);
        // '' (not null) when the client sends "" or null → clears the value
        $error === null ? $phone = ClientFieldRules::normalizeOptional($data['phone']) : $errors['phone'] = $error;
    }

    // email: same as phone
    // status: ClientFieldRules::status()

    $instance = new self($fullName, $phone, $email, $status);
    $instance->errors = $errors;

    return $instance;
}
```

(Si prefieres `if/else` en vez del ternario con asignaciones, mejor: es más legible. Lo importante es la semántica.)

### Service

```php
final class PaymentClientService
{
    public function __construct(
        private readonly PaymentClientRepository $repository
    ) {
    }

    public function create(CreatePaymentClientRequest $request, int $notaryId): void
    {
        $this->repository->create(new PaymentClient(
            id: 0,
            notaryId: $notaryId,
            fullName: $request->fullName,
            phone: $request->phone,
            email: $request->email,
            statusId: $request->status,
        ));
    }

    /**
     * @return bool false when the client does not exist in this notary
     */
    public function update(int $id, UpdatePaymentClientRequest $request, int $notaryId): bool
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);
        if ($existing === null) {
            return false;
        }

        // '' is not null, so an explicit empty value overrides the stored one.
        $this->repository->update(new PaymentClient(
            id: $id,
            notaryId: $notaryId,
            fullName: $request->fullName ?? $existing->fullName,
            phone: $request->phone ?? $existing->phone,
            email: $request->email ?? $existing->email,
            statusId: $request->status ?? $existing->statusId,
        ));

        return true;
    }

    // getAll() y getById() idénticos a PaymentProcedureService
}
```

### Controller (store / update)

```php
public function store(Request $request): Response
{
    $dto = CreatePaymentClientRequest::fromArray($request->getBody());
    if (!$dto->isValid()) {
        return Response::badRequest('Validation failed', $dto->getErrors());
    }

    $this->service->create($dto, $request->getNotaryId());

    return Response::created();
}

public function update(Request $request): Response
{
    $id = $this->parseId($request);
    if ($id instanceof Response) {
        return $id;
    }

    $dto = UpdatePaymentClientRequest::fromArray($request->getBody());
    if (!$dto->isValid()) {
        return Response::badRequest('Validation failed', $dto->getErrors());
    }

    return $this->service->update($id, $dto, $request->getNotaryId())
        ? Response::noContent()
        : Response::notFound(self::NOT_FOUND);
}
```

`index`, `show` y `parseId` se copian de `PaymentProcedureController` cambiando nombres.

### Repository (constantes y listado)

```php
private const COLUMNS = 'id_client, id_notary, full_name, phone, email, id_status';

// Column is utf8mb3_bin; convert to get case- and accent-insensitive comparison/sort.
private const NAME_EXPR = 'CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci';

// en findAllByNotary():
$sql .= ' ORDER BY ' . self::NAME_EXPR . ' ASC, id_client ASC';
```

### Rutas (después de payment-procedures)

```php
// Payment Clients routes (protected)
$router->get('/api/v3/payment-clients', [PaymentClientController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-clients/{id}', [PaymentClientController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-clients', [PaymentClientController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-clients/{id}', [PaymentClientController::class, 'update'])->withMiddleware('auth');
```

### bootstrap.php (después de PaymentProcedure)

```php
use App\Module\PaymentClient\Repository\PaymentClientRepository;
use App\Module\PaymentClient\Service\PaymentClientService;

ServiceLocator::register(PaymentClientRepository::class, function () {
    return new PaymentClientRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentClientService::class, function () {
    return new PaymentClientService(
        ServiceLocator::get(PaymentClientRepository::class)
    );
});
```

## Base de Datos

### Tabla Involucrada (verificada en BD local)

```sql
CREATE TABLE `payments_clients` (
  `id_client` int(11) NOT NULL AUTO_INCREMENT,
  `id_notary` int(11) NOT NULL,
  `full_name` varchar(250) NOT NULL,
  `phone` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(250) NOT NULL DEFAULT '',
  `id_status` int(11) DEFAULT 1 COMMENT '1. active, 2. inactive',
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_client`)
) ENGINE=MyISAM AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin
```

Referenciada por `payments_transactions.id_client` (sin FK, MyISAM).

### Migración

```sql
-- Migration: add_notary_status_index_to_payments_clients
-- Description: Index for GET /api/v3/payment-clients (notary filter + status filter)
-- Author: [tu nombre]
-- Date: YYYY-MM-DD

-- UP
ALTER TABLE payments_clients
    ADD INDEX idx_payments_clients_notary_status (id_notary, id_status);

-- DOWN (rollback)
-- ALTER TABLE payments_clients DROP INDEX idx_payments_clients_notary_status;
```

### Datos Existentes (snapshot local, 2026-11-10)

| Notaría | Status | Registros | Con email | Con phone |
|---------|--------|-----------|-----------|-----------|
| 1 | 1 | 16 | 0 | 0 |
| 1 | 2 | 5 | 0 | 1 |
| 3 | 1 | 1 | 0 | 1 |

- 22 registros, ninguno con espacios sobrantes, ninguno con `id_status NULL`, sin nombres duplicados.
- Nombre más largo: 56 caracteres.
- **Ningún registro tiene email** → para probar el GET con email hay que crear uno vía POST.

### Queries Clave

```sql
-- Listar con filtros
SELECT id_client, id_notary, full_name, phone, email, id_status
FROM payments_clients
WHERE id_notary = :notaryId
  AND id_status = :status  -- opcional
  AND CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :name ESCAPE '!'  -- opcional
ORDER BY CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC, id_client ASC;

-- Update (siempre con notaría)
UPDATE payments_clients
SET full_name = :fullName, phone = :phone, email = :email, id_status = :status
WHERE id_client = :id AND id_notary = :notaryId;
```

## Tests Unitarios

### Estructura

```
tests/Module/PaymentClient/
├── Entity/
│   └── PaymentClientTest.php
├── DTO/
│   ├── ClientFieldRulesTest.php
│   ├── CreatePaymentClientRequestTest.php
│   ├── UpdatePaymentClientRequestTest.php
│   └── PaymentClientResponseTest.php
└── Service/
    └── PaymentClientServiceTest.php
```

### Casos imprescindibles

```php
// ClientFieldRulesTest: usar @dataProvider para los límites
public static function invalidPhones(): array
{
    return [
        'too long'   => [str_repeat('1', 21)],
        'not string' => [5551234],
        'emoji'      => ['555 📞'],
    ];
}
// Válidos: '', '   ', null, str_repeat('1', 20), '  ' . str_repeat('1', 20) . '  ' (24 con espacios, 20 tras trim)

// Email: '  juan@example.com  ' válido; '   ' válido (vacío); 'no-es-email' inválido

// UpdatePaymentClientRequestTest
public function testEmptyStringPhoneClearsValueInsteadOfBeingIgnored(): void
{
    $dto = UpdatePaymentClientRequest::fromArray(['phone' => '']);

    $this->assertTrue($dto->isValid());
    $this->assertSame('', $dto->phone);   // '' ≠ null
    $this->assertNull($dto->email);       // absent → unchanged
}

public function testNullFullNameIsRejected(): void
{
    $dto = UpdatePaymentClientRequest::fromArray(['fullName' => null]);

    $this->assertFalse($dto->isValid());
    $this->assertArrayHasKey('fullName', $dto->getErrors());
}

// PaymentClientServiceTest
public function testUpdateKeepsAbsentFieldsAndClearsEmptyOnes(): void
{
    $existing = new PaymentClient(5, 1, 'Juan', '555', 'juan@x.com', 1);
    $this->repository->method('findByIdAndNotary')->willReturn($existing);

    $this->repository->expects($this->once())->method('update')
        ->with($this->callback(fn (PaymentClient $c) =>
            $c->fullName === 'Juan' && $c->phone === '555' && $c->email === '' && $c->notaryId === 1
        ));

    $this->assertTrue($this->service->update(5, UpdatePaymentClientRequest::fromArray(['email' => '']), 1));
}

public function testUpdateOfMissingClientReturnsFalseWithoutWriting(): void
{
    $this->repository->method('findByIdAndNotary')->willReturn(null);
    $this->repository->expects($this->never())->method('update');

    $this->assertFalse($this->service->update(999, UpdatePaymentClientRequest::fromArray(['status' => 2]), 1));
}
```

## Checklist Pre-PR

- [ ] Migración aplicada en local y movida a `.db/applied/`
- [ ] `EXPLAIN` del listado muestra `idx_payments_clients_notary_status`
- [ ] `composer test` pasa (suite completa sin regresiones)
- [ ] Probado con curl/Postman:
  - [ ] GET lista sin filtros, `?status=1`, `?status=3` (400), `?name=lopez` encuentra "López"
  - [ ] GET por ID válido, `abc` (400), inexistente (404), de otra notaría (404)
  - [ ] POST solo `fullName` → 201; GET posterior muestra `phone: ""`, `email: ""`, `status: 1`
  - [ ] POST con nombre duplicado → 201
  - [ ] POST `fullName: "   "`, email inválido, phone de 21 chars → 400
  - [ ] PUT parcial (solo phone) conserva el resto; PUT `email: ""` lo borra; PUT `{}` → 400
  - [ ] PUT `{"status": 2}` → 204 y el cliente aparece en `?status=2`
  - [ ] Sin token → 401; DELETE → 405
- [ ] OpenAPI actualizado (incluyendo semántica ausente / `""` / `null`)
- [ ] Sin `var_dump`/`print_r`
- [ ] `declare(strict_types=1)` en todos los archivos nuevos
- [ ] PSR-12

## Preguntas Frecuentes

**P: ¿Por qué no uso `LOWER()` como dice el PO?**
R: Porque con `utf8mb3_bin` no ignora acentos y los clientes se llaman López, Pérez, Núñez... `?name=lopez` no encontraría "López". Ver ADR-001.

**P: ¿Por qué POST no devuelve el cliente creado si el PO lo pide?**
R: Consistencia con los demás catálogos (ADR-004). Es una limitación conocida: como los nombres no son únicos, el frontend no puede localizar el ID nuevo con certeza. Se revisará en el feature de Transactions.

**P: ¿Valido el formato del teléfono?**
R: No. Solo longitud (≤ 20) y caracteres soportados. Los formatos en México son muy variados (`55 1234 5678`, `+52 ...`, `ext. 12`).

**P: Si mandan `"phone": null` en el POST, ¿es error?**
R: No, se guarda `''` (ADR-003). Solo `fullName: null` es error.

**P: ¿Puedo desactivar un cliente que tiene transacciones?**
R: Sí. No hay FK ni validación cruzada en este feature; Transactions deberá tolerar clientes inactivos.

**P: ¿Hago `final` el Repository?**
R: No, igual que `PaymentProcedureRepository`, para que PHPUnit pueda mockearlo.

## Contacto

- Para dudas de negocio: `specs/09_payment_clients_crud/po/`
- Para dudas de arquitectura: este documento e `implementation_plan.md` (ADRs)
- Patrón de referencia: `src/Module/PaymentProcedure/`
