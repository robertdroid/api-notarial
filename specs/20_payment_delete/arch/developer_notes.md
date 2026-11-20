# Developer Notes: Eliminar Pago (Abono)

## Contexto rápido

Un `DELETE /api/v3/payments/{id}` con borrado **físico** (igual que la legacy). Una sola sentencia SQL filtrada por `id_payment` **y** `id_notary`. Sin migraciones, sin clases nuevas, sin cambios en `bootstrap.php`. Todo el cambio vive en el módulo `Payment` más la ruta y OpenAPI.

**Decisiones cerradas:**
- Borrado físico, irreversible, sin auditoría ni tombstone (riesgo aceptado por el PO).
- Cualquier usuario autenticado de la notaría; sin restricción por rol, antigüedad ni estado del cliente.
- `rowCount()` decide el 404 (ADR-001): **no** hagas `findByIdAndNotary` antes.
- Sin tests de Controller ni de integración (ADR-002): el proyecto no tiene infraestructura para ello.

**Aviso:** el `technical_context.md` del PO pide tests de Controller e integración con BD. No son viables hoy: `PaymentController` toma el service del `ServiceLocator` en el constructor y `PaymentService` es `final`. No refactorices para lograrlo; se verifica en QA.

**Empieza por** `PaymentRepository::delete` y `PaymentService::delete` con su test (Fase 1 y 3): es lógica pura, se prueba sin BD.

## Archivos clave (existentes)

| Archivo | Para qué |
|---------|----------|
| `src/Module/Payment/Controller/PaymentController.php` | Agrega `destroy`. Reutiliza `parseId()` (400 `Invalid ID format`, 404 si `<= 0`) y `errorResponse()` |
| `src/Module/Payment/Service/PaymentService.php` | `final`. Agrega `delete`. Patrón de `throw new PaymentException(PaymentError::PaymentNotFound)` en `getById` |
| `src/Module/Payment/Repository/PaymentRepository.php` | `class` (no final, mockeable). Agrega `delete`. Docblock de clase: "Never opens transactions" |
| `src/Module/Payment/Exception/PaymentError.php` | `PaymentNotFound` ya existe (mensaje `Payment not found`, 404). No se modifica |
| `src/Module/PaymentProcedureTax/Controller/PaymentProcedureTaxController.php` | Referencia del `destroy` que responde `Response::noContent()` (línea ~109) |
| `src/Core/Http/Response.php` | `Response::noContent()` → `204` sin cuerpo |
| `src/Config/routes.php` | Router ya soporta `$router->delete(...)`; agregar junto a las rutas de Payments (~línea 103) |
| `tests/Module/Payment/Service/PaymentServiceTest.php` | Patrón de tests: `createMock(PaymentRepository::class)`, constantes `NOTARY = 1`, `ID = 19` |
| `docs/openapi.yaml` | `/payments/{id}` en línea ~2280 (`get` y `put`); agregar `delete` tras `put` (~2408) |

## Tablas

| Tabla | Uso | Notas |
|-------|-----|-------|
| `payments_payments` | `DELETE` por `id_payment` + `id_notary` | Sin columna de status. Ninguna tabla la referencia por FK. La migración InnoDB de spec 17 ya está en `.db/applied/`; el `DELETE` funciona igual en MyISAM e InnoDB |

No se tocan `payments_clients` ni `payments_transactions`.

## Código de referencia

### Repository (`PaymentRepository.php`, después de `update`)
```php
/**
 * Physical delete. Unlike update(), rowCount() is reliable here:
 * 0 rows means the payment does not exist or belongs to another notary.
 */
public function delete(int $id, int $notaryId): bool
{
    $stmt = $this->db->prepare(
        'DELETE FROM payments_payments WHERE id_payment = :id AND id_notary = :notaryId'
    );
    $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

    return $stmt->rowCount() > 0;
}
```

### Service (`PaymentService.php`, después de `update`)
```php
public function delete(int $id, int $notaryId): void
{
    // No active-client check on purpose: a payment can be deleted whatever the client status (scenario 8)
    if (!$this->repository->delete($id, $notaryId)) {
        throw new PaymentException(PaymentError::PaymentNotFound);
    }
}
```

### Controller (`PaymentController.php`, después de `update`)
```php
public function destroy(Request $request): Response
{
    $id = $this->parseId($request);
    if ($id instanceof Response) {
        return $id;
    }

    try {
        $this->service->delete($id, $request->getNotaryId());
    } catch (PaymentException $e) {
        return $this->errorResponse($e->error);
    }

    return Response::noContent();
}
```

### Ruta (`src/Config/routes.php`)
```php
$router->delete('/api/v3/payments/{id}', [PaymentController::class, 'destroy'])->withMiddleware('auth');
```

### Test (`PaymentServiceTest.php`)
```php
public function testDeleteRemovesPayment(): void
{
    $this->repository->expects($this->once())
        ->method('delete')->with(self::ID, self::NOTARY)->willReturn(true);
    $this->repository->expects($this->never())->method('findByIdAndNotary');
    $this->clientRepository->expects($this->never())->method('findByIdAndNotary');

    $this->service->delete(self::ID, self::NOTARY);
}

public function testDeleteThrowsNotFoundWhenNothingDeleted(): void
{
    $this->repository->method('delete')->willReturn(false);

    try {
        $this->service->delete(self::ID, self::NOTARY);
        $this->fail('Expected PaymentException');
    } catch (PaymentException $e) {
        $this->assertSame(PaymentError::PaymentNotFound, $e->error);
    }
}
```

### OpenAPI (`docs/openapi.yaml`, bajo `/payments/{id}`, tras `put`)
```yaml
    delete:
      tags:
        - Payments
      summary: Delete a payment
      description: |
        Permanently deletes a payment (physical delete, irreversible, no audit trail).

        - The client statement and receipts stop including it: `GET /payments/{id}` and
          `GET /payments/{id}/receipt` return 404 afterwards.
        - The `uuid` becomes free: a later `POST /payments` with the same uuid creates a new payment.
        - The client is **not** required to be active.

        Returns 404 if the payment doesn't exist or belongs to a different notary.
      operationId: deletePayment
      security:
        - bearerAuth: []
      parameters:
        - name: id
          in: path
          required: true
          description: Payment ID
          schema:
            type: integer
      responses:
        '204':
          description: Payment deleted (no body)
        '400':
          description: Invalid ID format
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
              example:
                error: "Invalid ID format"
        '401':
          description: Unauthorized
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
        '404':
          description: Payment not found (or `id` <= 0)
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'
              example:
                error: "Payment not found"
```

## Comandos

```bash
docker-compose exec api vendor/bin/phpunit tests/Module/Payment/Service/PaymentServiceTest.php
docker-compose exec api vendor/bin/phpunit
```

## Pitfalls

- **No omitir `id_notary` en el `WHERE`**: es criterio de rechazo y la única barrera multi-tenant.
- **No usar `rowCount()` como en `update`**: allí 0 filas es válido; aquí significa 404.
- **No devolver cuerpo**: `Response::noContent()`, no `Response::json(...)`.
- **No agregar validación de cliente activo**: el escenario 8 exige `204` con cliente inactivo.
- **Verifica en QA con un JWT de otra notaría** que el abono sigue existiendo (E6): los tests unitarios con mocks no lo prueban.
