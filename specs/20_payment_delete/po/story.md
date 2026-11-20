# Eliminar Pago (Abono)

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** eliminar un pago (abono) registrado por error
**Para** que el estado de cuenta del cliente refleje únicamente los abonos válidos

## Contexto

Un **pago** (`payments_payments`, spec 17) es un abono de dinero recibido de un cliente de pagos. Hoy la API v3 permite crearlo, consultarlo y modificarlo, pero no eliminarlo (spec 17 lo dejó fuera de alcance).

La app legacy (`notarial`) sí lo permite: `DELETE /api/v1/payment/payment/{id}` ejecuta un `DELETE FROM` físico, sin status ni auditoría. En la app nueva (`notarial-v3`), la pantalla de Movimientos muestra el botón "Eliminar" por cada abono, pero está deshabilitado ("Próximamente") porque no existe endpoint v3.

Esta feature agrega ese endpoint con el **mismo comportamiento que la legacy**: borrado **físico**.

### Decisiones de negocio (confirmadas con el usuario)
- **Borrado físico**: la fila se elimina definitivamente de `payments_payments`.
- **Permisos**: cualquier usuario autenticado de la notaría dueña del abono (sin distinción de roles).
- **Sin restricciones**: se puede eliminar aunque el cliente esté inactivo o el abono sea antiguo.
- **Sin rastro**: no se crea auditoría, tombstone ni tabla nueva.

### Efectos sobre otros módulos
- **Estado de cuenta** (spec 19): el saldo y la lista de abonos se calculan en cada consulta desde `payments_payments`, por lo que el abono desaparece y el saldo se recalcula solo.
- **Recibos PDF** (spec 18): `/payments/{id}/receipt` y `/payments/uuid/{uuid}/receipt` responden `404` para un abono eliminado.
- **Idempotencia por UUID** (spec 17): al eliminar, el uuid queda libre. Un `POST /payments` reintentado después del borrado (p. ej. sincronización offline tardía) volvería a crear el abono.

### Riesgos aceptados
1. **Sin rastro del borrado**: no se puede saber quién eliminó qué ni recuperar el abono.
2. **Re-creación por reintento**: un `POST` con el uuid ya eliminado crea un abono nuevo con otro `id`.

### Flujo de uso
1. El usuario abre Movimientos de un cliente y ve la lista de abonos
2. Pulsa "Eliminar" en un abono (el front pide confirmación; fuera de alcance de esta spec)
3. El front llama `DELETE /api/v3/payments/{id}`
4. La API borra el abono y responde `204`; el front recarga el estado de cuenta

## Alcance

### Incluido
- `DELETE /api/v3/payments/{id}` con borrado físico, filtrado por `notary_id` del JWT
- Respuestas `204`, `400`, `404`
- Documentación OpenAPI

### Excluido
- Botón/confirmación de Eliminar en `notarial-v3` (spec aparte en ese repo)
- Anulación lógica (columna de status) y auditoría
- Eliminación de cargos (`payment-transactions`)
- Eliminación masiva o por cliente
- Restricciones por rol, antigüedad o estado del cliente
- Migración de `payments_payments` a InnoDB (ya está en `.db/pending`, spec 17)

## Usuarios Afectados

- **Empleados de notaría**: corrigen abonos capturados por error
- **Administradores**: igual; no hay distinción de rol
- **App frontend (`notarial-v3`)**: habilitará el botón Eliminar en Movimientos

## Valor de Negocio

- **Paridad con legacy**: permite migrar la pantalla de Movimientos sin perder la capacidad de eliminar abonos
- **Integridad del saldo**: un abono erróneo deja de afectar el estado de cuenta
- **Seguridad**: aislamiento estricto por notaría; no se puede borrar el abono de otra notaría
