# Contexto Técnico

## Entidades Involucradas

### Tabla: `payments_payments` (sin cambios de esquema)
```sql
CREATE TABLE `payments_payments` (
  `id_payment` int(11) NOT NULL AUTO_INCREMENT,
  `id_client` int(11) NOT NULL,
  `id_notary` int(11) NOT NULL,
  `uuid` varchar(20) NOT NULL DEFAULT '',
  `amount` double NOT NULL DEFAULT 0,
  `payment_date` datetime DEFAULT NULL,
  `comments` text DEFAULT NULL,
  PRIMARY KEY (`id_payment`)
)
```
Sin columna de status: el borrado es físico. No hay FKs hacia esta tabla; ninguna otra tabla referencia `id_payment`.

### Consumidores de lectura de `payments_payments` (no requieren cambios)
- `PaymentStatement\Repository\StatementRepository` (spec 19): lista de abonos y `SUM(amount)`; se recalcula en cada consulta
- `Payment\Repository\PaymentRepository` (spec 17): `findByIdAndNotary`, `findByUuidAndNotary`
- Recibos PDF (spec 18): leen el abono vía `PaymentRepository`; un abono borrado produce `404`

## Endpoints Sugeridos

| Método | Ruta | Descripción |
|--------|------|-------------|
| DELETE | `/api/v3/payments/{id}` | Eliminar físicamente un abono |

Registrar en `src/Config/routes.php` junto a las rutas de `payments` (líneas ~99-103) con `->withMiddleware('auth')`. No colisiona con `/payments/{id}/receipt` (GET) ni `/payments/uuid/{uuid}/receipt` (GET).

### Request
```
DELETE /api/v3/payments/19
Authorization: Bearer <jwt>
```
Sin cuerpo.

### Responses
```
204 No Content        (sin cuerpo)

// 400 Bad Request
{ "error": "Invalid ID format" }

// 401 Unauthorized   (middleware auth)

// 404 Not Found
{ "error": "Payment not found" }
```

## Dependencias

### Módulos requeridos (existentes)
- **Auth** (spec 01): JWT con `notaryId`
- **Payment** (spec 17): `PaymentController`, `PaymentService`, `PaymentRepository`, `PaymentError::PaymentNotFound`

### Patrón de referencia
- `PaymentProcedureTaxController::destroy` / `PaymentProcedureTaxService::remove` (spec 10): `DELETE` que responde `Response::noContent()`

## Impacto en Sistema Existente

### Archivos a modificar
- `src/Config/routes.php`: ruta `DELETE /api/v3/payments/{id}`
- `src/Module/Payment/Controller/PaymentController.php`: acción `destroy` (reutiliza `parseId` y `errorResponse`)
- `src/Module/Payment/Service/PaymentService.php`: método `delete(int $id, int $notaryId): void`
- `src/Module/Payment/Repository/PaymentRepository.php`: método `delete(int $id, int $notaryId): bool` (o `int` filas afectadas)
- `docs/openapi.yaml`: documentar el endpoint

### Sin cambios en
- `PaymentStatement`, `Receipt`, `PaymentClient`, `PaymentTransaction`
- Esquema de BD

## Consideraciones de Datos

- **Sin migración nueva.** La migración `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql` (spec 17) sigue en `pending`; no bloquea el `DELETE`, que funciona igual en MyISAM e InnoDB.
- **Borrado irreversible**: no hay respaldo lógico. Riesgo aceptado por el negocio.
- **Uuid reutilizable**: tras el borrado el índice único `(id_notary, uuid)` queda libre, así que un `POST` posterior con el mismo uuid crea un abono nuevo (ver Casos Edge en criterios).

## Notas para Implementación

1. **Orden sugerido del Service**:
   - Ejecutar `DELETE FROM payments_payments WHERE id_payment = :id AND id_notary = :notaryId`
   - Si `rowCount() === 0` → lanzar `PaymentException(PaymentError::PaymentNotFound)` (404)
   - Alternativa: `findByIdAndNotary` antes y luego `DELETE`; Arch decide. El `rowCount()` evita una consulta y cubre la carrera de borrados simultáneos (el segundo recibe 404)
2. **Aquí sí se usa `rowCount()`** (a diferencia de `update`, donde 0 filas es válido): en un `DELETE` 0 filas significa que no existía o es de otra notaría
3. **Multi-tenant**: el `WHERE` incluye siempre `id_notary`; `notaryId` viene de `$request->getNotaryId()`
4. **Una sola sentencia**: sin transacción (docblock de `PaymentRepository`: "Never opens transactions")
5. **Respuesta**: `Response::noContent()`; no devolver el abono eliminado
6. **Tests**: unitarios de Service (éxito, 404) y de Controller (204, 400 `Invalid ID format`, 404 con id <= 0); integración con BD para aislamiento por notaría y borrado doble

## Frontend (fuera de alcance, para referencia)

`notarial-v3` ya tiene la acción `delete` en `PAYMENT_ACTIONS` (`MovementRowActions.jsx`), deshabilitada porque `PaymentClientMovementsPage.jsx` no le pasa `handlers`. Se habilitará en una spec propia en ese repo, con confirmación previa y recarga del estado de cuenta tras el `204`.

## Estimación

- **Complejidad**: Baja (una sentencia, patrón ya resuelto en spec 10)
- **Riesgo**: Bajo en código; el riesgo de negocio (borrado irreversible, sin rastro) está aceptado

| Tarea | Estimación |
|-------|------------|
| Repository + Service | 45 min |
| Controller + ruta | 30 min |
| Tests unitarios + integración | 1.5 horas |
| OpenAPI docs | 20 min |
| **Total** | **~3 horas** |
