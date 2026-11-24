# Contexto Técnico

## Entidades Involucradas

### Tabla principal: `payments_payments`
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
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin  -- MIGRAR A InnoDB
```
Sin columna de status (no hay baja lógica; eliminación fuera de alcance).

### Tablas relacionadas (solo lectura, validación)
- `payments_clients` - validar existencia, status y notaría; obtener `clientName`

## Estado de los datos (BD local)
- 16 filas, 16 UUID distintos, 0 duplicados por `(id_notary, uuid)` → **no requiere dedupe**
- Verificar duplicados en producción antes de crear el índice único

## Migración de Base de Datos (Prerequisito)

Archivo en `.db/` siguiendo el patrón de migraciones existentes (ej. `20261111_100100_payments_transactions_innodb_and_indexes.sql`):

```sql
-- Verificación previa (debe retornar 0 filas)
SELECT id_notary, uuid, COUNT(*) AS cnt
FROM payments_payments
GROUP BY id_notary, uuid
HAVING cnt > 1;

ALTER TABLE payments_payments ENGINE=InnoDB;

ALTER TABLE payments_payments
  ADD UNIQUE INDEX idx_notary_uuid (id_notary, uuid),
  ADD INDEX idx_client (id_client);
```

## Endpoints Sugeridos

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v3/payments` | Crear pago (idempotente por uuid) |
| GET | `/api/v3/payments/{id}` | Obtener por ID |
| GET | `/api/v3/payments?uuid={uuid}` | Obtener por UUID |
| PUT | `/api/v3/payments/{id}` | Modificar monto, fecha, comentarios |

La ruta final la confirma Arch (verificar colisión con rutas existentes de `payment-*`).

### Request/Response Examples

#### POST /api/v3/payments
Request:
```json
{
  "clientId": 5,
  "uuid": "Us2Gvq",
  "amount": 5000.00,
  "paymentDate": "2024-04-02",
  "comments": "Adelanto de mensualidad"
}
```

Response 201 (200 si el uuid ya existía):
```json
{
  "paymentId": 19,
  "uuid": "Us2Gvq",
  "clientId": 5,
  "clientName": "Juan Pérez López",
  "amount": 5000.00,
  "paymentDate": "2024-04-02",
  "comments": "Adelanto de mensualidad"
}
```

#### GET /api/v3/payments/19  ·  GET /api/v3/payments?uuid=Us2Gvq
Response 200: mismo formato.

#### PUT /api/v3/payments/19
Request:
```json
{
  "clientId": 5,
  "uuid": "Us2Gvq",
  "amount": 5500.00,
  "paymentDate": "2024-04-03",
  "comments": "Adelanto corregido"
}
```
Response 200: pago actualizado (mismo formato).

### Error Responses
```json
// 400 Bad Request
{ "error": "Required fields: clientId, uuid, amount" }
{ "error": "Amount must be greater than zero" }
{ "error": "UUID must not exceed 20 characters" }
{ "error": "Invalid paymentDate format, expected YYYY-MM-DD" }
{ "error": "Cannot change UUID" }
{ "error": "Cannot change client" }
{ "error": "uuid query parameter is required" }

// 404 Not Found
{ "error": "Payment not found" }
{ "error": "Client not found" }

// 422 Unprocessable Entity
{ "error": "Client is inactive" }
```

## Dependencias

### Módulos requeridos (existentes)
- **Auth** (spec 01): JWT con `notaryId`
- **PaymentClient** (spec 09): `PaymentClientRepository` para validar cliente
- **PaymentTransaction** (spec 11): patrón de referencia (UUID idempotente, inmutabilidad, manejo de carrera por índice único)

## Estructura del Módulo (sugerida)

```
src/Module/Payment/
├── Controller/PaymentController.php
├── DTO/{CreatePaymentRequest,UpdatePaymentRequest,PaymentResponse}.php
├── Entity/Payment.php
├── Repository/PaymentRepository.php
└── Service/PaymentService.php
```
Nombre del módulo `Payment` (la entidad es `payments_payments`); Arch decide si conviene `PaymentPayment` para alinear con el resto.

## Impacto en Sistema Existente

### Nuevos archivos
- Módulo completo + migración SQL

### Archivos a modificar
- `src/Config/routes.php` - rutas
- `docs/openapi.yaml` - documentación

### Sin cambios en
- `PaymentClientRepository` (solo lectura/validación; puede requerir un método para obtener el nombre del cliente)
- Módulo `PaymentTransaction`

## Notas para Implementación

1. **Orden de operaciones en POST**:
   - Validar campos requeridos y formato (400)
   - Buscar uuid en la notaría → si existe, retornar 200 con el existente (antes de validar cliente, para que un reintento no falle si el cliente se desactivó después)
   - Validar cliente existe (404) y activo (422)
   - INSERT; si falla por violación del índice único (carrera) → recargar por uuid y retornar 200
   - Cargar y retornar con `clientName`
2. **Una sola sentencia**: no hay detalles, por lo que no se necesita transacción de BD explícita
3. **Fecha**: `payment_date` es `datetime`; la API expone solo `YYYY-MM-DD` (se guarda con hora 00:00:00, como los datos existentes)
4. **Timezone**: `America/Mexico_City`
5. **Collation `utf8mb3_bin`**: comparaciones de `uuid` sensibles a mayúsculas; mantener en queries
6. **Multi-tenant**: todas las queries filtran por `id_notary`

## Estimación

- **Complejidad**: Baja-Media (CRUD sin detalles; patrón ya resuelto en spec 11)
- **Riesgo**: Bajo (migración InnoDB de 16 filas)

| Tarea | Estimación |
|-------|------------|
| Migración InnoDB + índices | 30 min |
| Entity + DTO | 45 min |
| Repository | 1 hora |
| Service | 1 hora |
| Controller + rutas | 45 min |
| Tests unitarios + integración | 2 horas |
| OpenAPI docs | 30 min |
| **Total** | **~6-7 horas** |
