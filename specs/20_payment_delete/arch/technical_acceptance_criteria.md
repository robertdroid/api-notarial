# Criterios de Aceptación Técnicos: Eliminar Pago (Abono)

## Controller Layer (`PaymentController`)
- [ ] Acción `destroy(Request $request): Response` implementada
- [ ] Reutiliza `parseId()`: no entero (`abc`, `1.5`) → `400` `{ "error": "Invalid ID format" }`; `id <= 0` → `404` `{ "error": "Payment not found" }`
- [ ] `notaryId` se obtiene solo de `$request->getNotaryId()` (nunca del request)
- [ ] Captura `PaymentException` y delega en `errorResponse()` (404)
- [ ] Éxito: `Response::noContent()` (`204`, sin cuerpo); nunca `200` ni el abono eliminado
- [ ] Ruta `DELETE /api/v3/payments/{id}` en `src/Config/routes.php` con `->withMiddleware('auth')` (sin JWT → `401`)
- [ ] La ruta no colisiona con `GET /payments/{id}/receipt` ni `GET /payments/uuid/{uuid}/receipt`

## Service Layer (`PaymentService`)
- [ ] `delete(int $id, int $notaryId): void`
- [ ] Si `repository->delete()` retorna `false` → `throw new PaymentException(PaymentError::PaymentNotFound)`
- [ ] No valida estado del cliente, rol ni antigüedad del abono (escenario 8; criterios de rechazo)
- [ ] No abre transacción
- [ ] No consulta `findByIdAndNotary` previamente (ADR-001)

## Repository Layer (`PaymentRepository`)
- [ ] `delete(int $id, int $notaryId): bool`
- [ ] SQL: `DELETE FROM payments_payments WHERE id_payment = :id AND id_notary = :notaryId` (prepared statement, parámetros nombrados)
- [ ] El `WHERE` incluye **siempre** `id_notary` (criterio de rechazo si se omite)
- [ ] Retorna `$stmt->rowCount() > 0`
- [ ] Docblock aclara que, a diferencia de `update`, aquí 0 filas = no existe / otra notaría
- [ ] No abre transacciones (una sola sentencia)
- [ ] Usa la PK `id_payment`; sin escaneo de tabla

## Entity/DTO
- [ ] Sin Entity ni DTO nuevos (la respuesta `204` no tiene cuerpo)
- [ ] Sin cambios en `Payment`, `PaymentResponse`, `PaymentError`

## Tests

### Unit (`tests/Module/Payment/Service/PaymentServiceTest.php`)
- [ ] `delete` éxito: repository mock retorna `true` → no lanza excepción
- [ ] `delete` pasa `id` y `notaryId` exactos al repository (`->with(ID, NOTARY)`, `->expects($this->once())`)
- [ ] `delete` no encontrado / otra notaría: repository mock retorna `false` → lanza `PaymentException` con `error === PaymentError::PaymentNotFound`
- [ ] `delete` no consulta `PaymentClientRepository` (`clientRepository->expects($this->never())->method('findByIdAndNotary')`) → garantiza que no hay restricción por estado del cliente
- [ ] `delete` no llama a `findByIdAndNotary` del repository (ADR-001)

### Fuera de alcance automático (ADR-002)
- Tests de Controller: no viables (ServiceLocator en constructor + Service `final`)
- Tests de integración con BD: el proyecto no tiene infraestructura; se cubren en verificación QA

## Verificación (QA, HTTP contra Docker)
Mapeo a escenarios del PO:
- [ ] E1: `DELETE /payments/{id}` → `204` sin cuerpo; la fila ya no existe
- [ ] E2: `GET /payments/{id}` y `GET /payments?uuid=...` → `404 Payment not found`
- [ ] E3: `GET /payment-clients/{id}/statement` excluye el abono y recalcula total/saldo
- [ ] E4: ambos `GET .../receipt` → `404`
- [ ] E5: id inexistente → `404 Payment not found`
- [ ] E6: JWT de otra notaría → `404` y el abono **sigue existiendo** (confirmar en BD)
- [ ] E7: segundo `DELETE` del mismo id → `404`
- [ ] E8: cliente inactivo → `204`
- [ ] E9: sin JWT / JWT inválido → `401`
- [ ] Validaciones: `abc` y `1.5` → `400 Invalid ID format`; `0` y `-1` → `404 Payment not found`
- [ ] Edge: abono con `payment_date` NULL se elimina; último abono del cliente → estado de cuenta responde con total 0; `POST` con uuid borrado → `201` con nuevo `id`
- [ ] `payments_clients` y `payments_transactions` sin modificaciones

## Código
- [ ] PSR-12
- [ ] `declare(strict_types=1)` y tipos de retorno explícitos
- [ ] SOLID: Controller solo HTTP, Service solo regla de negocio, Repository solo SQL
- [ ] Sin clases nuevas ni cambios en `bootstrap.php`

## Performance
- [ ] `DELETE` por clave primaria (`id_payment`) más `id_notary`; una sola sentencia
- [ ] Sin N+1; paginación no aplica

## Seguridad
- [ ] JWT requerido (middleware `auth`)
- [ ] Multi-tenant: `id_notary` en el `WHERE`; 404 (no 403) para abonos de otra notaría
- [ ] Parámetros enlazados (prepared statement); `id` validado como entero
- [ ] Sin migraciones, columnas de status ni tablas de auditoría (criterio de rechazo)

## Documentación
- [ ] `docs/openapi.yaml`: `delete` en `/payments/{id}` con `operationId: deletePayment`, respuestas `204`, `400`, `401`, `404`; nota de borrado físico irreversible y uuid reutilizable
