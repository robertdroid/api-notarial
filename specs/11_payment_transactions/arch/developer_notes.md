# Notas para el Developer

## Contexto Rápido

Hay que poder crear, editar y consultar **transacciones** (movimientos): un cargo a un cliente por un procedimiento, con honorarios (`fee`) e impuestos (`taxes`). Son 4 endpoints sobre `payments_transactions` + `payments_transaction_details`.

Lo nuevo respecto a 07–10:

- **Transacciones de BD reales**: las tablas pasan a InnoDB y se agrega `TransactionManager` en Core. El Service marca el límite atómico; el Repository nunca abre transacciones.
- **Dos modos de `taxes`**: si el procedimiento **no** tiene impuestos configurados, `taxes` lo manda el cliente y no hay detalles. Si **sí** tiene, `taxes` se calcula de los detalles.
- **POST idempotente por `uuid`**: uuid repetido → 200 con la transacción existente, sin tocarla.
- **Respuestas con body** (201/200), a diferencia de 08/09/10.
- **La v1 sigue escribiendo en estas tablas.** La migración es lo más delicado: lee la sección de Base de Datos completa antes de tocar nada.

Hay varios puntos donde el plan **se aparta del PO**; están en la sección "Diferencias con el PO" al final. Ante la duda, mandan los ADRs.

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Core/Database/TransactionManager.php` | **Nuevo.** `transactional(callable)` con begin/commit/rollback |
| `src/Module/PaymentTransaction/Controller/PaymentTransactionController.php` | `store`, `show`, `showByUuid`, `update`; traduce excepciones a HTTP |
| `src/Module/PaymentTransaction/Service/PaymentTransactionService.php` | Idempotencia, validaciones, modo de taxes, filtrado de detalles, límite transaccional |
| `src/Module/PaymentTransaction/Service/CreateTransactionResult.php` | `{transaction, created}` para decidir 201 o 200 |
| `src/Module/PaymentTransaction/Repository/PaymentTransactionRepository.php` | SELECT con JOINs, INSERT/UPDATE de cabecera, DELETE + INSERT multi-row de detalles |
| `src/Module/PaymentTransaction/Entity/PaymentTransaction.php` | Read model de la cabecera (incluye nombres y detalles) |
| `src/Module/PaymentTransaction/Entity/PaymentTransactionDetail.php` | Read model del detalle (incluye `taxName`) |
| `src/Module/PaymentTransaction/Exception/TransactionError.php` | Enum: mensaje + código HTTP |
| `src/Module/PaymentTransaction/Exception/TransactionException.php` | Excepción de dominio |
| `src/Module/PaymentTransaction/Exception/DuplicateTransactionUuidException.php` | Choque con el UNIQUE (carrera de dos POST) |
| `src/Module/PaymentTransaction/DTO/TransactionFieldRules.php` | Reglas de validación compartidas |
| `src/Module/PaymentTransaction/DTO/TransactionDetailsParser.php` | Valida `details` → `TransactionDetailItem[]` |
| `src/Module/PaymentTransaction/DTO/TransactionDetailItem.php` | VO de un detalle de entrada |
| `src/Module/PaymentTransaction/DTO/CreateTransactionRequest.php` | Body del POST |
| `src/Module/PaymentTransaction/DTO/UpdateTransactionRequest.php` | Body del PUT |
| `src/Module/PaymentTransaction/DTO/TransactionResponse.php` | Entity → array de API |
| `src/bootstrap.php` | Registrar `TransactionManager`, Repository y Service |
| `src/Config/routes.php` | 4 rutas |

## Patrones a Seguir

- `src/Module/PaymentProcedureTax/`: es el módulo más parecido. Copia el patrón de enum + excepción (`ProcedureTaxError`), el `errorResponse()` del Controller, el Service con varios repositorios y los placeholders generados en loop para `IN` y multi-row INSERT.
- `src/Module/PaymentClient/DTO/ClientFieldRules.php`: estilo de las reglas estáticas.
- `tests/Module/PaymentProcedureTax/Service/PaymentProcedureTaxServiceTest.php`: cómo mockear repositorios y verificar `never()`.

Métodos existentes que vas a reutilizar **sin modificar**:

| Método | Uso |
|--------|-----|
| `PaymentClientRepository::findByIdAndNotary()` | Existencia, tenencia y status del cliente |
| `PaymentProcedureRepository::findByIdAndNotary()` | Existencia, tenencia y status del procedimiento |
| `PaymentProcedureTaxRepository::findActiveByProcedure()` | Lista vacía = modo manual |
| `PaymentTaxRepository::findByIdsAndNotary()` | Impuestos de los detalles, indexados por id, en un query |

## Código de Referencia

### TransactionManager

```php
namespace App\Core\Database;

use PDO;

class TransactionManager
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            return $operation();
        }

        $this->db->beginTransaction();

        try {
            $result = $operation();
            $this->db->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}
```

### Estructura del Controller

```php
final class PaymentTransactionController
{
    private PaymentTransactionService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(PaymentTransactionService::class);
    }

    public function store(Request $request): Response
    {
        $dto = CreateTransactionRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $result = $this->service->create($dto, $request->getNotaryId());
        } catch (TransactionException $e) {
            return $this->errorResponse($e->error);
        }

        $body = TransactionResponse::fromEntity($result->transaction);

        return $result->created ? Response::created($body) : Response::json($body);
    }

    public function showByUuid(Request $request): Response
    {
        $uuid = $request->getQueryParam('uuid');
        if ($uuid === null) {
            return Response::badRequest('uuid query parameter is required');
        }

        // ?uuid[]=x llega como array: TransactionFieldRules::uuid() lo rechaza por no ser string
        if (TransactionFieldRules::uuid($uuid) !== null) {
            return Response::badRequest('Invalid uuid format');
        }

        try {
            $transaction = $this->service->getByUuid($uuid, $request->getNotaryId());
        } catch (TransactionException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(TransactionResponse::fromEntity($transaction));
    }

    private function errorResponse(TransactionError $error): Response
    {
        return match ($error->httpStatus()) {
            400 => Response::badRequest($error->message()),
            404 => Response::notFound($error->message()),
            default => Response::unprocessable($error->message()),
        };
    }
}
```

`parseId()` es igual al de `PaymentClientController` con el mensaje `Transaction not found`.

### Estructura del Service

```php
final class PaymentTransactionService
{
    public function __construct(
        private readonly PaymentTransactionRepository $repository,
        private readonly PaymentClientRepository $clientRepository,
        private readonly PaymentProcedureRepository $procedureRepository,
        private readonly PaymentTaxRepository $taxRepository,
        private readonly PaymentProcedureTaxRepository $procedureTaxRepository,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function create(CreateTransactionRequest $dto, int $notaryId): CreateTransactionResult
    {
        // Idempotency first: a retry must succeed even if the catalog changed meanwhile (ADR-003)
        $existing = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId);
        if ($existing !== null) {
            return new CreateTransactionResult($existing, created: false);
        }

        $this->requireActiveClient($dto->clientId, $notaryId);
        $this->requireActiveProcedure($dto->procedureId, $notaryId);

        [$details, $taxes] = $this->resolveDetailsAndTaxes(
            $dto->procedureId,
            $notaryId,
            $dto->details,
            $dto->taxes,
        );
        // In manual mode, $details is [] and $taxes comes from the request
        $total = round($dto->fee + $taxes, 2);

        try {
            $id = $this->transactions->transactional(function () use ($dto, $notaryId, $details, $taxes, $total): int {
                $id = $this->repository->insert($dto, $notaryId, $taxes, $total);
                $this->repository->insertDetails($id, $details);

                return $id;
            });
        } catch (DuplicateTransactionUuidException) {
            // Lost the race against a concurrent POST with the same uuid: return the winner
            $winner = $this->repository->findByUuidAndNotary($dto->uuid, $notaryId)
                ?? throw new \LogicException('Duplicate uuid reported but transaction not found');

            return new CreateTransactionResult($winner, created: false);
        }

        return new CreateTransactionResult($this->getById($id, $notaryId), created: true);
    }

    public function update(int $id, UpdateTransactionRequest $dto, int $notaryId): PaymentTransaction
    {
        $this->transactions->transactional(function () use ($id, $dto, $notaryId): void {
            $current = $this->repository->findByIdAndNotary($id, $notaryId, forUpdate: true)
                ?? throw new TransactionException(TransactionError::TransactionNotFound);

            if ($dto->clientId !== null && $dto->clientId !== $current->clientId) {
                throw new TransactionException(TransactionError::CannotChangeClient);
            }
            if ($dto->uuid !== null && $dto->uuid !== $current->uuid) {
                throw new TransactionException(TransactionError::CannotChangeUuid);
            }
            if ($dto->procedureId !== $current->procedureId) {
                $this->requireActiveProcedure($dto->procedureId, $notaryId);
            }

            [$details, $taxes] = $this->resolveDetailsAndTaxes(
                $dto->procedureId,
                $notaryId,
                $dto->details,
                $dto->taxes,
                keepTaxIds: $current->detailTaxIds(),
            );

            $this->repository->deleteDetails($id);
            $this->repository->insertDetails($id, $details);
            $this->repository->update($id, $notaryId, $dto, $taxes, round($dto->fee + $taxes, 2));
        });

        return $this->getById($id, $notaryId);
    }

    /**
     * @param TransactionDetailItem[] $items
     * @param int[] $keepTaxIds taxes already on the transaction: kept even if inactive now (ADR-006)
     * @return array{0: TransactionDetailItem[], 1: float} valid details and the taxes amount
     */
    private function resolveDetailsAndTaxes(
        int $procedureId,
        int $notaryId,
        array $items,
        float $requestTaxes,
        array $keepTaxIds = [],
    ): array {
        $isManual = $this->procedureTaxRepository->findActiveByProcedure($procedureId, $notaryId) === [];

        if ($isManual) {
            // Details are silently ignored in manual mode; taxes comes from the request
            return [[], round($requestTaxes, 2)];
        }

        $taxIds = array_map(static fn (TransactionDetailItem $i): int => $i->taxId, $items);
        $taxes = $this->taxRepository->findByIdsAndNotary($taxIds, $notaryId);

        $valid = [];
        $sum = 0.0;
        foreach ($items as $item) {
            $tax = $taxes[$item->taxId] ?? null;

            if (isset($valid[$item->taxId]) || $tax === null) {
                continue;   // repeated taxId (first wins) or unknown / other notary
            }

            $isActive = $tax->statusId === PaymentTax::STATUS_ACTIVE;
            if (!$isActive && !in_array($item->taxId, $keepTaxIds, true)) {
                continue;   // inactive and new to this transaction
            }

            $valid[$item->taxId] = $item;
            $sum += $item->subtotal();
        }

        return [array_values($valid), round($sum, 2)];
    }
}
```

Notas sobre el ejemplo:

- En `create`, las validaciones van **fuera** de `transactional()`: son solo lecturas y así la transacción de BD dura lo mínimo. En `update` van **dentro**, porque dependen de la fila bloqueada con `FOR UPDATE`.
- En `update`, las `TransactionException` lanzadas dentro del callable provocan rollback y se propagan tal cual al Controller.
- `requireActiveClient()` y `requireActiveProcedure()` siguen el patrón de `requireProcedure()` del spec 10.
- La firma exacta de `repository->insert()` / `update()` la decides tú (pasar el DTO o los campos sueltos); lo importante es que `taxes` y `total` lleguen ya calculados.

### Validación de fecha

```php
public static function date(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    // The round-trip rejects 2024-02-30 (PHP would roll it over to March 1st) and 2024-1-5
    return $date !== false && $date->format('Y-m-d') === $value;
}
```

### Detección del uuid duplicado en el Repository

```php
try {
    $stmt->execute($params);
} catch (\PDOException $e) {
    // 1062 = ER_DUP_ENTRY. The only unique key besides the PK is (id_notary, uuid)
    if (($e->errorInfo[1] ?? null) === 1062) {
        throw new DuplicateTransactionUuidException(previous: $e);
    }

    throw $e;
}
```

Usa `errorInfo[1]` (código del driver). `getCode()` devuelve el SQLSTATE `23000`, que también cubre otras violaciones de integridad.

## Base de Datos

### Tablas Involucradas

- `payments_transactions`: `id_transaction` (PK auto), `uuid` varchar(20), `id_notary`, `id_client`, `id_procedure`, `reference`/`responsible`/`comments` (TEXT NOT NULL **sin default**: siempre envía `''`, nunca NULL), `date` datetime, `fee`/`taxes`/`total` double, `id_status`.
- `payments_transaction_details`: `id_transaction`, `id_tax`, `quantity` int, `amount` double, `date` datetime NULL, `comments` TEXT NULL. Sin PK hoy.
- Ambas: MyISAM, `utf8mb3_bin`. **El `uuid` distingue mayúsculas de minúsculas**: no lo normalices.
- `payments_clients`, `payment_procedures`, `payment_taxes`, `payment_procedure_taxes`: solo lectura, vía sus repositorios.

`sql_mode` incluye `STRICT_TRANS_TABLES`: con InnoDB, un valor fuera de rango o un NULL en columna NOT NULL es **error**, no warning. Por eso el DTO limita montos, cantidades y longitudes.

### Queries Sugeridas

```sql
-- Header by id (same query by uuid: WHERE t.uuid = :uuid AND t.id_notary = :notaryId)
SELECT t.id_transaction, t.uuid, t.id_notary, t.id_client, t.id_procedure,
       t.reference, t.`date`, t.responsible, t.fee, t.taxes, t.total, t.comments, t.id_status,
       c.full_name AS client_name, p.procedure_name
FROM payments_transactions t
LEFT JOIN payments_clients c   ON c.id_client = t.id_client       AND c.id_notary = t.id_notary
LEFT JOIN payment_procedures p ON p.id_procedure = t.id_procedure AND p.id_notary = t.id_notary
WHERE t.id_transaction = :id AND t.id_notary = :notaryId;
```

```sql
-- Details of one transaction
SELECT d.id_tax, x.tax_name, d.quantity, d.amount, d.`date`, d.comments
FROM payments_transaction_details d
LEFT JOIN payment_taxes x ON x.id_tax = d.id_tax
WHERE d.id_transaction = :transactionId
ORDER BY CONVERT(x.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC, d.id_tax ASC;
```

Los detalles se consultan **después** de validar que la cabecera es de la notaría, nunca con un id que venga directo del cliente.

```sql
-- Row lock at the start of PUT (inside the transaction)
SELECT id_transaction FROM payments_transactions
WHERE id_transaction = :id AND id_notary = :notaryId
FOR UPDATE;
```

```sql
-- Header insert: totals already computed, single statement
INSERT INTO payments_transactions
    (uuid, id_notary, id_client, id_procedure, reference, `date`, responsible, fee, taxes, total, comments, id_status)
VALUES
    (:uuid, :notaryId, :clientId, :procedureId, :reference, :date, :responsible, :fee, :taxes, :total, :comments, 1);
```

```sql
-- Details: one multi-row insert (placeholders generated in a loop)
INSERT INTO payments_transaction_details (id_transaction, id_tax, quantity, amount, `date`, comments)
VALUES (:tx0, :tax0, :qty0, :amount0, :date0, :comments0),
       (:tx1, :tax1, :qty1, :amount1, :date1, :comments1);
```

```sql
-- PUT
DELETE FROM payments_transaction_details WHERE id_transaction = :transactionId;

UPDATE payments_transactions
SET id_procedure = :procedureId, `date` = :date, fee = :fee, taxes = :taxes, total = :total,
    reference = :reference, responsible = :responsible, comments = :comments
WHERE id_transaction = :id AND id_notary = :notaryId;
```

Recordatorios:

- `date` es palabra reservada: siempre con backticks.
- `Connection` usa prepares nativos: **no repitas un placeholder con nombre** en la misma sentencia (por eso `:tx0`, `:tx1`… aunque el valor sea el mismo).
- Fechas: guarda `"$date 00:00:00"` y expón `substr($row['date'], 0, 10)`.
- No uses `rowCount()` del UPDATE para detectar el 404: devuelve 0 cuando los valores no cambian. El 404 lo decide el `SELECT ... FOR UPDATE`.
- Los floats se bindean como string en PDO; es correcto.

### Migraciones Necesarias

#### Antes de aplicar en producción: tarea 0.1 y pre-checks

La v1 **sigue activa** sobre estas tablas. Antes de aplicar la segunda migración en producción necesitas la respuesta de la tarea 0.1 del plan (cómo inserta la v1). Y siempre corre estos pre-checks:

```sql
-- A. Duplicate uuid per notary (local: 1 row → NdARZM, ids 16,17)
SELECT id_notary, uuid, COUNT(*) AS c, GROUP_CONCAT(id_transaction ORDER BY id_transaction) AS ids
FROM payments_transactions
GROUP BY id_notary, uuid
HAVING c > 1;

-- B. For every group from A: are the rows identical, and does any of them have details?
SELECT t.*, (SELECT COUNT(*) FROM payments_transaction_details d
             WHERE d.id_transaction = t.id_transaction) AS n_details
FROM payments_transactions t
WHERE (t.id_notary, t.uuid) IN (
    SELECT id_notary, uuid FROM payments_transactions GROUP BY id_notary, uuid HAVING COUNT(*) > 1
)
ORDER BY t.id_notary, t.uuid, t.id_transaction;

-- C. Empty uuid: more than one per notary would collide with the UNIQUE index (local: 0)
SELECT id_notary, COUNT(*) AS c FROM payments_transactions WHERE uuid = '' GROUP BY id_notary;

-- D. Duplicate (id_transaction, id_tax) pairs: would break the new PK (local: 0)
SELECT id_transaction, id_tax, COUNT(*) AS c
FROM payments_transaction_details
GROUP BY id_transaction, id_tax
HAVING c > 1;

-- E. Orphan details (local: 0)
SELECT COUNT(*) FROM payments_transaction_details d
LEFT JOIN payments_transactions t ON t.id_transaction = d.id_transaction
WHERE t.id_transaction IS NULL;
```

**Detente y avisa** (no resuelvas por tu cuenta) si en producción: B muestra duplicados que **no** son idénticos o cuya fila de id mayor tiene detalles; C devuelve algún grupo con `c > 1`; o D devuelve filas. En local, el único duplicado (16/17) son filas idénticas sin detalles, así que la limpieza es segura.

#### `.db/pending/20261111_100000_dedupe_payments_transactions_uuid.sql`

```sql
-- Migration: dedupe_payments_transactions_uuid
-- Description: Removes duplicated transactions (same id_notary + uuid), keeping the lowest
--              id_transaction, so UNIQUE (id_notary, uuid) can be created afterwards.
--              Details of the removed transactions are deleted first to avoid orphans.
-- Author: Roberto Trejo
-- Date: 2026-11-11

-- PRE-CHECK (mandatory in production, see specs/11_payment_transactions/arch/developer_notes.md):
--   Run pre-checks A and B. Apply this migration ONLY if every duplicated group has identical
--   rows and the rows to be removed have no details. Back up both tables first.

-- UP
DELETE d FROM payments_transaction_details d
INNER JOIN payments_transactions dup  ON dup.id_transaction = d.id_transaction
INNER JOIN payments_transactions keep ON keep.id_notary = dup.id_notary
                                     AND keep.uuid = dup.uuid
                                     AND keep.id_transaction < dup.id_transaction;

DELETE dup FROM payments_transactions dup
INNER JOIN payments_transactions keep ON keep.id_notary = dup.id_notary
                                     AND keep.uuid = dup.uuid
                                     AND keep.id_transaction < dup.id_transaction;

-- DOWN (rollback)
-- Not reversible by SQL: restore the removed rows from the backup taken before applying.
```

#### `.db/pending/20261111_100100_payments_transactions_innodb_and_indexes.sql`

```sql
-- Migration: payments_transactions_innodb_and_indexes
-- Description: Moves payments_transactions and payments_transaction_details to InnoDB (atomic
--              header + details writes) and adds the natural keys:
--              UNIQUE (id_notary, uuid) for idempotent POST, PK (id_transaction, id_tax) for
--              details, and (id_notary, id_client) for per-client lookups.
-- Author: Roberto Trejo
-- Date: 2026-11-11

-- PRE-CHECK (must all return 0 rows in production before applying): pre-checks A, C (c > 1) and D.
-- The legacy v1 app still writes to these tables: confirm task 0.1 of the implementation plan first.

-- UP
ALTER TABLE payments_transactions ENGINE=InnoDB;
ALTER TABLE payments_transaction_details ENGINE=InnoDB;

ALTER TABLE payments_transactions
    ADD UNIQUE INDEX uq_payments_transactions_notary_uuid (id_notary, uuid),
    ADD INDEX idx_payments_transactions_notary_client (id_notary, id_client);

ALTER TABLE payments_transaction_details
    ADD PRIMARY KEY (id_transaction, id_tax);

-- DOWN (rollback)
-- ALTER TABLE payments_transaction_details DROP PRIMARY KEY;
-- ALTER TABLE payments_transactions
--     DROP INDEX idx_payments_transactions_notary_client,
--     DROP INDEX uq_payments_transactions_notary_uuid;
-- ALTER TABLE payments_transaction_details ENGINE=MyISAM;
-- ALTER TABLE payments_transactions ENGINE=MyISAM;
```

## Cómo probar la atomicidad en local (escenario 18)

1. Crea una transacción en modo calculado con el endpoint y anota el conteo de `payments_transactions`.
2. Renombra temporalmente la tabla de detalles: `RENAME TABLE payments_transaction_details TO payments_transaction_details_tmp;`
3. Haz un POST con detalles y un uuid nuevo → debe responder 500.
4. Verifica que el conteo de `payments_transactions` **no** cambió y que ese uuid no existe.
5. Restaura: `RENAME TABLE payments_transaction_details_tmp TO payments_transaction_details;`

Si el paso 4 muestra una fila nueva, la tabla sigue en MyISAM o el Repository no usa la misma conexión que el `TransactionManager`.

## Checklist Pre-PR
- [ ] Tarea 0.1 (revisión de la v1) respondida en la descripción del PR
- [ ] Pre-checks ejecutados en local; ambas migraciones aplicadas y movidas a `.db/applied/`
- [ ] Código sigue la arquitectura definida (el Service no conoce HTTP ni PDO; el Repository no abre transacciones)
- [ ] Tests escritos y pasando (`docker-compose exec api vendor/bin/phpunit`)
- [ ] Prueba de atomicidad en local realizada
- [ ] Sin warnings de PHP
- [ ] `docs/openapi.yaml` actualizado (modos de `taxes`, 200 vs 201, detalles ignorados, PUT de reemplazo completo)
- [ ] Revisé `technical_acceptance_criteria.md`
- [ ] PR a `develop` (QA cierra)

## Diferencias con el PO

| Tema | PO | Plan | ADR |
|------|----|------|-----|
| Cálculo de `taxes` | Siempre Σ detalles | Dos modos según el procedimiento; `taxes` es campo de entrada | 002 |
| Detalles en procedimiento sin impuestos | No contemplado | Se ignoran silenciosamente | 002 |
| Orden en POST | Cliente → procedimiento → uuid | uuid → cliente → procedimiento | 003 |
| Transacción en el Service | Inyectar `PDO` | `TransactionManager` | 001 |
| INSERT + UPDATE de totales | Dos sentencias | Totales calculados antes; un solo INSERT | — |
| Errores de validación | `{"error": "Fee cannot be negative"}` | `Validation failed` + `errors.fee` | 005 |
| PUT con cliente o impuesto inactivo | No especificado | Se conserva lo existente | 006 |
| `clientId` / `uuid` en PUT | Enviados siempre | Opcionales; si vienen deben coincidir | 007 |
| Índice de cliente | `idx_client (id_client)` | `(id_notary, id_client)` | 008 |
| Limpieza de duplicados | Solo borra cabeceras | Borra también los detalles de las eliminadas | — |
| Límites | Sin límites | 50 detalles, cantidad ≤ 9999, montos ≤ 999,999,999.99 | — |

## Preguntas Frecuentes

**P: ¿Cómo sé si un procedimiento "tiene impuestos"?**
R: `findActiveByProcedure($procedureId, $notaryId) !== []`. Cuenta solo asignaciones activas de impuestos activos. Si todas las asignaciones están desactivadas, el procedimiento está en modo manual.

**P: En modo calculado, ¿rechazo un detalle cuyo impuesto no está configurado en el procedimiento?**
R: No. Se acepta cualquier impuesto activo de la notaría. La configuración del procedimiento solo decide el modo.

**P: En modo calculado, ¿qué hago con el `taxes` que mande el cliente?**
R: Ignorarlo sin error. El frontend puede reenviar el objeto del GET completo.

**P: En modo manual, ¿qué hago si llegan detalles?**
R: Ignorarlos sin error. El frontend normalmente los envía vacíos o ausentes, pero si llegan se descartan silenciosamente.

**P: Un PUT en modo manual llega sin `taxes`. ¿Conservo el valor anterior?**
R: No. PUT es reemplazo completo: `taxes` omitido = 0, igual que `fee`. El frontend debe reenviar lo que recibió del GET.

**P: Una transacción en modo manual tiene `taxes = 1430`. Después le configuran impuestos al procedimiento y alguien la edita. ¿Qué pasa?**
R: En ese PUT ya aplica el modo calculado: `taxes` pasa a ser la suma de los detalles que se envíen (0 si no hay). Es la regla de negocio acordada. Mientras nadie la edite, no cambia.

**P: ¿El POST con uuid existente compara el payload?**
R: No. Devuelve la transacción guardada tal cual, con 200. Para modificar se usa PUT.

**P: ¿Por qué capturar el 1062 si ya revisé el uuid antes de insertar?**
R: Por la carrera: dos requests simultáneos pasan la revisión y solo uno inserta. Sin la captura, el segundo respondería 500 en lugar de 200.

**P: ¿Puedo devolver la respuesta armándola en memoria en vez de releer?**
R: No. Relee con `getById()`: así los nombres, el redondeo y los detalles que realmente quedaron provienen de la BD.

**P: ¿Valido que el impuesto de un detalle exista antes de ignorarlo, o respondo 404?**
R: Se ignora en silencio (escenarios 8 y 9 del PO). Nunca 404 ni 422 por un impuesto de detalle.

**P: ¿Filtro por `id_status = 1` al leer transacciones?**
R: No. La anulación está fuera de alcance y hoy todas las filas tienen status 1. Se crean con status 1 y el PUT no lo toca.

**P: ¿Por qué `LEFT JOIN` para los nombres?**
R: Para que una transacción legacy con cliente, procedimiento o impuesto inexistente siga siendo consultable (con nombre `''`) en lugar de responder 404.

**P: ¿Agrego `GET /payment-transactions` como listado?**
R: No. Fuera de alcance. Sin `?uuid=` responde 400.

**P: ¿Necesito bloquear algo en el POST?**
R: No. El UNIQUE resuelve la concurrencia. Solo el PUT bloquea la cabecera con `FOR UPDATE`.

## Contacto
Para dudas de negocio: revisar `specs/11_payment_transactions/po/`
Para dudas de arquitectura: `specs/11_payment_transactions/arch/implementation_plan.md` (ADRs) o consultar con el arquitecto
