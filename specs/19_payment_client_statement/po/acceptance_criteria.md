# Criterios de Aceptación

## Funcionales

### Escenario 1: Estado de cuenta completo sin filtros
**Dado** un cliente activo con id=5 en la notaría 1, con 3 trámites activos y 2 cobros
**Cuando** hace GET a `/api/v3/payment-clients/5/statement`
**Entonces** responde **200** con:
- `clientId`: 5, `clientName` con el nombre del cliente
- `fromDate` y `toDate`: `null`
- `transactions`: los 3 trámites activos, en orden cronológico ascendente
- `payments`: los 2 cobros, en orden cronológico ascendente
- `pendingAmount`: 0
- `totalCharges`: suma de `total` de los 3 trámites
- `totalPayments`: suma de `amount` de los 2 cobros
- `balance` = `totalCharges` − `totalPayments`

### Escenario 2: Estado de cuenta con rango `from` y `to`
**Dado** un cliente con movimientos antes, dentro y después de un rango
**Cuando** hace GET a `/api/v3/payment-clients/5/statement?from=2026-03-01&to=2026-03-31`
**Entonces** responde **200** con:
- `transactions` y `payments`: solo los movimientos dentro del rango
- `pendingAmount`: suma de cargos menos abonos de todas las fechas anteriores a `2026-03-01`
- `balance` = `pendingAmount` + `totalCharges` − `totalPayments`

### Escenario 3: Último día del rango incluido completo
**Dado** un trámite activo con `date` = `2026-03-31 14:35:00`
**Cuando** hace GET con `to=2026-03-31`
**Entonces** el trámite aparece en `transactions`

### Escenario 4: Primer día del rango incluido completo
**Dado** un cobro con `payment_date` = `2026-03-01 00:00:00`
**Cuando** hace GET con `from=2026-03-01`
**Entonces** el cobro aparece en `payments`

### Escenario 5: Solo `from`
**Dado** un cliente con movimientos antes y después de `2026-03-01`
**Cuando** hace GET con `from=2026-03-01` y sin `to`
**Entonces** `transactions` y `payments` incluyen todo lo posterior a `2026-03-01` (inclusive), `toDate` es `null` y `pendingAmount` cubre todo lo anterior

### Escenario 6: Solo `to`
**Dado** un cliente con movimientos antes y después de `2026-03-31`
**Cuando** hace GET con `to=2026-03-31` y sin `from`
**Entonces** `transactions` y `payments` incluyen todo lo anterior a `2026-03-31` (inclusive), `fromDate` es `null` y `pendingAmount` es 0

### Escenario 7: Trámite cancelado excluido de todos los cálculos
**Dado** un cliente con un trámite de estatus 2 (cancelado) de $1,000 y un trámite activo de $500
**Cuando** hace GET al estado de cuenta
**Entonces** responde **200** con:
- `transactions`: solo el trámite activo
- `totalCharges`: 500
- `balance` no incluye los $1,000 cancelados

### Escenario 8: Trámite cancelado excluido del saldo anterior
**Dado** un trámite cancelado de $1,000 en febrero y un trámite activo de $500 en febrero
**Cuando** hace GET con `from=2026-03-01`
**Entonces** `pendingAmount` = 500

### Escenario 9: Cliente inactivo se puede consultar
**Dado** un cliente con `id_status`=2 (inactivo) en la notaría 1 con saldo pendiente
**Cuando** hace GET a su estado de cuenta
**Entonces** responde **200** con el estado de cuenta completo

### Escenario 10: Cliente inexistente
**Dado** que no existe un cliente con id=999
**Cuando** hace GET a `/api/v3/payment-clients/999/statement`
**Entonces** responde **404** con `{ "error": "Client not found" }`

### Escenario 11: Cliente de otra notaría
**Dado** un cliente con id=7 que pertenece a la notaría 2
**Cuando** un usuario de la notaría 1 hace GET a `/api/v3/payment-clients/7/statement`
**Entonces** responde **404**, igual que si el cliente no existiera. No se revela que existe

### Escenario 12: Cliente sin movimientos
**Dado** un cliente activo sin trámites ni cobros
**Cuando** hace GET a su estado de cuenta
**Entonces** responde **200** con `transactions: []`, `payments: []`, `pendingAmount: 0`, `totalCharges: 0`, `totalPayments: 0`, `balance: 0`

### Escenario 13: Saldo a favor
**Dado** un cliente con cobros mayores que sus cargos
**Cuando** hace GET a su estado de cuenta
**Entonces** `balance` es negativo. Un saldo negativo significa que la notaría debe al cliente

### Escenario 14: Cobro sin fecha sin filtros
**Dado** un cobro con `payment_date` = null
**Cuando** hace GET sin `from` ni `to`
**Entonces** el cobro aparece en `payments`, con `date` = `null`, y suma a `totalPayments`

### Escenario 15: Cobro sin fecha con filtro de fechas
**Dado** un cobro con `payment_date` = null
**Cuando** hace GET con `from` o `to`
**Entonces** el cobro no aparece en `payments` y no suma a `totalPayments`

### Escenario 16: Montos a 2 decimales
**Dado** movimientos con montos que generan más de 2 decimales en la suma
**Cuando** hace GET al estado de cuenta
**Entonces** `pendingAmount`, `totalCharges`, `totalPayments` y `balance` tienen como máximo 2 decimales

## Validaciones

### Escenario 17: `clientId` no numérico, cero o negativo
**Dado** un usuario autenticado
**Cuando** hace GET a `/api/v3/payment-clients/abc/statement`, `/payment-clients/0/statement` o `/payment-clients/-3/statement`
**Entonces**:
- `abc` (no numérico) responde **400** con `{ "error": "Invalid ID format" }`
- `0` o `-3` responden **404** con `{ "error": "Client not found" }`, igual que el módulo PaymentClient existente

### Escenario 18: Fecha con formato inválido
**Dado** un usuario autenticado
**Cuando** hace GET con `from=01/03/2026` o `from=2026-3-1`
**Entonces** responde **400** con `{ "error": "Validation failed", "errors": { "from": "..." } }`, usando el formato `YYYY-MM-DD`

### Escenario 19: Fecha inexistente
**Dado** un usuario autenticado
**Cuando** hace GET con `from=2026-02-31`
**Entonces** responde **400**. No se corrige a 2026-03-03

### Escenario 20: Rango invertido
**Dado** un usuario autenticado
**Cuando** hace GET con `from=2026-04-01` y `to=2026-03-01`
**Entonces** responde **400** con `{ "error": "Validation failed", "errors": { "to": "..." } }` indicando que `to` no puede ser anterior a `from`

### Escenario 21: `from` y `to` iguales
**Dado** un usuario autenticado
**Cuando** hace GET con `from=2026-03-15` y `to=2026-03-15`
**Entonces** responde **200** con los movimientos de ese único día

## No Funcionales
- **Solo lectura**: la consulta no escribe en ninguna tabla.
- **Multi-tenant**: todas las consultas (cliente, trámites, cobros y saldo anterior) filtran por `id_notary` del token.
- **Rendimiento (propuesta, Arch confirma)**: p95 menor a 500 ms para un cliente con hasta 500 movimientos en el rango consultado.
- **Montos**: `double` redondeado a 2 decimales al armar la respuesta. No se recalculan `total` ni `taxes`; se usan los valores guardados.
- **Zona horaria**: las fechas de la respuesta se muestran en `Y-m-d` con `America/Mexico_City`.
- **Sin caché**: cada consulta lee la base de datos.

## Validaciones
- `clientId` es un entero. No numérico responde 400; menor o igual a 0 responde 404 (convención del módulo PaymentClient).
- `from` y `to`, si se envían, cumplen formato `YYYY-MM-DD` y existen en el calendario.
- Si se envían ambos, `from` ≤ `to`.
- Un parámetro vacío (`from=`) se trata como no enviado.
- Los parámetros desconocidos se ignoran.

## Casos Edge
- Cliente con trámites cancelados únicamente: `transactions: []` y totales en 0.
- Trámite o cobro con `date` a las 23:59:59 del último día del rango: incluido.
- Rango de un solo día: incluye movimientos de todo ese día.
- Rango sin movimientos pero con saldo anterior: `transactions` y `payments` vacíos, `pendingAmount` con el saldo previo, `balance` = `pendingAmount`.
- Cliente cuyo nombre cambió después de sus movimientos: `clientName` muestra el nombre actual.
- Trámite con procedimiento eliminado: `procedureName` se devuelve como `""`, sin error.

## Criterios de Rechazo
- La respuesta incluye trámites cancelados en `transactions`, `totalCharges` o `balance`.
- Un cliente o movimiento de otra notaría aparece en la respuesta o altera los totales.
- Un rango `to` excluye movimientos del último día por la hora de la fecha.
- Una fecha inválida devuelve datos en vez de 400.
- La consulta escribe datos o modifica algún registro.
- Los campos de la respuesta cambian de nombre respecto a v1 (solo cambia la ruta).
