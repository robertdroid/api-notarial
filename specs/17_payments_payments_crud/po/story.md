# Payments (Pagos) CRUD

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** registrar, modificar y consultar los pagos (abonos) de un cliente
**Para** llevar el control de los anticipos y pagos recibidos, incluso trabajando sin conexión

## Contexto

Un **pago** representa un abono de dinero recibido de un cliente de pagos (`payments_clients`). Es una entidad **distinta** de las transacciones (`payments_transactions`, spec 11): la transacción es un cargo al cliente; el pago es dinero que el cliente entrega.

Cada pago tiene:
- Un **cliente** que lo realiza
- Un **monto** (`amount`)
- Una **fecha de pago** (`paymentDate`)
- **Comentarios** libres (ej. "R4329 INSTRUMENTO NO. 20961")
- Un **UUID** generado por el cliente (frontend) para soporte offline-first

### UUID
- Lo genera el frontend (`ShortUniqueId`, máx 20 caracteres)
- Es **inmutable**: una vez creado el pago no puede cambiar
- Sirve de **llave de idempotencia**: evita duplicados cuando el cliente reintenta la creación (doble clic, reconexión, sincronización offline)
- Único por notaría

### Relación con otros módulos
- **Clientes** (spec 09): el pago pertenece a un cliente, que debe existir y estar activo
- **Transacciones** (spec 11): mismo patrón de UUID / idempotencia / inmutabilidad

### Flujo de uso
1. Usuario selecciona un cliente
2. Registra un pago con monto, fecha y comentarios; el frontend genera el UUID
3. El sistema guarda el pago (o devuelve el existente si el UUID ya fue registrado)
4. Posteriormente el usuario puede corregir monto, fecha o comentarios
5. El pago se consulta por `id` o por `uuid`

## Alcance

### Incluido
- Migración de `payments_payments` a InnoDB con índice único `(id_notary, uuid)` e índice por `id_client`
- `POST` para crear un pago (idempotente por UUID)
- `GET` por `id`
- `GET` por `uuid`
- `PUT` para modificar `amount`, `paymentDate` y `comments`
- Validación de pertenencia a notaría (multi-tenant)
- Validación de cliente existente y activo
- Documentación OpenAPI

### Excluido
- Listado/búsqueda de pagos (incluyendo por cliente)
- Eliminación/anulación de pagos
- Saldo, estado de cuenta del cliente y reportes
- Aplicación de pagos contra transacciones
- Cambio de cliente en un pago existente

## Usuarios Afectados

- **Empleados de notaría**: registran y corrigen pagos diariamente
- **Administradores**: consultan y corrigen pagos
- **App frontend offline-first**: sincroniza pagos creados sin conexión

## Valor de Negocio

- **Offline-first**: el UUID permite crear pagos sin conexión y sincronizar después sin duplicar
- **Integridad**: un pago no puede reasignarse de cliente ni cambiar su identidad (UUID)
- **Seguridad**: aislamiento estricto por notaría
