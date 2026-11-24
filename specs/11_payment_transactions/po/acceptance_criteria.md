# Criterios de Aceptación

## Funcionales

### Escenario 1: Crear transacción sin detalles
**Dado** un usuario autenticado con un cliente activo (id=5) y un procedimiento activo (id=3)
**Cuando** hace POST a `/api/v3/payment-transactions` con:
```json
{
  "clientId": 5,
  "procedureId": 3,
  "uuid": "abc123xyz",
  "date": "2024-01-15",
  "fee": 5000.00,
  "reference": "ESC-2024-001",
  "responsible": "Lic. García"
}
```
**Entonces** responde 201 con la transacción creada incluyendo `transactionId`, `total` calculado (5000), y campos expandidos (`clientName`, `procedureName`)

### Escenario 2: Crear transacción con detalles
**Dado** un usuario autenticado, cliente activo (id=5), procedimiento activo (id=3), e impuestos activos (ids 12, 15)
**Cuando** hace POST con:
```json
{
  "clientId": 5,
  "procedureId": 3,
  "uuid": "def456uvw",
  "date": "2024-01-15",
  "fee": 3000.00,
  "details": [
    { "taxId": 12, "quantity": 1, "amount": 500.00 },
    { "taxId": 15, "quantity": 2, "amount": 250.00 }
  ]
}
```
**Entonces** responde 201 con `taxes` = 1000.00 (500 + 250×2), `total` = 4000.00, y los detalles con `taxName` expandido

### Escenario 3: POST con UUID existente (idempotencia / reintento)
**Dado** una transacción existente con uuid="abc123xyz" en la notaría del usuario
**Cuando** hace POST con el mismo uuid (reintento por doble clic, reconexión, offline-first)
**Entonces** responde **200** con la transacción **existente** sin modificarla (idempotente)

**Nota**: Esto permite reintentos seguros en escenarios offline-first. Si el usuario quiere modificar la transacción, debe usar PUT explícito.

### Escenario 4: Cliente inexistente o de otra notaría
**Dado** un clientId que no existe o pertenece a otra notaría
**Cuando** hace POST con ese clientId
**Entonces** responde 404 con `{"error": "Client not found"}`

### Escenario 5: Cliente inactivo
**Dado** un cliente con id_status=2 (inactivo)
**Cuando** hace POST con ese clientId
**Entonces** responde 422 con `{"error": "Client is inactive"}`

### Escenario 6: Procedimiento inexistente o de otra notaría
**Dado** un procedureId que no existe o pertenece a otra notaría
**Cuando** hace POST con ese procedureId
**Entonces** responde 404 con `{"error": "Procedure not found"}`

### Escenario 7: Procedimiento inactivo
**Dado** un procedimiento con id_status=2 (inactivo)
**Cuando** hace POST con ese procedureId
**Entonces** responde 422 con `{"error": "Procedure is inactive"}`

### Escenario 8: Impuesto inactivo en detalles
**Dado** un detalle con taxId de un impuesto inactivo (id_status=2)
**Cuando** hace POST con ese detalle
**Entonces** el detalle se **ignora silenciosamente** (no se guarda, no da error), el resto de la transacción se procesa normalmente

### Escenario 9: Impuesto de otra notaría en detalles
**Dado** un detalle con taxId que pertenece a otra notaría
**Cuando** hace POST con ese detalle
**Entonces** el detalle se **ignora silenciosamente**

### Escenario 10: taxId repetido en el mismo request
**Dado** un request con dos detalles que tienen el mismo taxId
**Cuando** hace POST
**Entonces** solo se procesa el **primer** detalle con ese taxId, el segundo se ignora

### Escenario 11: Obtener transacción por ID
**Dado** una transacción existente con id=10 en la notaría del usuario
**Cuando** hace GET a `/api/v3/payment-transactions/10`
**Entonces** responde 200 con la transacción completa incluyendo detalles y nombres expandidos

### Escenario 12: Obtener transacción por UUID
**Dado** una transacción existente con uuid="abc123xyz" en la notaría del usuario
**Cuando** hace GET a `/api/v3/payment-transactions?uuid=abc123xyz`
**Entonces** responde 200 con la transacción completa

### Escenario 13: Transacción no encontrada
**Dado** un id o uuid que no existe o pertenece a otra notaría
**Cuando** hace GET
**Entonces** responde 404 con `{"error": "Transaction not found"}`

### Escenario 14: Actualizar transacción (PUT)
**Dado** una transacción existente con id=10
**Cuando** hace PUT a `/api/v3/payment-transactions/10` con nuevos datos
**Entonces** responde 200 con la transacción actualizada, los detalles anteriores se **reemplazan** por los nuevos

### Escenario 15: PUT no puede cambiar clientId
**Dado** una transacción existente con clientId=5
**Cuando** hace PUT con clientId=7
**Entonces** responde 400 con `{"error": "Cannot change client"}`

### Escenario 16: PUT no puede cambiar uuid
**Dado** una transacción existente con uuid="abc123"
**Cuando** hace PUT con uuid="xyz789"
**Entonces** responde 400 con `{"error": "Cannot change UUID"}`

### Escenario 17: PUT puede cambiar procedureId
**Dado** una transacción existente con procedureId=3
**Cuando** hace PUT con procedureId=5 (activo, de la misma notaría)
**Entonces** responde 200 con la transacción actualizada

### Escenario 18: Atomicidad - fallo al guardar detalle
**Dado** un POST válido con 3 detalles donde el tercero tiene un error interno
**Cuando** el sistema falla al insertar el tercer detalle
**Entonces** se hace rollback de todo: ni la transacción ni los detalles 1-2 se guardan

### Escenario 19: Valores negativos rechazados
**Dado** un request con `fee: -100` o un detalle con `amount: -50`
**Cuando** hace POST o PUT
**Entonces** responde 400 con `{"error": "Fee cannot be negative"}` o `{"error": "Detail amount cannot be negative"}`

### Escenario 20: Quantity menor a 1 rechazado
**Dado** un detalle con `quantity: 0`
**Cuando** hace POST o PUT
**Entonces** responde 400 con `{"error": "Detail quantity must be at least 1"}`

## No Funcionales

- **Rendimiento**: Las operaciones deben completarse en menos de 500ms
- **Atomicidad**: Usar transacciones de BD (InnoDB) - todo o nada
- **Seguridad**: Nunca exponer datos de otras notarías (multi-tenant estricto)

## Validaciones de Datos

### Campos requeridos en POST
| Campo | Tipo | Validación |
|-------|------|------------|
| clientId | int | Requerido, debe existir y estar activo |
| procedureId | int | Requerido, debe existir y estar activo |
| uuid | string | Requerido, max 20 chars, único por notaría |
| date | string | Requerido, formato YYYY-MM-DD |

### Campos opcionales en POST
| Campo | Tipo | Default | Validación |
|-------|------|---------|------------|
| fee | float | 0.0 | ≥ 0 |
| reference | string | "" | max 65535 chars (text) |
| responsible | string | "" | max 65535 chars (text) |
| comments | string | "" | max 65535 chars (text) |
| details | array | [] | Array de objetos detalle |

### Campos de cada detalle
| Campo | Tipo | Default | Validación |
|-------|------|---------|------------|
| taxId | int | - | Requerido, debe existir (inactivo = ignorar) |
| quantity | int | 1 | ≥ 1 |
| amount | float | 0.0 | ≥ 0 |
| date | string | null | Formato YYYY-MM-DD si presente |
| comments | string | "" | max 65535 chars |

### Campos calculados (servidor)
- `taxes` = Σ(quantity × amount) de todos los detalles válidos
- `total` = fee + taxes

## Casos Edge

1. **Transacción sin detalles**: Válido. `taxes` = 0, `total` = `fee`
2. **Todos los detalles ignorados** (todos inactivos): Válido. `taxes` = 0
3. **UUID de 20 caracteres exactos**: Aceptado (es el máximo del legacy)
4. **UUID vacío**: Rechazado (400)
5. **Fecha en el pasado**: Aceptada
6. **Fecha en el futuro**: Aceptada
7. **fee = 0 con detalles**: Válido
8. **PUT que elimina todos los detalles**: Válido, `details: []` borra todos los existentes

## Criterios de Rechazo

- ❌ Datos de transacciones de otras notarías visibles o modificables
- ❌ Operación parcial guardada (cabecera sin detalles o viceversa)
- ❌ Totales calculados incorrectamente
- ❌ POST con UUID existente modifica la transacción (debe ser idempotente, devolver la existente sin cambios)
- ❌ Cliente o procedimiento inactivo aceptado
- ❌ Cambio de clientId o uuid permitido en PUT
- ❌ Valores negativos aceptados
