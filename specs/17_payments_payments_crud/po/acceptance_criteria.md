# Criterios de Aceptación

## Funcionales

### Escenario 1: Crear pago
**Dado** un usuario autenticado con un cliente activo (id=5)
**Cuando** hace POST a `/api/v3/payments` con:
```json
{
  "clientId": 5,
  "uuid": "abc123xyz",
  "amount": 5000.00,
  "paymentDate": "2024-04-02",
  "comments": "Adelanto de mensualidad"
}
```
**Entonces** responde **201** con el pago creado incluyendo `paymentId`, `uuid`, `clientId`, `clientName`, `amount`, `paymentDate` y `comments`

### Escenario 2: Crear pago sin fecha ni comentarios
**Dado** un usuario autenticado con un cliente activo
**Cuando** hace POST sin `paymentDate` ni `comments`
**Entonces** responde 201, `paymentDate` = fecha actual (`America/Mexico_City`) y `comments` = `""`

### Escenario 3: POST con UUID existente (idempotencia / reintento)
**Dado** un pago existente con uuid="abc123xyz" en la notaría del usuario
**Cuando** hace POST con el mismo uuid (reintento por doble clic, reconexión, offline-first)
**Entonces** responde **200** con el pago **existente** sin modificarlo ni duplicarlo, aunque `amount`, `paymentDate` o `comments` del request difieran

**Nota**: Para modificar el pago el usuario debe usar PUT explícito.

### Escenario 4: Mismo UUID en otra notaría
**Dado** un pago con uuid="abc123xyz" en la notaría A
**Cuando** un usuario de la notaría B hace POST con uuid="abc123xyz"
**Entonces** responde 201 y crea un pago independiente (la unicidad es por notaría) sin exponer el de la notaría A

### Escenario 5: Cliente inexistente o de otra notaría
**Dado** un clientId que no existe o pertenece a otra notaría
**Cuando** hace POST con ese clientId
**Entonces** responde 404 con `{"error": "Client not found"}`

### Escenario 6: Cliente inactivo
**Dado** un cliente con id_status=2 (inactivo)
**Cuando** hace POST con ese clientId
**Entonces** responde 422 con `{"error": "Client is inactive"}`

### Escenario 7: Obtener pago por ID
**Dado** un pago existente con id=10 en la notaría del usuario
**Cuando** hace GET a `/api/v3/payments/10`
**Entonces** responde 200 con el pago completo (incluye `clientName`)

### Escenario 8: Obtener pago por UUID
**Dado** un pago existente con uuid="abc123xyz" en la notaría del usuario
**Cuando** hace GET a `/api/v3/payments?uuid=abc123xyz`
**Entonces** responde 200 con el pago completo

### Escenario 9: Pago no encontrado
**Dado** un id o uuid que no existe o pertenece a otra notaría
**Cuando** hace GET (por id o por uuid) o PUT
**Entonces** responde 404 con `{"error": "Payment not found"}`

### Escenario 10: GET sin id ni uuid
**Cuando** hace GET a `/api/v3/payments` sin query `uuid`
**Entonces** responde 400 con `{"error": "uuid query parameter is required"}` (el listado está fuera de alcance)

### Escenario 11: Actualizar pago (PUT)
**Dado** un pago existente con id=10
**Cuando** hace PUT a `/api/v3/payments/10` con nuevo `amount`, `paymentDate` y/o `comments`
**Entonces** responde 200 con el pago actualizado; `uuid`, `clientId` y `paymentId` no cambian

### Escenario 12: PUT con campos omitidos
**Dado** un pago existente con `paymentDate`="2024-04-02" y comentarios
**Cuando** hace PUT omitiendo `paymentDate`
**Entonces** responde 200 y conserva la fecha existente

### Escenario 13: PUT no puede cambiar uuid
**Dado** un pago existente con uuid="abc123"
**Cuando** hace PUT con uuid="xyz789"
**Entonces** responde 400 con `{"error": "Cannot change UUID"}`

### Escenario 14: PUT no puede cambiar clientId
**Dado** un pago existente con clientId=5
**Cuando** hace PUT con clientId=7
**Entonces** responde 400 con `{"error": "Cannot change client"}`

### Escenario 15: PUT reenviando uuid y clientId sin cambios
**Dado** un pago existente con uuid="abc123" y clientId=5
**Cuando** hace PUT con el mismo uuid y clientId (el frontend reenvía el objeto completo)
**Entonces** responde 200 (no se considera cambio)

### Escenario 16: Monto inválido
**Dado** un request con `amount` ≤ 0 (0 o negativo)
**Cuando** hace POST o PUT
**Entonces** responde 400 con `{"error": "Amount must be greater than zero"}`

### Escenario 17: Aislamiento multi-tenant
**Dado** un pago de la notaría A
**Cuando** un usuario de la notaría B hace GET o PUT por su id o uuid
**Entonces** responde 404 (nunca 403, no se revela su existencia)

### Escenario 18: PUT con cliente actualmente inactivo
**Dado** un pago cuyo cliente fue desactivado después
**Cuando** hace PUT para corregir monto o comentarios
**Entonces** responde 200 (la validación de cliente activo aplica solo al crear)

## No Funcionales

- **Rendimiento**: operaciones en menos de 500ms
- **Concurrencia**: dos POST simultáneos con el mismo UUID no duplican (garantizado por índice único `(id_notary, uuid)`); el que pierde la carrera devuelve 200 con el existente
- **Seguridad**: nunca exponer datos de otras notarías

## Validaciones de Datos

### Campos requeridos en POST
| Campo | Tipo | Validación |
|-------|------|------------|
| clientId | int | Requerido, debe existir y estar activo |
| uuid | string | Requerido, no vacío, max 20 chars, único por notaría |
| amount | float | Requerido, > 0 |

### Campos opcionales en POST
| Campo | Tipo | Default | Validación |
|-------|------|---------|------------|
| paymentDate | string | Fecha actual (America/Mexico_City) | Formato YYYY-MM-DD, fecha válida |
| comments | string | "" | max 65535 chars |

### Campos en PUT
| Campo | Tipo | Validación |
|-------|------|------------|
| amount | float | Requerido, > 0 |
| paymentDate | string | Opcional; si se omite se conserva. Formato YYYY-MM-DD |
| comments | string | Opcional; si se omite se conserva |
| uuid | string | Opcional; si viene debe ser igual al existente |
| clientId | int | Opcional; si viene debe ser igual al existente |

## Casos Edge

1. **UUID de 20 caracteres exactos**: aceptado
2. **UUID de más de 20 caracteres**: rechazado (400)
3. **UUID vacío o solo espacios**: rechazado (400)
4. **Fecha en pasado o futuro**: aceptada
5. **`paymentDate` null en registros legacy**: se devuelve `null` en la respuesta
6. **`comments` null en registros legacy**: se devuelve `""`
7. **UUID sensible a mayúsculas**: la columna es `utf8mb3_bin`, `abc` y `ABC` son UUIDs distintos
8. **Amount con más de 2 decimales**: se acepta tal cual (la columna es `double`); redondeo fuera de alcance

## Criterios de Rechazo

- ❌ Datos de pagos de otras notarías visibles o modificables
- ❌ POST con UUID existente modifica o duplica el pago
- ❌ Cambio de `uuid` o `clientId` permitido en PUT
- ❌ Montos ≤ 0 aceptados
- ❌ Pago creado para cliente inactivo, inexistente o de otra notaría
