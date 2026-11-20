# Criterios de Aceptación

## Funcionales

### Escenario 1: PDF sin filtros
**Dado** un cliente activo con id=5 en la notaría 1, con 3 trámites activos y 2 cobros
**Cuando** hace GET a `/api/v3/payment-clients/5/statement/pdf`
**Entonces** responde **200** con:
- `Content-Type: application/pdf` y `Content-Disposition: inline`
- Un PDF válido (empieza con `%PDF-`)
- Periodo "Todos los movimientos"
- 5 filas en el libro (3 cargos y 2 abonos), sin fila "Saldo anterior"
- Resumen con total de cargos, total de abonos y saldo final iguales a `totalCharges`, `totalPayments` y `balance` del JSON de la spec 19

### Escenario 2: PDF con rango `from` y `to`
**Dado** un cliente con movimientos antes, dentro y después de un rango
**Cuando** hace GET con `?from=2026-03-01&to=2026-03-31`
**Entonces** el PDF:
- Muestra el periodo `Del 01/03/2026 al 31/03/2026`
- Empieza con la fila "Saldo anterior" igual a `pendingAmount` del JSON
- Lista solo los movimientos dentro del rango

### Escenario 3: Solo `from` o solo `to`
**Dado** un cliente con movimientos
**Cuando** hace GET con solo `from=2026-03-01`, o con solo `to=2026-03-31`
**Entonces** el periodo se muestra como `Desde 01/03/2026` o `Hasta 31/03/2026`. La fila "Saldo anterior" aparece solo cuando hay `from`

### Escenario 4: Libro cronológico con saldo corrido
**Dado** un cargo de $1,000 el día 10, un abono de $400 el día 12 y un cargo de $200 el día 15, sin saldo anterior
**Cuando** hace GET al PDF
**Entonces** las filas aparecen en orden cronológico con saldo corrido 1,000.00 → 600.00 → 800.00, y el saldo final es 800.00

### Escenario 5: Cargo antes que abono el mismo día
**Dado** un trámite y un cobro con la misma fecha
**Cuando** hace GET al PDF
**Entonces** la fila del trámite aparece antes que la del cobro

### Escenario 6: Trámite cancelado excluido
**Dado** un trámite cancelado (estatus 2) de $1,000 y un trámite activo de $500
**Cuando** hace GET al PDF
**Entonces** solo aparece el trámite activo. No hay fila ni marca de agua para el cancelado, y el saldo no lo incluye

### Escenario 7: Cliente inactivo
**Dado** un cliente con `id_status`=2 con saldo pendiente
**Cuando** hace GET a su PDF
**Entonces** responde **200** con el PDF completo

### Escenario 8: Cliente sin movimientos
**Dado** un cliente activo sin trámites ni cobros
**Cuando** hace GET a su PDF
**Entonces** responde **200** con un PDF que muestra "Sin movimientos en el periodo", resumen en 0.00 y la leyenda "SIN ADEUDO"

### Escenario 9: Rango sin movimientos pero con saldo anterior
**Dado** un cliente con saldo anterior de $800 y sin movimientos en el rango
**Cuando** hace GET con un `from` posterior a sus movimientos
**Entonces** el PDF muestra la fila "Saldo anterior" de $800, la leyenda "Sin movimientos en el periodo" y saldo final $800 con "SALDO PENDIENTE"

### Escenario 10: Saldo pendiente
**Dado** un cliente con saldo final de $1,200.00
**Cuando** hace GET al PDF
**Entonces** el PDF muestra "SALDO PENDIENTE", el importe `$1,200.00` y el importe con letra "MIL DOSCIENTOS PESOS 00/100 M.N."

### Escenario 11: Saldo a favor
**Dado** un cliente con cobros mayores que sus cargos, saldo final de −$300.00
**Cuando** hace GET al PDF
**Entonces** el PDF muestra "SALDO A FAVOR" con `$300.00` (valor absoluto) y su importe con letra. No se muestra un signo negativo como único indicador

### Escenario 12: Sin adeudo
**Dado** un cliente con saldo final exactamente 0.00
**Cuando** hace GET al PDF
**Entonces** el PDF muestra "SIN ADEUDO"

### Escenario 13: Cobro sin fecha sin filtros
**Dado** un cobro con `payment_date` = null
**Cuando** hace GET sin `from` ni `to`
**Entonces** el cobro aparece al final del libro con fecha "—" y suma al total de abonos y al saldo

### Escenario 14: Cobro sin fecha con filtro
**Dado** un cobro con `payment_date` = null
**Cuando** hace GET con `from` o `to`
**Entonces** el cobro no aparece ni suma a los totales

### Escenario 15: Cliente inexistente o de otra notaría
**Dado** que no existe el cliente 999, o que el cliente 7 es de la notaría 2
**Cuando** un usuario de la notaría 1 hace GET a `/api/v3/payment-clients/999/statement/pdf` o a `/7/statement/pdf`
**Entonces** responde **404** JSON con `{ "error": "Client not found" }` en ambos casos. No se revela que el cliente existe

### Escenario 16: Encabezado de la notaría
**Dado** una notaría con logo, notario titular, RFC, dirección y teléfono
**Cuando** hace GET al PDF
**Entonces** el encabezado muestra todos esos datos. Si la notaría no tiene logo, o el archivo no existe, se omite el logo y el PDF se genera igual

### Escenario 17: Muchos movimientos
**Dado** un cliente con más movimientos de los que caben en una hoja
**Cuando** hace GET al PDF
**Entonces** la tabla continúa en la página siguiente, el encabezado de columnas se repite, una fila no se parte entre páginas, y el resumen y la leyenda de saldo aparecen al final sin quedar cortados

### Escenario 18: Footer
**Dado** un PDF de varias páginas
**Cuando** se abre
**Entonces** cada página tiene `Generado el dd/mm/yyyy HH:MM by notarial.devrob.net - Pagina n`, con fecha en `America/Mexico_City` y `n` desde 1

## Validaciones

### Escenario 19: `id` no numérico, cero o negativo
**Cuando** hace GET a `/payment-clients/abc/statement/pdf`, `/0/statement/pdf` o `/-3/statement/pdf`
**Entonces**:
- `abc` responde **400** `{ "error": "Invalid ID format" }`
- `0` o `-3` responden **404** `{ "error": "Client not found" }`

### Escenario 20: Fechas inválidas
**Cuando** hace GET con `from=01/03/2026`, `from=2026-3-1` o `from=2026-02-31`
**Entonces** responde **400** JSON `{ "error": "Validation failed", "errors": { "from": "..." } }`. No se genera PDF

### Escenario 21: Rango invertido
**Cuando** hace GET con `from=2026-04-01` y `to=2026-03-01`
**Entonces** responde **400** con `{ "error": "Validation failed", "errors": { "to": "..." } }`

### Escenario 22: Sin autenticación
**Cuando** hace GET sin token o con token inválido
**Entonces** responde **401** (middleware `auth`)

### Escenario 23: Falla al generar el PDF
**Dado** un error interno al armar o renderizar el PDF
**Cuando** hace GET
**Entonces** responde **500** JSON `{ "error": "Could not generate statement" }`. Nunca se envía un PDF parcial ni encabezados de PDF antes del error

## No Funcionales
- **Solo lectura**: no escribe en ninguna tabla.
- **Multi-tenant**: todas las consultas (cliente, trámites, cobros, saldo anterior y datos de la notaría) filtran por `notary_id` del token.
- **Consistencia**: los montos del PDF salen de `PaymentStatementService`. No se recalculan en el renderer.
- **Montos**: formato `$1,234.56`, dos decimales, pesos mexicanos.
- **Fechas**: `dd/mm/yyyy` en `America/Mexico_City`.
- **Tipografía**: DejaVu Sans, para soportar acentos y la ñ.
- **Sin caché**: `Cache-Control: no-store`, igual que los recibos.
- **Seguridad del PDF**: el HTML solo incrusta data URIs, sin acceso remoto ni PHP. Todos los textos de usuario (nombre del cliente, comentarios, referencias) se escapan.
- **Rendimiento**: sin límite definido. Hoy no hay clientes con miles de movimientos. Arch revisa el costo de Dompdf con volumen alto.

## Validaciones
- `{id}`: entero. No numérico es 400. Menor o igual a 0 es 404.
- `from` y `to`: `YYYY-MM-DD`, fecha real. Si vienen ambos, `from` ≤ `to`.
- Parámetro vacío (`from=`) se trata como no enviado. Parámetros desconocidos se ignoran.
- Las validaciones son las mismas que las del JSON (`StatementQuery`).

## Casos Edge
- Cliente sin teléfono o correo: se muestra "—" en el PDF.
- Notaría inexistente o inactiva en el token: error 500 controlado, igual que en los recibos.
- Nombre de cliente o comentario muy largo: el texto se ajusta dentro de su celda sin romper la tabla.
- Trámite con procedimiento eliminado: el concepto muestra solo la referencia, o "—" si tampoco hay.
- Cobro sin comentario: el concepto muestra "Abono".
- Caracteres especiales o HTML en nombres y comentarios: se escapan y no alteran el documento.
- Saldo corrido con decimales de punto flotante: se calcula en centavos enteros, igual que el servicio.
- Cliente cuyo nombre cambió: el PDF usa el nombre actual.

## Criterios de Rechazo
- El PDF incluye trámites cancelados o los suma al saldo.
- El saldo final, los totales o el saldo anterior del PDF difieren del JSON de la spec 19 para el mismo cliente y rango.
- Un cliente, movimiento o dato de notaría de otra notaría aparece en el PDF.
- Un rango `to` excluye movimientos del último día.
- Una fecha inválida devuelve un PDF en vez de 400.
- Una falla de generación envía un PDF parcial o corrupto.
- Un saldo a favor se muestra solo como número negativo, sin leyenda.
- El endpoint escribe datos.
- Cambia el comportamiento del JSON de la spec 19 o de los recibos de la spec 18.
