# Contexto Técnico

## Entidades Involucradas

### Tabla principal: `payments_transactions`
```sql
CREATE TABLE `payments_transactions` (
  `id_transaction` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(20) NOT NULL DEFAULT '',
  `id_notary` int(11) NOT NULL,
  `id_client` int(11) NOT NULL,
  `id_procedure` int(11) NOT NULL,
  `reference` text NOT NULL,
  `date` datetime NOT NULL,
  `responsible` text NOT NULL,
  `fee` double NOT NULL DEFAULT 0,
  `taxes` double NOT NULL DEFAULT 0,
  `total` double NOT NULL DEFAULT 0,
  `comments` text NOT NULL,
  `id_status` int(11) DEFAULT 1,
  PRIMARY KEY (`id_transaction`)
) ENGINE=MyISAM  -- MIGRAR A InnoDB
```

### Tabla de detalles: `payments_transaction_details`
```sql
CREATE TABLE `payments_transaction_details` (
  `id_transaction` int(11) NOT NULL,
  `id_tax` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `amount` double DEFAULT 0,
  `date` datetime DEFAULT NULL,
  `comments` text DEFAULT NULL
  -- SIN PRIMARY KEY  -- AGREGAR PK (id_transaction, id_tax)
) ENGINE=MyISAM  -- MIGRAR A InnoDB
```

### Tablas relacionadas (solo lectura, validación)
- `payments_clients` - Validar existencia, status y notaría del cliente
- `payment_procedures` - Validar existencia, status y notaría del procedimiento
- `payment_taxes` - Validar existencia, status y notaría del impuesto

## Migración de Base de Datos (Prerequisito)

### Paso 1: Limpiar duplicados existentes
```sql
-- Verificar duplicados de uuid por notaría
SELECT id_notary, uuid, COUNT(*) as cnt 
FROM payments_transactions 
GROUP BY id_notary, uuid 
HAVING cnt > 1;

-- Eliminar duplicados (mantener el de menor id_transaction)
DELETE t1 FROM payments_transactions t1
INNER JOIN payments_transactions t2 
WHERE t1.id_notary = t2.id_notary 
  AND t1.uuid = t2.uuid 
  AND t1.id_transaction > t2.id_transaction;
```

**Nota**: En BD local existe 1 duplicado: `(id_notary=1, uuid='NdARZM')`. Verificar en producción antes de ejecutar.

### Paso 2: Migrar a InnoDB y crear índices
```sql
-- Migrar tablas a InnoDB
ALTER TABLE payments_transactions ENGINE=InnoDB;
ALTER TABLE payments_transaction_details ENGINE=InnoDB;

-- Índice único para uuid por notaría
ALTER TABLE payments_transactions 
ADD UNIQUE INDEX idx_notary_uuid (id_notary, uuid);

-- Primary key compuesta para detalles
ALTER TABLE payments_transaction_details 
ADD PRIMARY KEY (id_transaction, id_tax);

-- Índice para búsquedas por cliente
ALTER TABLE payments_transactions 
ADD INDEX idx_client (id_client);
```

## Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v3/payment-transactions` | Crear transacción con detalles |
| GET | `/api/v3/payment-transactions/{id}` | Obtener por ID |
| GET | `/api/v3/payment-transactions?uuid={uuid}` | Obtener por UUID |
| PUT | `/api/v3/payment-transactions/{id}` | Actualizar transacción y detalles |

### Request/Response Examples

#### POST /api/v3/payment-transactions
Request:
```json
{
  "clientId": 5,
  "procedureId": 3,
  "uuid": "NxK9mZ",
  "date": "2024-01-15",
  "fee": 5000.00,
  "reference": "ESC-2024-001",
  "responsible": "Lic. García",
  "comments": "Compraventa de inmueble",
  "details": [
    { "taxId": 12, "quantity": 1, "amount": 1500.00, "comments": "ISR" },
    { "taxId": 15, "quantity": 1, "amount": 800.00, "date": "2024-01-20" }
  ]
}
```

Response 201:
```json
{
  "transactionId": 45,
  "uuid": "NxK9mZ",
  "clientId": 5,
  "clientName": "Juan Pérez López",
  "procedureId": 3,
  "procedureName": "Compraventa",
  "date": "2024-01-15",
  "fee": 5000.00,
  "taxes": 2300.00,
  "total": 7300.00,
  "reference": "ESC-2024-001",
  "responsible": "Lic. García",
  "comments": "Compraventa de inmueble",
  "details": [
    {
      "taxId": 12,
      "taxName": "ISR",
      "quantity": 1,
      "amount": 1500.00,
      "date": null,
      "comments": "ISR"
    },
    {
      "taxId": 15,
      "taxName": "ISAI",
      "quantity": 1,
      "amount": 800.00,
      "date": "2024-01-20",
      "comments": ""
    }
  ]
}
```

#### GET /api/v3/payment-transactions/45
Response 200: (mismo formato que POST response)

#### GET /api/v3/payment-transactions?uuid=NxK9mZ
Response 200: (mismo formato que POST response)

#### PUT /api/v3/payment-transactions/45
Request:
```json
{
  "clientId": 5,
  "procedureId": 3,
  "uuid": "NxK9mZ",
  "date": "2024-01-16",
  "fee": 5500.00,
  "reference": "ESC-2024-001-A",
  "responsible": "Lic. García",
  "details": [
    { "taxId": 12, "quantity": 1, "amount": 1600.00 }
  ]
}
```

Response 200:
```json
{
  "transactionId": 45,
  "uuid": "NxK9mZ",
  "clientId": 5,
  "clientName": "Juan Pérez López",
  "procedureId": 3,
  "procedureName": "Compraventa",
  "date": "2024-01-16",
  "fee": 5500.00,
  "taxes": 1600.00,
  "total": 7100.00,
  "reference": "ESC-2024-001-A",
  "responsible": "Lic. García",
  "comments": "Compraventa de inmueble",
  "details": [
    {
      "taxId": 12,
      "taxName": "ISR",
      "quantity": 1,
      "amount": 1600.00,
      "date": null,
      "comments": ""
    }
  ]
}
```
**Nota**: El taxId=15 fue eliminado porque no vino en el request.

### Error Responses

```json
// 400 Bad Request - Validación
{ "error": "Required fields: clientId, procedureId, uuid, date" }
{ "error": "Fee cannot be negative" }
{ "error": "Detail quantity must be at least 1" }
{ "error": "Cannot change client" }
{ "error": "Cannot change UUID" }

// 404 Not Found
{ "error": "Transaction not found" }
{ "error": "Client not found" }
{ "error": "Procedure not found" }

// 200 OK (POST idempotente - UUID ya existe)
// Devuelve la transacción existente sin modificarla

// 422 Unprocessable Entity
{ "error": "Client is inactive" }
{ "error": "Procedure is inactive" }
```

## Dependencias

### Módulos requeridos (existentes)
- **Auth** (spec 01): Token JWT con `notaryId` y `userId`
- **PaymentClient** (spec 09): Validar cliente
- **PaymentProcedure** (spec 08): Validar procedimiento
- **PaymentTax** (spec 07): Validar impuestos en detalles

### Servicios a inyectar
```php
class PaymentTransactionService {
    public function __construct(
        private PaymentTransactionRepository $transactionRepository,
        private PaymentClientRepository $clientRepository,
        private PaymentProcedureRepository $procedureRepository,
        private PaymentTaxRepository $taxRepository,
        private PDO $connection  // Para transacciones
    ) {}
}
```

## Estructura del Módulo

```
src/Module/PaymentTransaction/
├── Controller/
│   └── PaymentTransactionController.php
├── DTO/
│   ├── CreateTransactionRequest.php
│   ├── UpdateTransactionRequest.php
│   ├── TransactionDetailItem.php
│   └── TransactionResponse.php
├── Entity/
│   ├── PaymentTransaction.php
│   └── PaymentTransactionDetail.php
├── Exception/
│   └── TransactionException.php
├── Repository/
│   └── PaymentTransactionRepository.php
└── Service/
    └── PaymentTransactionService.php
```

## Impacto en Sistema Existente

### Nuevos archivos
- Todo el módulo `PaymentTransaction/`
- Migración SQL para InnoDB + índices

### Archivos a modificar
- `src/Config/routes.php` - Agregar rutas
- `docs/openapi.yaml` - Documentar endpoints

### Sin cambios en
- `PaymentClientRepository` - Solo se usa para validar
- `PaymentProcedureRepository` - Solo se usa para validar
- `PaymentTaxRepository` - Solo se usa para validar

## Consideraciones de Datos

### Transacciones de BD (PHP PDO)

El Service debe manejar la transacción:
```php
public function create(CreateTransactionRequest $request, int $notaryId): TransactionResponse
{
    $this->connection->beginTransaction();
    try {
        // 1. Validar cliente, procedimiento
        // 2. Verificar uuid único
        // 3. Insertar transacción
        // 4. Insertar detalles válidos
        // 5. Calcular totales y actualizar
        $this->connection->commit();
        return $response;
    } catch (\Exception $e) {
        $this->connection->rollBack();
        throw $e;
    }
}
```

**El Repository NO abre transacciones** - solo ejecuta queries individuales.

### PUT - Reemplazo de detalles

```php
public function update(int $id, UpdateTransactionRequest $request, int $notaryId): TransactionResponse
{
    $this->connection->beginTransaction();
    try {
        // 1. Validar que exista y sea de esta notaría
        // 2. Validar que clientId y uuid no cambien
        // 3. Validar nuevo procedureId si cambió
        // 4. DELETE FROM payments_transaction_details WHERE id_transaction = ?
        // 5. INSERT nuevos detalles
        // 6. UPDATE transacción con nuevos totales
        $this->connection->commit();
        return $response;
    } catch (\Exception $e) {
        $this->connection->rollBack();
        throw $e;
    }
}
```

### Cálculo de totales

```php
private function calculateTotals(float $fee, array $details): array
{
    $taxes = 0.0;
    foreach ($details as $detail) {
        $taxes += $detail->getQuantity() * $detail->getAmount();
    }
    return [
        'taxes' => $taxes,
        'total' => $fee + $taxes
    ];
}
```

### Filtrado de detalles inválidos

```php
private function filterValidDetails(array $details, int $notaryId): array
{
    $validDetails = [];
    $seenTaxIds = [];
    
    foreach ($details as $detail) {
        // Ignorar taxId repetido
        if (in_array($detail->getTaxId(), $seenTaxIds)) {
            continue;
        }
        
        // Validar que el impuesto existe, es de esta notaría y está activo
        $tax = $this->taxRepository->findById($detail->getTaxId(), $notaryId);
        if ($tax === null || !$tax->isActive()) {
            continue; // Ignorar silenciosamente
        }
        
        $seenTaxIds[] = $detail->getTaxId();
        $validDetails[] = $detail;
    }
    
    return $validDetails;
}
```

## Notas para Implementación

1. **Orden de operaciones en POST**:
   - Validar campos requeridos (400 si faltan)
   - Validar cliente existe y activo (404/422)
   - Validar procedimiento existe y activo (404/422)
   - Verificar uuid en notaría → si existe, retornar 200 con existente (idempotencia)
   - Filtrar detalles válidos (sin error)
   - BEGIN TRANSACTION
   - INSERT transacción
   - INSERT detalles
   - Calcular y UPDATE totales
   - COMMIT
   - Cargar y retornar con nombres expandidos

2. **UUID handling**: El frontend genera UUIDs con `ShortUniqueId` (máx 20 chars) para soporte offline-first. POST es **idempotente**: si el UUID ya existe en la notaría, retorna 200 con la transacción existente sin modificarla (permite reintentos seguros por doble clic, reconexión, etc.).

3. **Compatibilidad con legacy**: El legacy usa GET con query param `?id=` o `?uuid=`. La API v3 usa path param para id (`/45`) y query param para uuid (`?uuid=abc`).

4. **Response incluye nombres**: Siempre expandir `clientName`, `procedureName`, y `taxName` en los detalles.

5. **Timezone**: Todas las fechas en `America/Mexico_City` (configurado en PHP).

6. **Campo `id_status`**: Siempre 1 (activo) al crear. No se modifica (fuera de alcance la anulación).

## Estimación

- **Complejidad**: Alta (transacciones BD, múltiples validaciones, relaciones)
- **Riesgo**: Medio (depende de migración InnoDB exitosa)

### Desglose
| Tarea | Estimación |
|-------|------------|
| Migración InnoDB + índices | 1 hora |
| Entity + DTO | 1 hora |
| Repository | 1.5 horas |
| Service (con transacciones) | 2.5 horas |
| Controller | 1 hora |
| Tests unitarios | 2 horas |
| Tests integración | 1.5 horas |
| OpenAPI docs | 30 min |
| **Total** | **11-12 horas** |
