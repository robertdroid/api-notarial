# Developer Notes: Estado de Cuenta de Cliente

## Contexto rápido

Feature **solo lectura**: 1 `GET` que devuelve JSON con los cargos (trámites activos), abonos (cobros) y saldo de un cliente, opcionalmente acotado por `from`/`to`. Sin migraciones, sin dependencias nuevas. Módulo nuevo `src/Module/PaymentStatement/`.

**Decisiones del equipo (ya cerradas):**
- Defaults del PO aprobados: cobro sin fecha solo aparece/suma sin filtros de fecha; sin paginación; cualquier usuario autenticado de la notaría; orden cronológico ascendente.
- **No hay migración de índices.** Usa `(id_notary, id_client)` existentes.
- Filtro de rango **en SQL**.
- **Trámites cancelados (`id_status = 2`) fuera de todo**; cliente **inactivo sí se consulta**.

**Aviso:** el `technical_context.md` del PO dice que ya existen listados con `LEFT JOIN` en `PaymentTransactionRepository` y `PaymentRepository`. **No existen.** Escribe las consultas nuevas en `StatementRepository`; no toques los repositories existentes.

**Empieza por** `StatementQuery` (Fase 1): es lógica pura, se prueba sin BD y de ella dependen los límites de las consultas.

## Archivos clave (existentes)

| Archivo | Para qué |
|---------|----------|
| `src/Module/PaymentClient/Controller/PaymentClientController.php` | Copia `parseId()` (400 `Invalid ID format`, 404 `Client not found` si `<= 0`) y la constante `NOT_FOUND` |
| `src/Module/PaymentClient/Repository/PaymentClientRepository.php` | `findByIdAndNotary(int $id, int $notaryId): ?PaymentClient`. **No** filtra por status. Es `class` (no final): mockeable |
| `src/Module/PaymentClient/Service/PaymentClientService.php` | `final`: **no la inyectes** (no se puede mockear); usa el repository (ADR-008) |
| `src/Module/Payment/DTO/PaymentFieldRules.php` | `date(mixed): bool` ya hace el round-trip `!Y-m-d`. **Reutilízalo** |
| `src/Module/Payment/DTO/CreatePaymentRequest.php` | Patrón del DTO: constructor privado, `fromArray()`, `isValid()`, `getErrors()` |
| `src/Module/Payment/DTO/PaymentResponse.php` | Patrón de `Response` DTO: método estático que devuelve `array` |
| `src/Module/Payment/Entity/Payment.php` | Patrón de `fromRow()`: `substr(date, 0, 10)`, fecha nullable |
| `src/Module/PaymentTransaction/Repository/PaymentTransactionRepository.php` | Referencia del `LEFT JOIN` a `payment_procedures` (`HEADER_SQL`) con `id_notary` en el `ON` |
| `src/Module/Payment/Service/PaymentService.php` / `tests/Module/Payment/Service/PaymentServiceTest.php` | Patrón de Service con `PaymentClientRepository` y de pruebas con mocks |
| `src/Core/Http/Response.php` | `json()`, `badRequest($msg, $errors)`, `notFound($msg)` |
| `src/Core/Http/Request.php` | `getQueryParam('from')` (puede devolver `string`, `array` o `null`), `getRouteParam('id')`, `getNotaryId()` |
| `src/Config/routes.php` | Agregar 1 ruta junto a *Payment Clients* |
| `src/bootstrap.php` | Registrar `StatementRepository` y `PaymentStatementService` (después de `PaymentClientRepository`) |

## Tablas (solo lectura)

| Tabla | Columnas usadas | Notas |
|-------|-----------------|-------|
| `payments_clients` | `id_client`, `id_notary`, `full_name`, `id_status` | Vía `PaymentClientRepository`. No se filtra por status |
| `payments_transactions` | `id_transaction`, `uuid`, `id_notary`, `id_client`, `id_procedure`, `reference`, `date` (datetime), `fee`, `taxes`, `total`, `id_status` | Solo `id_status = 1`. Índice `(id_notary, id_client)` |
| `payments_payments` | `id_payment`, `uuid`, `id_notary`, `id_client`, `amount`, `payment_date` (datetime **nullable**), `comments` | Sin status. Índice `(id_notary, id_client)` **pendiente de aplicar** (spec 17) |
| `payment_procedures` | `id_procedure`, `id_notary`, `procedure_name` | `LEFT JOIN`; si no hay fila, `procedureName = ''` |

## Código de referencia

### Ruta (`src/Config/routes.php`)
```php
use App\Module\PaymentStatement\Controller\PaymentStatementController;

// Payment client statement (protected). Two segments after the resource: no collision with /payment-clients/{id}
$router->get('/api/v3/payment-clients/{id}/statement', [PaymentStatementController::class, 'show'])->withMiddleware('auth');
```

### Registro (`src/bootstrap.php`)
```php
ServiceLocator::register(StatementRepository::class, function () {
    return new StatementRepository(Connection::getInstance());
});

ServiceLocator::register(PaymentStatementService::class, function () {
    return new PaymentStatementService(
        ServiceLocator::get(PaymentClientRepository::class),
        ServiceLocator::get(StatementRepository::class)
    );
});
```

### Controller (esqueleto)
```php
public function show(Request $request): Response
{
    $id = $this->parseId($request);
    if ($id instanceof Response) {
        return $id;
    }

    $query = StatementQuery::fromArray($request->getQueryParams());
    if (!$query->isValid()) {
        return Response::badRequest('Validation failed', $query->getErrors());
    }

    $statement = $this->service->build($id, $request->getNotaryId(), $query);

    return $statement === null
        ? Response::notFound(self::NOT_FOUND)
        : Response::json(StatementResponse::fromEntity($statement));
}
```

### `StatementQuery` (lógica clave)
```php
private static function parseDate(mixed $value, string $label, string $field, array &$errors): ?string
{
    if ($value === null || $value === '') {
        return null;                       // empty = not sent
    }
    if (!PaymentFieldRules::date($value)) {   // rejects arrays, 2026-02-31, 2026-3-1
        $errors[$field] = "$label must be a valid date in YYYY-MM-DD format";
        return null;
    }
    return $value;
}

public function fromBoundary(): ?string
{
    return $this->from !== null ? $this->from . ' 00:00:00' : null;
}

public function toExclusiveBoundary(): ?string
{
    if ($this->to === null) {
        return null;
    }
    return (new \DateTimeImmutable($this->to . ' 00:00:00', new \DateTimeZone('America/Mexico_City')))
        ->modify('+1 day')
        ->format('Y-m-d H:i:s');
}
```
Cuidado: si `from` es inválido, `parseDate` devuelve `null` y **no** debe disparar la comparación de rango. Compara `to < from` solo cuando ambos son válidos y distintos de `null` (comparar strings `Y-m-d` es seguro).

### Repository: filtro de rango reutilizable
```php
/**
 * @param array<string, mixed> $params
 */
private function rangeClause(string $column, ?string $from, ?string $toExclusive, array &$params): string
{
    $sql = '';
    if ($from !== null) {
        $sql .= " AND $column >= :from";
        $params['from'] = $from;
    }
    if ($toExclusive !== null) {
        $sql .= " AND $column < :toExclusive";
        $params['toExclusive'] = $toExclusive;
    }
    return $sql;
}
```
`$column` es una constante interna (`t.date` o `payment_date`), nunca input del usuario. Las fechas siempre van como parámetro.

### Repository: cobros (con nulos al final)
```php
$sql = 'SELECT id_payment, uuid, payment_date, comments, amount
        FROM payments_payments
        WHERE id_notary = :notaryId AND id_client = :clientId'
    . $this->rangeClause('payment_date', $from, $toExclusive, $params)
    . ' ORDER BY (payment_date IS NULL), payment_date ASC, id_payment ASC';
```
Con `from` o `to`, el `NULL` queda fuera sin lógica extra (`NULL >= x` no es verdadero). Sin filtros no hay predicado de fecha, así el cobro sin fecha entra.

### Repository: saldo anterior
```php
$stmt = $this->db->prepare(
    'SELECT COALESCE(SUM(total), 0) FROM payments_transactions
     WHERE id_notary = :notaryId AND id_client = :clientId AND id_status = 1 AND `date` < :from'
);
$stmt->execute(['notaryId' => $notaryId, 'clientId' => $clientId, 'from' => $from]);

return (float) $stmt->fetchColumn();
```
`date` va entre backticks (palabra reservada, igual que en `PaymentTransactionRepository`).

### Service: centavos
```php
private static function toCents(float $amount): int
{
    return (int) round($amount * 100);
}

private static function fromCents(int $cents): float
{
    return round($cents / 100, 2);
}

$charges = array_sum(array_map(static fn (StatementTransaction $t): int => self::toCents($t->total), $transactions));
$payments = array_sum(array_map(static fn (StatementPayment $p): int => self::toCents($p->amount), $payments));
$pending = $from === null ? 0 : self::toCents($sumCharges) - self::toCents($sumPayments);
$balance = $pending + $charges - $payments;
```

### Respuesta (contrato v1, solo cambia la ruta)
```json
{
  "clientId": 5, "clientName": "María García López",
  "fromDate": "2026-03-01", "toDate": "2026-03-31",
  "transactions": [{ "transactionId": 25, "uuid": "Us2Gvq", "date": "2026-03-10",
                     "procedureName": "Trámite de Compraventa", "reference": "ESC-123",
                     "fee": 1000.0, "taxes": 160.0, "total": 1160.0 }],
  "payments": [{ "paymentId": 10, "uuid": "abc123xyz", "date": "2026-03-15",
                 "comment": "Anticipo", "amount": 500.0 }],
  "pendingAmount": 0.0, "totalCharges": 1160.0, "totalPayments": 500.0, "balance": 660.0
}
```

## Cosas a vigilar

1. **`from[]=x` / `to[]=x`**: `getQueryParam` devuelve un `array`. `PaymentFieldRules::date()` ya lo rechaza (`is_string`), pero no hagas `trim()` ni cast a `string` antes de validar.
2. **Nunca `BETWEEN` ni `DATE(col)`**: es justo el bug de v1 (excluye el último día) y `DATE(col)` anula el índice. Usa los límites `>= from 00:00:00` y `< (to + 1 día) 00:00:00`.
3. **`notary_id` en las 4 consultas**, incluidas las dos sumas de saldo anterior. Es el criterio de rechazo más grave del PO.
4. **`id_status = 1` en la lista y en la suma anterior.** Si solo lo pones en una, el saldo no cuadra con la lista (escenarios 7 y 8).
5. **No filtres por `id_status` del cliente.** Un cliente inactivo se consulta (escenario 9).
6. **Cliente 404 antes de movimientos**: no ejecutes consultas de movimientos si el cliente no existe en la notaría.
7. **`comment` vs `comments`**: la columna es `comments`; el campo de respuesta de `payments[]` es `comment` (contrato v1).
8. **Float**: nunca sumes `float` directo; usa centavos (ADR-005). Los valores de `JSON` salen como `1160.0` (así los serializa PHP; es esperado).
9. **`fromDate`/`toDate`** devuelven el valor recibido (`'2026-03-01'`) o `null`; no los reformatees.
10. **Palabra reservada `date`**: entre backticks en SQL (`` `date` ``), como en el repository de trámites.
11. **Sin escritura**: ni `INSERT` ni `UPDATE`, ni transacciones. Si pruebas con `curl` en Docker, no debe cambiar ninguna fila.
12. **Dependencia de despliegue**: el índice `(id_notary, id_client)` de `payments_payments` está en `.db/pending` (spec 17). Si no está aplicado, la consulta funciona pero con *full scan*; avisa a QA antes de medir rendimiento.
13. **`final`**: `PaymentClientService` es `final` y no se puede mockear. Inyecta `PaymentClientRepository` en el Service.

## Puntos para devolver al PO / QA (no bloquean el desarrollo)

1. **Listados "existentes" inexistentes**: ver aviso arriba. Alcance sin cambios.
2. **Pruebas de integración**: el PO pide los 21 escenarios; el proyecto no tiene infraestructura de BD en pruebas. Se cubren con unitarias + verificación manual de QA.
3. **Orden de cobros sin fecha** (no definido por el PO): quedan **al final** de `payments`. ¿Confirmar?
4. **Cobros sin fecha y filtros**: con `from`/`to` también se excluyen del `pendingAmount`; el balance filtrado puede diferir del no filtrado por ese monto. Consecuencia del default aprobado.
5. **Estimación**: ~12.5 h (ver plan), 1 h más que el PO por el repository propio y las entities de lectura.

## Comandos útiles

```bash
docker-compose up -d
docker-compose exec api composer test
docker-compose exec api vendor/bin/phpunit tests/Module/PaymentStatement

# Prueba manual (obtén el token con POST /api/v3/auth/login)
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/5/statement"
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/5/statement?from=2026-03-01&to=2026-03-31"
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/5/statement?from=2026-02-31"   # 400
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/abc/statement"                 # 400
```

EXPLAIN (phpMyAdmin en http://localhost:8081):
```sql
EXPLAIN SELECT t.id_transaction FROM payments_transactions t
WHERE t.id_notary = 1 AND t.id_client = 5 AND t.id_status = 1
  AND t.`date` >= '2026-03-01 00:00:00' AND t.`date` < '2026-04-01 00:00:00'
ORDER BY t.`date`, t.id_transaction;
-- key esperado: idx_payments_transactions_notary_client
```
