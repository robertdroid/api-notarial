# Notas para el Developer

## Contexto Rápido

Hay que configurar qué impuestos aplican a cada procedimiento, con un valor default. La configuración sirve como plantilla para Transactions. Es un **sub-recurso** de `/payment-procedures/{id}` sobre `payment_procedure_taxes`, una tabla MyISAM **sin PK y sin `id_notary`**: la tenencia se obtiene del procedimiento.

Lo nuevo respecto a 07/08/09:
- Un Service que usa **3 repositorios** (el propio + los de Procedure y Tax).
- **409 y 422** (helpers nuevos en `Response`).
- Errores por **enum + excepciones**, en lugar de `true|string|null`.
- POST con dos formas de body (single / bulk) y **todo o nada** sin transacciones.
- Las respuestas siguen el patrón del proyecto: **201 sin body, 204, array plano** (no lo que muestran los ejemplos del PO).

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/PaymentProcedureTax/Controller/PaymentProcedureTaxController.php` | `index`, `store`, `update`, `destroy`; traduce excepciones a HTTP |
| `src/Module/PaymentProcedureTax/Service/PaymentProcedureTaxService.php` | Validaciones de tenencia, status y asignación; orquesta repos |
| `src/Module/PaymentProcedureTax/Repository/PaymentProcedureTaxRepository.php` | SELECT con JOINs, upsert multi-row, UPDATEs multi-tabla |
| `src/Module/PaymentProcedureTax/Entity/PaymentProcedureTax.php` | Read model (incluye `taxName` del JOIN) |
| `src/Module/PaymentProcedureTax/Exception/ProcedureTaxError.php` | Enum: mensaje + código HTTP de cada error |
| `src/Module/PaymentProcedureTax/Exception/ProcedureTaxException.php` | Error de la operación completa |
| `src/Module/PaymentProcedureTax/Exception/InvalidProcedureTaxItemsException.php` | Errores por índice en POST |
| `src/Module/PaymentProcedureTax/DTO/AddProcedureTaxesRequest.php` | Body de POST single/bulk → `ProcedureTaxItem[]` |
| `src/Module/PaymentProcedureTax/DTO/UpdateProcedureTaxRequest.php` | Body de PUT |
| `src/Module/PaymentProcedureTax/DTO/ProcedureTaxItem.php` | VO `{taxId, defaultValue}` |
| `src/Module/PaymentProcedureTax/DTO/DefaultValueRule.php` | Validación y normalización de `defaultValue` |
| `src/Module/PaymentProcedureTax/DTO/ProcedureTaxResponse.php` | Entity → array de API |
| `src/Module/PaymentTax/Repository/PaymentTaxRepository.php` | **Agregar** `findByIdsAndNotary()` |
| `src/Core/Http/Response.php` | **Agregar** `conflict()` y `unprocessable()` |

## Patrones a Seguir

- `src/Module/PaymentProcedure/`: estructura del Controller (`parseId`), DTOs con `fromArray()/isValid()/getErrors()`, Repository con `COLUMNS` y collation para ordenar.
- `src/Module/PaymentClient/DTO/ClientFieldRules.php`: estilo de reglas estáticas reutilizables (`DefaultValueRule`).
- `tests/Module/PaymentProcedure/Service/PaymentProcedureServiceTest.php`: cómo mockear repositorios.

## Código de Referencia

### Enum de errores

```php
enum ProcedureTaxError: string
{
    case ProcedureNotFound = 'procedure_not_found';
    case ProcedureInactive = 'procedure_inactive';
    case TaxNotFound = 'tax_not_found';
    case TaxInactive = 'tax_inactive';
    case AlreadyAssigned = 'already_assigned';
    case NotAssigned = 'not_assigned';

    public function message(): string
    {
        return match ($this) {
            self::ProcedureNotFound => 'Procedure not found',
            self::ProcedureInactive => 'Procedure is inactive',
            self::TaxNotFound => 'Tax not found',
            self::TaxInactive => 'Tax is inactive',
            self::AlreadyAssigned => 'Tax is already assigned to this procedure',
            self::NotAssigned => 'Tax is not assigned to this procedure',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::ProcedureNotFound, self::TaxNotFound, self::NotAssigned => 404,
            self::AlreadyAssigned => 409,
            self::ProcedureInactive, self::TaxInactive => 422,
        };
    }
}
```

```php
final class InvalidProcedureTaxItemsException extends \DomainException
{
    /** @param array<int, ProcedureTaxError> $itemErrors index → error */
    public function __construct(public readonly array $itemErrors)
    {
        parent::__construct('Invalid procedure tax items');
    }
}
```

### Estructura del Controller

```php
final class PaymentProcedureTaxController
{
    private PaymentProcedureTaxService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentProcedureTaxService::class);
    }

    public function store(Request $request): Response
    {
        $procedureId = $this->parseProcedureId($request);
        if ($procedureId instanceof Response) {
            return $procedureId;
        }

        $dto = AddProcedureTaxesRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $this->service->add($procedureId, $dto->items, $request->getNotaryId());
        } catch (ProcedureTaxException $e) {
            return $this->errorResponse($e->error);
        } catch (InvalidProcedureTaxItemsException $e) {
            if (!$dto->isBulk) {
                return $this->errorResponse(reset($e->itemErrors));
            }
            $errors = [];
            foreach ($e->itemErrors as $index => $error) {
                $errors["taxes.$index.taxId"] = $error->message();
            }
            return Response::badRequest('Validation failed', $errors);
        }

        return Response::created();
    }

    private function errorResponse(ProcedureTaxError $error): Response
    {
        return match ($error->httpStatus()) {
            404 => Response::notFound($error->message()),
            409 => Response::conflict($error->message()),
            422 => Response::unprocessable($error->message()),
        };
    }
}
```

`parseProcedureId()` es igual a `PaymentProcedureController::parseId()` (mensajes `Invalid ID format` / `Procedure not found`). `parseTaxId()` lee `getRouteParam('taxId')` → `Invalid tax ID format` / `Tax is not assigned to this procedure`.

### Estructura del Service

```php
final class PaymentProcedureTaxService
{
    public function __construct(
        private readonly PaymentProcedureTaxRepository $repository,
        private readonly PaymentProcedureRepository $procedureRepository,
        private readonly PaymentTaxRepository $taxRepository,
    ) {
    }

    /** @param ProcedureTaxItem[] $items */
    public function add(int $procedureId, array $items, int $notaryId): void
    {
        $this->requireProcedure($procedureId, $notaryId, requireActive: true);

        $taxIds = array_map(fn (ProcedureTaxItem $i) => $i->taxId, $items);
        $taxes = $this->taxRepository->findByIdsAndNotary($taxIds, $notaryId);
        $statuses = $this->repository->findStatuses($procedureId, $taxIds);

        $errors = [];
        foreach ($items as $index => $item) {
            $tax = $taxes[$item->taxId] ?? null;
            $error = match (true) {
                $tax === null => ProcedureTaxError::TaxNotFound,
                $tax->statusId !== PaymentTax::STATUS_ACTIVE => ProcedureTaxError::TaxInactive,
                ($statuses[$item->taxId] ?? null) === PaymentProcedureTax::STATUS_ACTIVE
                    => ProcedureTaxError::AlreadyAssigned,
                default => null,
            };
            if ($error !== null) {
                $errors[$index] = $error;
            }
        }

        if ($errors !== []) {
            throw new InvalidProcedureTaxItemsException($errors);   // nothing written
        }

        $this->repository->upsertMany($procedureId, $items);
    }

    private function requireProcedure(int $id, int $notaryId, bool $requireActive): void
    {
        $procedure = $this->procedureRepository->findByIdAndNotary($id, $notaryId)
            ?? throw new ProcedureTaxException(ProcedureTaxError::ProcedureNotFound);

        if ($requireActive && $procedure->statusId !== PaymentProcedure::STATUS_ACTIVE) {
            throw new ProcedureTaxException(ProcedureTaxError::ProcedureInactive);
        }
    }
}
```

> `PaymentProcedure::STATUS_ACTIVE` y `PaymentTax::STATUS_ACTIVE` ya existen.

### DefaultValueRule

```php
final class DefaultValueRule
{
    public static function validate(mixed $value): ?string
    {
        // is_int/is_float: bool, "500" y arrays quedan fuera
        return is_int($value) || is_float($value) ? null : 'Default value must be a number';
    }

    public static function normalize(int|float|null $value): float
    {
        return max(0.0, (float) ($value ?? 0));   // también convierte -0.0 en 0.0
    }
}
```

En POST, `null` es válido (= 0): llama `validate()` solo si la clave existe **y** el valor no es `null`. En PUT, la clave es requerida y `null` es inválido.

## Base de Datos

### Tablas Involucradas
- `payment_procedure_taxes`: `id_procedure`, `id_tax`, `default_value` (double NOT NULL DEFAULT 0), `id_status` (1/2, NULL → 1), `created_at`, `modified_at` (ON UPDATE). MyISAM, `utf8mb3_bin`, **sin índices**.
- `payment_procedures`: solo lectura vía `PaymentProcedureRepository::findByIdAndNotary()`.
- `payment_taxes`: solo lectura vía `PaymentTaxRepository::findByIdsAndNotary()` (nuevo).
- `view_payment_procedure_taxes`: **no usarla**. Hace LEFT JOIN desde procedures (devuelve filas NULL cuando no hay taxes) y no filtra `pt.id_notary`. Es más claro y seguro el query directo.

Estado actual en local: 11 filas, todas activas, 0 pares duplicados, 0 cruces entre notarías, 0 negativos, máx. 5 taxes por procedimiento.

### Queries Sugeridas

```sql
-- GET: active assignments of an active tax, scoped by notary on both parents
SELECT ppt.id_procedure, ppt.id_tax, pt.tax_name, ppt.default_value, ppt.id_status
FROM payment_procedure_taxes ppt
JOIN payment_procedures pp ON pp.id_procedure = ppt.id_procedure AND pp.id_notary = :notaryId
JOIN payment_taxes pt      ON pt.id_tax = ppt.id_tax           AND pt.id_notary = :notaryId2
WHERE ppt.id_procedure = :procedureId
  AND ppt.id_status = 1
  AND pt.id_status = 1
ORDER BY CONVERT(pt.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC, ppt.id_tax ASC;
```
> `Connection` usa `ATTR_EMULATE_PREPARES = false` (prepares nativos), así que **no se puede repetir un placeholder con nombre**: usa `:notaryId` y `:notaryId2` con el mismo valor (ver el comentario en `DeedRepository.php:97`).

```sql
-- Existing rows (any status) for the requested taxes
SELECT id_tax, id_status
FROM payment_procedure_taxes
WHERE id_procedure = :procedureId AND id_tax IN (:t0, :t1, :t2);
```

```sql
-- POST: insert new + reactivate inactive in ONE statement (requires the UNIQUE index)
INSERT INTO payment_procedure_taxes (id_procedure, id_tax, default_value, id_status)
VALUES (:p0, :t0, :v0, 1), (:p1, :t1, :v1, 1)
ON DUPLICATE KEY UPDATE default_value = VALUES(default_value), id_status = 1;
```

```sql
-- PUT
UPDATE payment_procedure_taxes ppt
JOIN payment_procedures pp ON pp.id_procedure = ppt.id_procedure
SET ppt.default_value = :value
WHERE ppt.id_procedure = :procedureId AND ppt.id_tax = :taxId
  AND ppt.id_status = 1 AND pp.id_notary = :notaryId;

-- DELETE (soft)
UPDATE payment_procedure_taxes ppt
JOIN payment_procedures pp ON pp.id_procedure = ppt.id_procedure
SET ppt.id_status = 2
WHERE ppt.id_procedure = :procedureId AND ppt.id_tax = :taxId
  AND ppt.id_status = 1 AND pp.id_notary = :notaryId;
```

Para los `IN (...)` y el multi-row INSERT, genera los placeholders en un loop (`":t$i"`) y arma el array de params en paralelo. **Nunca** concatenes valores.

**Ojo con `default_value`:** al bindear floats, PDO los manda como string; es correcto. No uses `PDO::PARAM_INT` para este campo.

### Migraciones Necesarias

`.db/pending/20261110_170000_add_unique_procedure_tax_to_payment_procedure_taxes.sql`:

```sql
-- Migration: add_unique_procedure_tax_to_payment_procedure_taxes
-- Description: Natural key (id_procedure, id_tax) for payment_procedure_taxes.
--              Prevents duplicate assignments and enables INSERT ... ON DUPLICATE KEY UPDATE.
--              Also serves GET /api/v3/payment-procedures/{id}/taxes (prefix id_procedure).
-- Author: Roberto Trejo
-- Date: 2026-11-10

-- PRE-CHECK (run in production BEFORE applying; must return 0 rows):
-- SELECT id_procedure, id_tax, COUNT(*) AS c, GROUP_CONCAT(id_status) AS statuses
-- FROM payment_procedure_taxes
-- GROUP BY id_procedure, id_tax
-- HAVING c > 1;

-- UP
ALTER TABLE payment_procedure_taxes
    ADD UNIQUE INDEX uq_payment_procedure_taxes_procedure_tax (id_procedure, id_tax);

-- DOWN (rollback)
-- ALTER TABLE payment_procedure_taxes DROP INDEX uq_payment_procedure_taxes_procedure_tax;
```

Si el pre-check devuelve filas en producción, **detente y avisa**. Hay que decidir qué fila conservar (normalmente la activa con el `modified_at` más reciente) antes de aplicar. No lo resuelvas por tu cuenta.

## Checklist Pre-PR
- [ ] Pre-check de duplicados ejecutado; migración aplicada en local y movida a `.db/applied/`
- [ ] Código sigue la arquitectura definida (el Service no conoce HTTP)
- [ ] Tests escritos y pasando (`docker-compose exec api vendor/bin/phpunit`)
- [ ] Sin warnings de PHP
- [ ] `docs/openapi.yaml` actualizado (incluye `oneOf` single/bulk y la diferencia 404/409/422 vs 400 en bulk)
- [ ] `.claude/CLAUDE.md`: 409 y 422 agregados a HTTP Status Codes
- [ ] Revisé `technical_acceptance_criteria.md`
- [ ] PR a `develop` (QA cierra)

## Preguntas Frecuentes

**P: Los ejemplos del PO muestran 201 con body, PUT 200 con body y `{ "data": [...] }`. ¿Los sigo?**
R: No. ADR-005: POST → 201 vacío, PUT/DELETE → 204, GET → array plano. Así lo decidió el equipo, por consistencia con 08/09.

**P: ¿Por qué "Tax not found" es 404 en single pero 400 en bulk?**
R: ADR-007. En bulk hay que reportar varios errores a la vez con su índice, y eso solo encaja en el formato `400 + errors`. Los errores del **procedimiento** sí son 404/422 en ambos casos.

**P: Si el bulk trae un tax ya activo, ¿lo ignoro y sigo con los demás?**
R: No. Es un error por item (`Tax is already assigned to this procedure`) y se rechaza todo el request. Todo o nada.

**P: ¿El bulk se rechaza si el procedimiento ya tiene impuestos?**
R: No. Se permite siempre (ADR-007).

**P: ¿Puedo listar los impuestos de un procedimiento inactivo?**
R: Sí: GET → 200. También DELETE. Solo POST y PUT exigen procedimiento activo.

**P: Un tax está asignado y activo, pero se desactivó en `payment_taxes`. ¿Qué pasa?**
R: No sale en el GET (ADR-006). PUT → 422 `Tax is inactive`. DELETE → 204 (permite limpiar). POST → 422 single / 400 bulk.

**P: ¿Por qué `ON DUPLICATE KEY UPDATE` si ya validé que no está activo?**
R: Porque así una sola sentencia cubre nuevos **y** rehabilitados (status=2), sin transacciones. La validación previa es la que genera el 409; el upsert es solo el mecanismo de escritura.

**P: El path dice `/taxes/{taxId}` pero el router usa `{id}` para el procedimiento. ¿Choca?**
R: No. Son dos parámetros con nombre distinto: `getRouteParam('id')` y `getRouteParam('taxId')`. El regex del router acepta `taxId` (`[a-zA-Z_]+`).

**P: ¿Debo validar que el tax pertenece a la notaría en el DELETE?**
R: No hace falta una consulta extra: la fila se busca por `id_procedure` (ya validado contra la notaría) y el UPDATE incluye el JOIN con `pp.id_notary`. Los inserts nuevos siempre validan el tax, así que no puede existir una fila cross-tenant (hoy: 0 en local).

**P: ¿Necesito `rowCount()` después del UPDATE para detectar 404?**
R: No lo uses para eso. En MySQL/MariaDB, `rowCount()` devuelve 0 cuando el valor no cambia (por ejemplo, PUT con el mismo `defaultValue`), y eso daría un falso 404. El 404 lo decide el Service con `findStatuses()` antes del UPDATE.

## Contacto
Para dudas de negocio: revisar `specs/10_payment_procedure_taxes_crud/po/`
Para dudas de arquitectura: `specs/10_payment_procedure_taxes_crud/arch/implementation_plan.md` (ADRs) o consultar con el arquitecto
