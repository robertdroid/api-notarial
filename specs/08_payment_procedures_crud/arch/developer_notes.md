# Notas para el Developer

## Contexto Rápido

Implementar CRUD de procedimientos de pago (`payment_procedures`), prácticamente idéntico a `PaymentTax`. Diferencias clave:
1. La columna `procedure_name` es NOT NULL (simplifica `fromRow()`).
2. Validar unicidad en UPDATE **solo si el nombre cambia** (hay un duplicado legacy).
3. Campos: `procedureId`, `procedureName`, `status` (no `taxId`/`taxName`).

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/PaymentProcedure/Entity/PaymentProcedure.php` | Entidad de dominio |
| `src/Module/PaymentProcedure/DTO/CreatePaymentProcedureRequest.php` | Validación de POST |
| `src/Module/PaymentProcedure/DTO/UpdatePaymentProcedureRequest.php` | Validación de PUT |
| `src/Module/PaymentProcedure/DTO/PaymentProcedureResponse.php` | Transformación a JSON |
| `src/Module/PaymentProcedure/DTO/ProcedureNameRule.php` | Validador compartido |
| `src/Module/PaymentProcedure/Repository/PaymentProcedureRepository.php` | Acceso a BD |
| `src/Module/PaymentProcedure/Service/PaymentProcedureService.php` | Lógica de negocio |
| `src/Module/PaymentProcedure/Controller/PaymentProcedureController.php` | Endpoints HTTP |
| `src/Config/routes.php` | Registro de rutas |
| `src/bootstrap.php` | Registro en ServiceLocator |

## Patrones a Seguir

**Copiar casi 1:1 de:**
- `src/Module/PaymentTax/` - Toda la estructura

**Cambios de nombre:**
- `PaymentTax` → `PaymentProcedure`
- `taxName` → `procedureName`
- `taxId` → `procedureId`
- `id_tax` → `id_procedure`
- `tax_name` → `procedure_name`
- `payment_taxes` → `payment_procedures`
- `Tax not found` → `Procedure not found`
- `Tax name ...` → `Procedure name ...`

## Código de Referencia

### Entity (diferencia: procedure_name es NOT NULL)

```php
<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\Entity;

final class PaymentProcedure
{
    public const STATUS_ACTIVE = 1;

    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly int $statusId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_procedure'],
            notaryId: (int) $row['id_notary'],
            name: (string) $row['procedure_name'],  // NOT NULL, no default needed
            statusId: (int) ($row['id_status'] ?? self::STATUS_ACTIVE),
        );
    }
}
```

### Service (diferencia: unicidad condicional)

```php
/**
 * @return true|string|null null when the procedure does not exist in this notary
 */
public function update(int $id, UpdatePaymentProcedureRequest $request, int $notaryId): true|string|null
{
    $existing = $this->repository->findByIdAndNotary($id, $notaryId);
    if ($existing === null) {
        return null;
    }

    // Validate uniqueness ONLY if name is being changed
    if ($request->procedureName !== null) {
        $newNameNormalized = mb_strtolower(trim($request->procedureName));
        $existingNameNormalized = mb_strtolower($existing->name);
        
        if ($newNameNormalized !== $existingNameNormalized) {
            if ($this->repository->existsByNameAndNotary($request->procedureName, $notaryId, $id)) {
                return self::DUPLICATE_NAME;
            }
        }
    }

    $this->repository->update(new PaymentProcedure(
        id: $id,
        notaryId: $notaryId,
        name: $request->procedureName ?? $existing->name,
        statusId: $request->status ?? $existing->statusId,
    ));

    return true;
}
```

### Repository (constantes)

```php
private const COLUMNS = 'id_procedure, id_notary, procedure_name, id_status';
private const NAME_EXPR = 'CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci';
```

### ProcedureNameRule

```php
<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedure\DTO;

final class ProcedureNameRule
{
    public const MAX_LENGTH = 500;

    /**
     * Returns the error message, or null when the value is valid.
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Procedure name must be a string';
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'Procedure name is required';
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            return 'Procedure name must not exceed ' . self::MAX_LENGTH . ' characters';
        }

        // Column is utf8mb3: 4-byte characters (emoji) are not supported.
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $trimmed) !== 0) {
            return 'Procedure name contains unsupported characters';
        }

        return null;
    }
}
```

### Rutas (agregar después de payment-taxes)

```php
// Payment Procedures routes (protected)
$router->get('/api/v3/payment-procedures', [PaymentProcedureController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-procedures/{id}', [PaymentProcedureController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/payment-procedures', [PaymentProcedureController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/payment-procedures/{id}', [PaymentProcedureController::class, 'update'])->withMiddleware('auth');
```

### bootstrap.php (agregar después de PaymentTax)

```php
use App\Module\PaymentProcedure\Repository\PaymentProcedureRepository;
use App\Module\PaymentProcedure\Service\PaymentProcedureService;

ServiceLocator::register(PaymentProcedureRepository::class, function () {
    return new PaymentProcedureRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentProcedureService::class, function () {
    return new PaymentProcedureService(
        ServiceLocator::get(PaymentProcedureRepository::class)
    );
});
```

## Base de Datos

### Tabla Involucrada

```sql
CREATE TABLE `payment_procedures` (
  `id_procedure` int(11) NOT NULL AUTO_INCREMENT,
  `id_notary` int(11) NOT NULL,
  `procedure_name` varchar(500) NOT NULL,
  `id_status` int(11) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_procedure`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin
```

### Migración

```sql
-- Migration: add_notary_status_index_to_payment_procedures
-- Description: Index for GET /api/v3/payment-procedures (notary filter + status filter)
-- Author: [tu nombre]
-- Date: YYYY-MM-DD

-- UP
ALTER TABLE payment_procedures
    ADD INDEX idx_payment_procedures_notary_status (id_notary, id_status);

-- DOWN (rollback)
-- ALTER TABLE payment_procedures DROP INDEX idx_payment_procedures_notary_status;
```

### Datos Existentes

- ~20 registros, AUTO_INCREMENT=21
- Notarías 1 y 3 tienen datos
- **Duplicado conocido**: "RATIFICACION" id=11 (status=2, inactivo) y id=12 (status=1, activo) en notaría 1

### Queries Clave

```sql
-- Listar con filtros
SELECT id_procedure, id_notary, procedure_name, id_status 
FROM payment_procedures 
WHERE id_notary = :notaryId
  AND id_status = :status  -- opcional
  AND CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :name ESCAPE '!'  -- opcional
ORDER BY procedure_name ASC;

-- Verificar unicidad (con exclusión para UPDATE)
SELECT COUNT(*) FROM payment_procedures 
WHERE id_notary = :notaryId 
  AND CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci = :name
  AND id_procedure <> :excludeId;  -- solo en UPDATE
```

## Tests Unitarios

### Estructura de directorios

```
tests/Module/PaymentProcedure/
├── Entity/
│   └── PaymentProcedureTest.php
├── DTO/
│   ├── CreatePaymentProcedureRequestTest.php
│   ├── UpdatePaymentProcedureRequestTest.php
│   └── PaymentProcedureResponseTest.php
└── Service/
    └── PaymentProcedureServiceTest.php
```

### Casos mínimos para Service (con mock)

```php
public function testCreateWithNewNameReturnsTrue(): void
{
    $this->repository->method('existsByNameAndNotary')->willReturn(false);
    $this->repository->expects($this->once())->method('create');
    
    $result = $this->service->create($validRequest, 1);
    
    $this->assertTrue($result);
}

public function testCreateWithDuplicateNameReturnsError(): void
{
    $this->repository->method('existsByNameAndNotary')->willReturn(true);
    
    $result = $this->service->create($validRequest, 1);
    
    $this->assertSame('Procedure name already exists', $result);
}

public function testUpdateWithSameNameDoesNotValidateUniqueness(): void
{
    $existing = new PaymentProcedure(1, 1, 'Existing Name', 1);
    $this->repository->method('findByIdAndNotary')->willReturn($existing);
    
    // existsByNameAndNotary should NOT be called
    $this->repository->expects($this->never())->method('existsByNameAndNotary');
    $this->repository->expects($this->once())->method('update');
    
    $request = UpdatePaymentProcedureRequest::fromArray(['procedureName' => 'Existing Name']);
    $result = $this->service->update(1, $request, 1);
    
    $this->assertTrue($result);
}
```

## Checklist Pre-PR

- [ ] Migración aplicada en local y movida a `.db/applied/`
- [ ] `composer test` pasa (suite completa sin regresiones)
- [ ] Probado con curl/Postman:
  - [ ] GET lista con/sin filtros
  - [ ] GET por ID válido e inválido
  - [ ] POST válido y con duplicado
  - [ ] PUT válido, con duplicado y con mismo nombre
  - [ ] Sin token → 401
  - [ ] ID de otra notaría → 404
- [ ] OpenAPI actualizado
- [ ] Sin `var_dump`/`print_r`
- [ ] `declare(strict_types=1)` en todos los archivos nuevos
- [ ] Código formateado (PSR-12)

## Preguntas Frecuentes

**P: ¿Por qué no usamos 409 Conflict para duplicados?**
R: Por consistencia con PaymentTax y otros módulos que usan 400. El frontend debe identificar el error por el mensaje `"Procedure name already exists"`. Ver Catálogo de Errores en `implementation_plan.md`.

**P: ¿Qué pasa con el duplicado "RATIFICACION"?**
R: Se tolera. El UPDATE valida unicidad solo si el nombre cambia. Si el usuario quiere modificar el id 12 enviando el mismo nombre, funciona. Si intenta cambiarle el nombre a uno que ya existe (incluyendo el id 11), recibe error.

**P: ¿Por qué POST/PUT devuelven sin body?**
R: Consistencia con los módulos existentes (Acts, DeedTypes, Taxes). POST → 201 sin body, PUT → 204 sin body.

**P: ¿Cómo pruebo la búsqueda insensible a acentos?**
R: Hay "SUBDIVISIÓN" en los datos. Busca con `?name=subdivision` (sin acento) y debe aparecer.

## Contacto

- Para dudas de negocio: Revisar `specs/08_payment_procedures_crud/po/`
- Para dudas de arquitectura: Revisar este documento y `implementation_plan.md`
- Patrón de referencia: `src/Module/PaymentTax/`
