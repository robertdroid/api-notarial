# Notas para el Developer: Payment Taxes CRUD

## Contexto Rápido

Catálogo de impuestos/conceptos de cobro por notaría (`payment_taxes`). Es prerrequisito para el CRUD de procedimientos de pago. Son 4 endpoints (`GET` lista, `GET` detalle, `POST`, `PUT`), sin DELETE: la baja es `PUT {"status": 2}`.

**Copia la estructura de `src/Module/DeedType/`**, pero ojo con estas diferencias:

| | DeedType | **PaymentTax** |
|---|---|---|
| PUT | Todos los campos requeridos | **Parcial**: `taxName` y `status` opcionales (merge) |
| Búsqueda/unicidad | `LOWER(col)`: sensible a acentos | `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci`: **insensible a acentos y mayúsculas** |
| Validación de ID | `is_numeric` | `filter_var(FILTER_VALIDATE_INT)`; ≤ 0 → 404 |
| Tipos en DTO | Cast directo (`TypeError` → 500 si llega un tipo incorrecto) | Verificar `is_string`/`is_int` → 400 |
| Response | Incluye `notaryId`, `userId`… | **Solo** `taxId`, `taxName`, `status` |
| `userId` | Se guarda | La tabla no tiene esa columna; no se usa |

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/PaymentTax/Entity/PaymentTax.php` | Entidad readonly + `fromRow()` |
| `src/Module/PaymentTax/DTO/TaxNameRule.php` | Reglas de `taxName` compartidas por Create y Update |
| `src/Module/PaymentTax/DTO/CreatePaymentTaxRequest.php` | Validación POST |
| `src/Module/PaymentTax/DTO/UpdatePaymentTaxRequest.php` | Validación PUT parcial |
| `src/Module/PaymentTax/DTO/PaymentTaxResponse.php` | Entity → `{taxId, taxName, status}` |
| `src/Module/PaymentTax/Repository/PaymentTaxRepository.php` | SQL (siempre con `id_notary`) |
| `src/Module/PaymentTax/Service/PaymentTaxService.php` | Unicidad, merge, trim |
| `src/Module/PaymentTax/Controller/PaymentTaxController.php` | HTTP: parseo de ID, DTO y códigos de estado |
| `src/bootstrap.php` | Registro en `ServiceLocator` |
| `src/Config/routes.php` | 4 rutas con `auth` |
| `.db/pending/…_add_notary_status_index_to_payment_taxes.sql` | Índice |
| `docs/openapi.yaml` | Documentación |

## Patrones a Seguir

- `src/Module/DeedType/`: estructura general, ServiceLocator y forma del controller
- `tests/Module/Act/`: estilo de tests (PHPUnit, `createMock(Repository::class)`)
- `.db/applied/20261109_120000_add_unique_index_actos_notaria.sql`: formato de migración

## Código de Referencia

### Entity

```php
<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\Entity;

final class PaymentTax
{
    public const STATUS_ACTIVE = 1;

    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly int $statusId,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_tax'],
            notaryId: (int) $row['id_notary'],
            name: (string) ($row['tax_name'] ?? ''),                       // nullable en BD
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),     // nullable en BD
        );
    }
}
```

### TaxNameRule (validación compartida)

```php
<?php

declare(strict_types=1);

namespace App\Module\PaymentTax\DTO;

final class TaxNameRule
{
    public const MAX_LENGTH = 500;

    /** Devuelve el mensaje de error, o null si es válido. */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Tax name must be a string';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'Tax name is required';
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            return 'Tax name must not exceed ' . self::MAX_LENGTH . ' characters';
        }

        // La columna es utf8mb3: no admite caracteres de 4 bytes (emoji).
        // preg_match devuelve false si el UTF-8 es inválido → también se rechaza.
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $trimmed) !== 0) {
            return 'Tax name contains unsupported characters';
        }

        return null;
    }
}
```

### CreatePaymentTaxRequest

```php
final class CreatePaymentTaxRequest
{
    private array $errors = [];

    private function __construct(
        public readonly string $taxName,
        public readonly int $status,
    ) {}

    public static function fromArray(array $data): self
    {
        $errors = [];

        $rawName = $data['taxName'] ?? null;
        if ($rawName === null) {
            $errors['taxName'] = 'Tax name is required';
        } elseif (($error = TaxNameRule::validate($rawName)) !== null) {
            $errors['taxName'] = $error;
        }

        $status = 1; // default
        if (array_key_exists('status', $data)) {
            if (!is_int($data['status']) || !in_array($data['status'], [1, 2], true)) {
                $errors['status'] = 'Status must be 1 or 2';
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self(
            taxName: is_string($rawName) ? trim($rawName) : '',
            status: $status,
        );
        $instance->errors = $errors;

        return $instance;
    }

    public function isValid(): bool { return $this->errors === []; }
    public function getErrors(): array { return $this->errors; }
}
```

### UpdatePaymentTaxRequest (parcial)

```php
final class UpdatePaymentTaxRequest
{
    private array $errors = [];

    private function __construct(
        public readonly ?string $taxName,   // null = no enviado → no cambia
        public readonly ?int $status,       // null = no enviado → no cambia
    ) {}

    public static function fromArray(array $data): self
    {
        $errors = [];
        $taxName = null;
        $status = null;

        $hasName = array_key_exists('taxName', $data);
        $hasStatus = array_key_exists('status', $data);

        if (!$hasName && !$hasStatus) {
            $errors['body'] = 'At least one field (taxName, status) is required';
        }

        if ($hasName) {
            // "taxName": null explícito también es inválido
            $error = TaxNameRule::validate($data['taxName']);
            if ($error !== null) {
                $errors['taxName'] = $error;
            } else {
                $taxName = trim($data['taxName']);
            }
        }

        if ($hasStatus) {
            if (!is_int($data['status']) || !in_array($data['status'], [1, 2], true)) {
                $errors['status'] = 'Status must be 1 or 2';
            } else {
                $status = $data['status'];
            }
        }

        $instance = new self($taxName, $status);
        $instance->errors = $errors;

        return $instance;
    }

    // isValid(), getErrors() igual que Create
}
```

### Response

```php
final class PaymentTaxResponse
{
    public static function fromEntity(PaymentTax $tax): array
    {
        return [
            'taxId' => $tax->id,
            'taxName' => $tax->name,
            'status' => $tax->statusId,
        ];
    }

    public static function fromCollection(array $taxes): array
    {
        return array_map(fn(PaymentTax $t) => self::fromEntity($t), $taxes);
    }
}
```

### Service

```php
final class PaymentTaxService
{
    private const DUPLICATE_NAME = 'Tax name already exists';

    public function __construct(
        private readonly PaymentTaxRepository $repository
    ) {}

    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?PaymentTax
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreatePaymentTaxRequest $request, int $notaryId): true|string
    {
        if ($this->repository->existsByNameAndNotary($request->taxName, $notaryId)) {
            return self::DUPLICATE_NAME;
        }

        $this->repository->create(new PaymentTax(
            id: 0,
            notaryId: $notaryId,
            name: $request->taxName,
            statusId: $request->status,
        ));

        return true;
    }

    /** @return true|string|null  null = no existe en esta notaría */
    public function update(int $id, UpdatePaymentTaxRequest $request, int $notaryId): true|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);
        if ($existing === null) {
            return null;
        }

        if ($request->taxName !== null
            && $this->repository->existsByNameAndNotary($request->taxName, $notaryId, $id)
        ) {
            return self::DUPLICATE_NAME;
        }

        $this->repository->update(new PaymentTax(
            id: $id,
            notaryId: $notaryId,
            name: $request->taxName ?? $existing->name,
            statusId: $request->status ?? $existing->statusId,
        ));

        return true;
    }
}
```

### Controller

```php
final class PaymentTaxController
{
    private const NOT_FOUND = 'Tax not found';

    private PaymentTaxService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentTaxService::class);
    }

    public function index(Request $request): Response
    {
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $taxes = $this->service->getAll(
            $request->getNotaryId(),
            $status !== null ? (int) $status : null,
            is_string($name) ? trim($name) : null,
        );

        return Response::json(PaymentTaxResponse::fromCollection($taxes));
    }

    public function show(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $tax = $this->service->getById($id, $request->getNotaryId());

        return $tax === null
            ? Response::notFound(self::NOT_FOUND)
            : Response::json(PaymentTaxResponse::fromEntity($tax));
    }

    public function store(Request $request): Response
    {
        $dto = CreatePaymentTaxRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->service->create($dto, $request->getNotaryId());

        return $result === true ? Response::created() : Response::badRequest($result);
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdatePaymentTaxRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->service->update($id, $dto, $request->getNotaryId());

        return match (true) {
            $result === null => Response::notFound(self::NOT_FOUND),
            $result === true => Response::noContent(),
            default => Response::badRequest($result),
        };
    }

    /** int válido > 0, o Response de error (400 formato / 404 no positivo). */
    private function parseId(Request $request): int|Response
    {
        $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return Response::badRequest('Invalid ID format');
        }

        if ($id <= 0) {
            return Response::notFound(self::NOT_FOUND);
        }

        return $id;
    }
}
```

> **Orden en `update()`:** primero el ID, luego el body, luego la existencia. Un PUT con un ID inexistente **y** un body inválido responde 400. Es el mismo orden que en DeedType.

### Rutas y wiring

```php
// src/Config/routes.php
use App\Module\PaymentTax\Controller\PaymentTaxController;

// Payment Taxes routes (protected)
$router->get('/api/v3/payment-taxes', [PaymentTaxController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-taxes/{id}', [PaymentTaxController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-taxes', [PaymentTaxController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-taxes/{id}', [PaymentTaxController::class, 'update'])->withMiddleware('auth');
```

```php
// src/bootstrap.php
ServiceLocator::register(PaymentTaxRepository::class, function () {
    return new PaymentTaxRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentTaxService::class, function () {
    return new PaymentTaxService(ServiceLocator::get(PaymentTaxRepository::class));
});
```

## Base de Datos

### Tablas Involucradas
- `payment_taxes`: **MyISAM**, **`utf8mb3_bin`** (sensible a mayúsculas y acentos). PK `id_tax`. `tax_name VARCHAR(500) NULL`. `id_status` es 1 = activo, 2 = inactivo. `created_at` y `modified_at` son automáticos (`modified_at` con `ON UPDATE`).
- `payment_procedure_taxes`: **no se toca** en este feature.

Datos semilla locales: 8 registros de la notaría 1 (incluye "Pago de Subdivisión" y "Derechos Subdivisión") y `id_tax = 9` "IVA" de la **notaría 3**, útil para probar el aislamiento multi-tenant.

### Queries

Estas queries ya se probaron vía PDO (prepares nativos, conexión `utf8mb4`) contra la BD local.

```sql
-- Listado (los filtros status y name son opcionales y se agregan dinámicamente)
SELECT id_tax, id_notary, tax_name, id_status
FROM payment_taxes
WHERE id_notary = :notaryId
  AND id_status = :status
  AND CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :name ESCAPE '!'
ORDER BY tax_name ASC;
-- :name = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%'
-- Resultados verificados (notaría 1):
--   'subdivision' → [Derechos Subdivisión, Pago de Subdivisión]
--   '%'           → []

-- Detalle
SELECT id_tax, id_notary, tax_name, id_status
FROM payment_taxes
WHERE id_tax = :id AND id_notary = :notaryId;

-- Unicidad (excludeId solo en update)
SELECT COUNT(*) FROM payment_taxes
WHERE id_notary = :notaryId
  AND CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci = :name
  AND id_tax <> :excludeId;
-- Verificado: 'derechos subdivision' → 1 · 'gastos' → 1 · 'Subdivision' → 0 · 'iva' (notaría 1) → 0

-- Insert
INSERT INTO payment_taxes (id_notary, tax_name, id_status)
VALUES (:notaryId, :name, :status);

-- Update (modified_at lo actualiza la BD)
UPDATE payment_taxes
SET tax_name = :name, id_status = :status
WHERE id_tax = :id AND id_notary = :notaryId;
```

> No uses `SELECT *`: lista las columnas explícitamente.
>
> Con `PDO::ATTR_EMULATE_PREPARES => false` no puedes repetir el mismo placeholder nombrado dos veces en una query. Usa nombres distintos si lo necesitas.

### Migración

`.db/pending/YYYYMMDD_HHMMSS_add_notary_status_index_to_payment_taxes.sql`:

```sql
-- Migration: add_notary_status_index_to_payment_taxes
-- Description: Index for GET /api/v3/payment-taxes (notary filter + status filter)
-- Author: <developer>
-- Date: YYYY-MM-DD

-- UP
ALTER TABLE payment_taxes
    ADD INDEX idx_payment_taxes_notary_status (id_notary, id_status);

-- DOWN (rollback)
-- ALTER TABLE payment_taxes DROP INDEX idx_payment_taxes_notary_status;
```

Aplícala en local (`docker-compose exec db mariadb …`) y mueve el archivo a `.db/applied/`.

## Tests Mínimos (Service)

```php
public function testUpdateOnlyStatusDoesNotCheckNameUniqueness(): void
{
    $this->repository->method('findByIdAndNotary')->willReturn(new PaymentTax(5, 1, 'ISR', 1));
    $this->repository->expects($this->never())->method('existsByNameAndNotary');
    $this->repository->expects($this->once())->method('update')
        ->with($this->callback(fn(PaymentTax $t) => $t->name === 'ISR' && $t->statusId === 2));

    $dto = UpdatePaymentTaxRequest::fromArray(['status' => 2]);

    $this->assertTrue($this->service->update(5, $dto, 1));
}

public function testUpdateSameNameExcludesOwnId(): void
{
    $this->repository->method('findByIdAndNotary')->willReturn(new PaymentTax(5, 1, 'ISR', 1));
    $this->repository->expects($this->once())->method('existsByNameAndNotary')
        ->with('ISR', 1, 5)->willReturn(false);

    $dto = UpdatePaymentTaxRequest::fromArray(['taxName' => 'ISR']);

    $this->assertTrue($this->service->update(5, $dto, 1));
}
```

La lista completa de casos de test está en `technical_acceptance_criteria.md` → "Tests Requeridos".

## Checklist Pre-PR
- [ ] Migración aplicada en local y movida a `.db/applied/`
- [ ] El código sigue la arquitectura definida (sin SQL en el Service y sin lógica en el Repository)
- [ ] Todas las queries tienen `id_notary = :notaryId`
- [ ] La respuesta **no** incluye `notaryId`
- [ ] No existe ruta DELETE
- [ ] `composer test` (o `vendor/bin/phpunit`) pasa
- [ ] Sin warnings de PHP (revisar `docker-compose logs api`)
- [ ] `docs/openapi.yaml` actualizado
- [ ] Probados manualmente los Escenarios 1–23 + cross-tenant con `id_tax = 9`
- [ ] Revisados los criterios de `technical_acceptance_criteria.md`
- [ ] Branch `feature/07-payment-taxes-crud` → PR a `develop` (el merge lo cierra QA)

## Preguntas Frecuentes

**P: ¿Por qué no usar `LOWER(tax_name) LIKE LOWER(:name)` como en DeedType?**
R: Porque la tabla es `utf8mb3_bin` y no encuentra "Subdivisión" al buscar "subdivision" (Escenario 3). Ver ADR-001.

**P: ¿Agrego un índice UNIQUE para el nombre?**
R: No. Con collation `_bin` solo bloquearía duplicados exactos, no "ISR" vs "isr". La unicidad se valida en el Service. Ver ADR-003.

**P: ¿Qué pasa con `PUT {}`?**
R: 400 `Validation failed` con `errors.body = "At least one field (taxName, status) is required"`.

**P: ¿`"status": "1"` (string) es válido?**
R: No. La API v3 espera un entero JSON. Responde 400 `Status must be 1 or 2`. En el **query string** (`?status=1`) sí llega como string, y eso es normal.

**P: Los Escenarios 21–23 piden "Unauthorized"/"Token expired", pero el middleware responde en español.**
R: Es conocido y transversal; no lo cambies en este feature. QA valida el código 401. Ver ADR-005.

**P: ¿Hay que validar que el impuesto no esté en uso antes de desactivarlo?**
R: No. El PO lo excluye explícitamente.

**P: ¿Guardo `userId` como en DeedType?**
R: No. `payment_taxes` no tiene columna de usuario. El Service no recibe `userId`.

## Contacto
Dudas de negocio: `specs/07_payment_taxes_crud/po/`
Dudas de arquitectura: `specs/07_payment_taxes_crud/arch/implementation_plan.md` (sección ADRs) o con el arquitecto (`/arch`)
