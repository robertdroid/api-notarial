# Criterios de Aceptación

## Funcionales

### Escenario 1: Eliminar un abono existente
**Dado** un abono con id 19 de la notaría 1 y un usuario autenticado de la notaría 1
**Cuando** envía `DELETE /api/v3/payments/19`
**Entonces** responde `204 No Content` sin cuerpo
**Y** la fila de `payments_payments` con `id_payment = 19` ya no existe

### Escenario 2: El abono eliminado deja de consultarse
**Dado** un abono eliminado (id 19, uuid `Us2Gvq`)
**Cuando** se consulta `GET /api/v3/payments/19` o `GET /api/v3/payments?uuid=Us2Gvq`
**Entonces** responde `404` con `{ "error": "Payment not found" }`

### Escenario 3: El estado de cuenta se recalcula
**Dado** un cliente con dos abonos (5,000.00 y 2,000.00) y cargos por 10,000.00
**Cuando** se elimina el abono de 2,000.00 y se consulta `GET /api/v3/payment-clients/{id}/statement`
**Entonces** la lista de abonos ya no incluye el eliminado
**Y** el total de abonos es 5,000.00 y el saldo refleja la diferencia

### Escenario 4: El recibo PDF del abono eliminado no existe
**Dado** un abono eliminado
**Cuando** se consulta `GET /api/v3/payments/{id}/receipt` o `GET /api/v3/payments/uuid/{uuid}/receipt`
**Entonces** responde `404`

### Escenario 5: Abono inexistente
**Dado** un id que no existe en `payments_payments`
**Cuando** se envía `DELETE /api/v3/payments/99999`
**Entonces** responde `404` con `{ "error": "Payment not found" }`

### Escenario 6: Abono de otra notaría (multi-tenant)
**Dado** un abono id 19 de la notaría 1 y un usuario autenticado de la notaría 3
**Cuando** envía `DELETE /api/v3/payments/19`
**Entonces** responde `404` con `{ "error": "Payment not found" }`
**Y** el abono sigue existiendo (no se revela que existe en otra notaría)

### Escenario 7: Eliminar dos veces
**Dado** un abono ya eliminado
**Cuando** se envía otra vez `DELETE /api/v3/payments/{id}`
**Entonces** responde `404` con `{ "error": "Payment not found" }` (no es idempotente a nivel de respuesta)

### Escenario 8: Cliente inactivo
**Dado** un abono cuyo cliente tiene `id_status = 2` (inactivo)
**Cuando** se envía `DELETE /api/v3/payments/{id}`
**Entonces** responde `204` y el abono se elimina (sin restricción por estado del cliente)

### Escenario 9: Sin autenticación
**Dado** una petición sin JWT o con JWT inválido
**Cuando** se envía `DELETE /api/v3/payments/{id}`
**Entonces** responde `401`

## No Funcionales
- Una sola sentencia `DELETE`; no requiere transacción explícita
- Multi-tenant: el `DELETE` filtra por `id_payment` **y** `id_notary`
- Timezone `America/Mexico_City` (sin impacto; no se manejan fechas)
- No se modifican `payments_clients` ni `payments_transactions`

## Validaciones
- `{id}` no entero (`abc`, `1.5`): `400` con `{ "error": "Invalid ID format" }` (mismo comportamiento que `GET`/`PUT`)
- `{id} <= 0`: `404` con `{ "error": "Payment not found" }`
- `notaryId` proviene siempre del JWT (`$request->getNotaryId()`), nunca del request

## Casos Edge
- **Reintento de POST tras el borrado**: un `POST /api/v3/payments` con el uuid del abono eliminado crea un abono nuevo (nuevo `id`, respuesta `201`). Riesgo aceptado y documentado
- **Abono legacy sin fecha** (`payment_date` NULL): se elimina igual
- **Eliminación concurrente**: dos `DELETE` simultáneos del mismo abono; uno recibe `204` y el otro `404`
- **Último abono del cliente**: el cliente queda con cero abonos; el estado de cuenta responde normalmente (total 0)

## Criterios de Rechazo
- El endpoint elimina un abono de otra notaría
- El endpoint responde `200` con cuerpo en lugar de `204`
- Se exige rol, status de cliente o antigüedad para eliminar (fuera de alcance)
- Se crea columna de status, tabla de auditoría o cualquier migración nueva
- El `DELETE` omite el filtro `id_notary`
