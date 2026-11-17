# Notas para el Developer

## Contexto Rápido

Endpoint de solo lectura `GET /api/v3/payment-procedures/taxes`. Devuelve **todos** los procedimientos activos de la notaría, cada uno con sus impuestos activos y `defaultValue`. Así el cliente no tiene que llamar `GET /payment-procedures/{id}/taxes` por cada procedimiento.

Puntos clave:
- **Array plano**, no `{ "data": [...] }` (ADR-001). Notaría vacía → `[]`.
- **Una sola query** con LEFT JOIN; los filtros de status van en el `ON` (ADR-002).
- La ruta va **antes** de `GET /payment-procedures/{id}` o el router la toma como `id = "taxes"`.
- Sin migraciones. Sin cambios a endpoints, DTOs ni entities existentes.

## Archivos Clave

| Archivo | Acción | Propósito |
|---------|--------|-----------|
| `src/Module/PaymentProcedureTax/Entity/ProcedureWithTaxes.php` | Crear | Read model: procedimiento + `PaymentProcedureTax[]` |
| `src/Module/PaymentProcedureTax/Repository/PaymentProcedureTaxRepository.php` | Modificar | `findActiveProceduresWithTaxes()` |
| `src/Module/PaymentProcedureTax/Service/PaymentProcedureTaxService.php` | Modificar | `getAllWithTaxes()` |
| `src/Module/PaymentProcedureTax/DTO/ProcedureWithTaxesResponse.php` | Crear | Entity → array de API |
| `src/Module/PaymentProcedureTax/Controller/PaymentProcedureTaxController.php` | Modificar | `listAll()` |
| `src/Config/routes.php` | Modificar | Ruta nueva antes de `/{id}` |
| `docs/openapi.yaml` | Modificar | Path y schemas nuevos |
| `tests/Module/PaymentProcedureTax/DTO/ProcedureWithTaxesResponseTest.php` | Crear | Forma del DTO |
| `tests/Module/PaymentProcedureTax/Service/PaymentProcedureTaxServiceTest.php` | Modificar | Delegación del Service |

## Patrones a Seguir

- `PaymentProcedureTaxRepository::findActiveByProcedure()`: mismos JOINs, `:notaryId`/`:notaryId2`, collation para ordenar y `PaymentProcedureTax::fromRow()`.
- `ProcedureTaxResponse`: estilo de `fromEntity()` / `fromCollection()`.
- `PaymentProcedureTaxServiceTest`: cómo mockear los 3 repos.

## Código de Referencia

### Query

```sql
SELECT pp.id_procedure, pp.procedure_name,
       pt.id_tax, pt.tax_name, ppt.default_value, ppt.id_status
FROM payment_procedures pp
LEFT JOIN payment_procedure_taxes ppt
       ON ppt.id_procedure = pp.id_procedure
      AND ppt.id_status = 1
LEFT JOIN payment_taxes pt
       ON pt.id_tax = ppt.id_tax
      AND pt.id_notary = :notaryId2
      AND pt.id_status = 1
WHERE pp.id_notary = :notaryId
  AND pp.id_status = 1
ORDER BY CONVERT(pp.procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC,
         pp.id_procedure ASC,
         CONVERT(pt.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC,
         pt.id_tax ASC;
```

Por qué así:
- Los filtros de `ppt` y `pt` van en el **`ON`**. Si van en el `WHERE`, el LEFT JOIN se vuelve INNER y desaparecen los procedimientos sin impuestos.
- Se selecciona **`pt.id_tax`**, no `ppt.id_tax`. Si la relación está activa pero el impuesto está inactivo o es de otra notaría, la fila llega con `pt.id_tax = NULL` y se descarta en PHP. El procedimiento sí se conserva.
- En MariaDB los NULL quedan primero en `ORDER BY ... ASC`. Por eso, las filas descartadas no alteran el orden de los impuestos válidos.
- Prepares nativos: no se puede repetir `:notaryId`. Usa `:notaryId2` con el mismo valor, como en `findActiveByProcedure`.

### Repository

```php
use App\Module\PaymentProcedure\Entity\PaymentProcedure;
use App\Module\PaymentTax\Entity\PaymentTax;

/**
 * Active procedures of the notary, each with its active assignments of active taxes.
 * Procedures without taxes are included with an empty list.
 *
 * @return ProcedureWithTaxes[]
 */
public function findActiveProceduresWithTaxes(int $notaryId): array
{
    $sql = '...'; // query de arriba, con PaymentProcedure::STATUS_ACTIVE / PaymentProcedureTax::STATUS_ACTIVE / PaymentTax::STATUS_ACTIVE

    $stmt = $this->db->prepare($sql);
    $stmt->execute(['notaryId' => $notaryId, 'notaryId2' => $notaryId]);

    /** @var array<int, array{name: string, taxes: PaymentProcedureTax[]}> $groups */
    $groups = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $procedureId = (int) $row['id_procedure'];
        $groups[$procedureId] ??= ['name' => (string) $row['procedure_name'], 'taxes' => []];

        if ($row['id_tax'] !== null) {
            $groups[$procedureId]['taxes'][] = PaymentProcedureTax::fromRow($row);
        }
    }

    $result = [];
    foreach ($groups as $procedureId => $group) {
        $result[] = new ProcedureWithTaxes($procedureId, $group['name'], $group['taxes']);
    }

    return $result;
}
```

> Los arrays de PHP con clave entera conservan el orden de inserción, así que se mantiene el orden de la query. `PaymentProcedureTax::fromRow()` ya maneja tipos string de PDO y `id_status` NULL.

### Entity

```php
final class ProcedureWithTaxes
{
    /**
     * @param PaymentProcedureTax[] $taxes
     */
    public function __construct(
        public readonly int $procedureId,
        public readonly string $procedureName,
        public readonly array $taxes,
    ) {
    }
}
```

### DTO

```php
final class ProcedureWithTaxesResponse
{
    /**
     * @return array{procedureId: int, procedureName: string, taxes: list<array{taxId: int, taxName: string, defaultValue: float}>}
     */
    public static function fromEntity(ProcedureWithTaxes $procedure): array
    {
        return [
            'procedureId' => $procedure->procedureId,
            'procedureName' => $procedure->procedureName,
            'taxes' => array_map(
                static fn (PaymentProcedureTax $tax): array => [
                    'taxId' => $tax->taxId,
                    'taxName' => $tax->taxName,
                    'defaultValue' => $tax->defaultValue,
                ],
                array_values($procedure->taxes),
            ),
        ];
    }

    /** @param ProcedureWithTaxes[] $procedures */
    public static function fromCollection(array $procedures): array
    {
        return array_map(self::fromEntity(...), array_values($procedures));
    }
}
```

> `array_values` garantiza una lista, así que `json_encode` produce `[...]` y nunca `{...}`. Un array vacío se codifica como `[]`.

### Controller y ruta

```php
public function listAll(Request $request): Response
{
    $procedures = $this->service->getAllWithTaxes($request->getNotaryId());

    return Response::json(ProcedureWithTaxesResponse::fromCollection($procedures));
}
```

```php
// src/Config/routes.php — MUST be registered before /payment-procedures/{id}
$router->get('/api/v3/payment-procedures', [PaymentProcedureController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/payment-procedures/taxes', [PaymentProcedureTaxController::class, 'listAll'])->withMiddleware('auth');
$router->get('/api/v3/payment-procedures/{id}', [PaymentProcedureController::class, 'show'])->withMiddleware('auth');
```

### OpenAPI

```yaml
  /payment-procedures/taxes:
    get:
      tags:
        - PaymentProcedureTaxes
      summary: List active payment procedures with their taxes
      description: |
        Returns every active procedure of the notary with its active taxes, in a single call.

        - Procedures without active taxes are included with `taxes: []`
        - Inactive procedures, inactive assignments and taxes inactive in the catalog are excluded
        - Procedures and taxes are in natural alphabetical order (case- and accent-insensitive; ties by ID)
        - Returns `[]` when the notary has no active procedures
      operationId: listPaymentProceduresWithTaxes
      security:
        - bearerAuth: []
      responses:
        '200':
          description: Procedures with their taxes (plain array, no envelope)
          content:
            application/json:
              schema:
                type: array
                items:
                  $ref: '#/components/schemas/ProcedureWithTaxesResponse'
        '401':
          description: Unauthorized
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
```

Schemas: `ProcedureWithTaxesResponse` (`procedureId`, `procedureName`, `taxes` → array de `ProcedureTaxSummary`) y `ProcedureTaxSummary` (`taxId`, `taxName`, `defaultValue` number/double). Pon el path antes de `/payment-procedures/{id}` en el YAML.

## Base de Datos

### Tablas Involucradas (solo lectura)
- `payment_procedures`: `id_procedure`, `id_notary`, `procedure_name` (`utf8mb3_bin`), `id_status`
- `payment_procedure_taxes`: `id_procedure`, `id_tax`, `default_value` (double), `id_status`; UNIQUE `(id_procedure, id_tax)` (spec 10)
- `payment_taxes`: `id_tax`, `id_notary`, `tax_name` (`utf8mb3_bin`), `id_status`
- `view_payment_procedure_taxes`: **no usarla**, porque no filtra status ni `pt.id_notary`.

### Pre-check opcional (local)
Debe devolver 0. Si no, avisa al arquitecto: esas filas no se listarían, igual que hoy en `GET /{id}/taxes`.
```sql
SELECT COUNT(*) FROM payment_procedure_taxes WHERE id_status IS NULL;
```

### Migraciones Necesarias
Ninguna.

## Checklist Pre-PR
- [ ] Ruta registrada antes de `/payment-procedures/{id}`; probado `GET /payment-procedures/taxes` y `GET /payment-procedures/5`
- [ ] Query probada en phpMyAdmin con: procedimiento sin taxes, relación inactiva, tax inactivo, procedimiento inactivo
- [ ] Tests escritos y pasando (`docker-compose exec api vendor/bin/phpunit`)
- [ ] Sin warnings de PHP
- [ ] `docs/openapi.yaml` actualizado
- [ ] Revisé `technical_acceptance_criteria.md`
- [ ] PR a `develop` (QA cierra)

## Preguntas Frecuentes

**P: El PO muestra `{ "data": [...] }`. ¿Lo sigo?**
R: No. ADR-001: array plano, igual que el resto de Payments. Notaría vacía → `[]`.

**P: El PO muestra `500.00` y `0.00`. ¿Formateo a 2 decimales?**
R: No. ADR-004: `defaultValue` es un `float` y sale como número JSON (`500`, `0`), igual que en `GET /{id}/taxes`. JSON no distingue `500` de `500.00`. No cambies los flags de `json_encode` en `Response`, porque eso afecta a toda la API.

**P: ¿Puedo reutilizar `findActiveByProcedure()` en un loop?**
R: No. Eso es exactamente el N+1 que este endpoint elimina. Usa una sola query.

**P: ¿Por qué el Repository agrupa y no el Service?**
R: Agrupar filas en `ProcedureWithTaxes` es hidratación de la entity, no regla de negocio. Así el Repository sigue devolviendo entities y el Service queda como una delegación simple.

**P: ¿Y si llegan `?status=2` o `?name=x`?**
R: Se ignoran. Los filtros están fuera de alcance; no respondas 400.

**P: ¿Hay que validar que el procedimiento exista?**
R: No hay id en la ruta. La lista vacía es una respuesta válida (200 `[]`).

## Contacto
Para dudas de negocio: revisar `specs/16_payment_procedures_taxes_list/po/`
Para dudas de arquitectura: `specs/16_payment_procedures_taxes_list/arch/implementation_plan.md` (ADRs) o consultar con el arquitecto
