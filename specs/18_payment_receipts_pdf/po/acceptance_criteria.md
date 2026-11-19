# Criterios de Aceptación

## Funcionales

### Escenario 1: Recibo de trámite por ID
**Dado** un usuario autenticado de la notaría 1 y un trámite activo con id=25
**Cuando** hace GET a `/api/v3/payment-transactions/25/receipt`
**Entonces** responde **200** con:
- `Content-Type: application/pdf`
- `Content-Disposition: inline; filename="recibo-tramite-T-000025.pdf"`
- Un PDF con el recibo de trámite (encabezado, cliente, detalle, total)

### Escenario 2: Recibo de trámite por UUID
**Dado** un trámite activo con uuid="Us2Gvq" en la notaría 1
**Cuando** hace GET a `/api/v3/payment-transactions/uuid/Us2Gvq/receipt`
**Entonces** responde **200** con el mismo PDF que el Escenario 1 para ese trámite

### Escenario 3: Recibo de cobro por ID
**Dado** un cobro con id=10 en la notaría 1
**Cuando** hace GET a `/api/v3/payments/10/receipt`
**Entonces** responde **200** con `application/pdf` y `filename="recibo-cobro-C-000010.pdf"`

### Escenario 4: Recibo de cobro por UUID
**Dado** un cobro con uuid="abc123xyz" en la notaría 1
**Cuando** hace GET a `/api/v3/payments/uuid/abc123xyz/receipt`
**Entonces** responde **200** con el PDF del cobro

### Escenario 5: Trámite inactivo lleva marca de agua
**Dado** un trámite con `id_status`=2 (inactivo)
**Cuando** pide su recibo (por id o uuid)
**Entonces** responde **200** y el PDF muestra la marca de agua "CANCELADO" en cada página

### Escenario 6: Trámite activo sin marca de agua
**Dado** un trámite con `id_status`=1 (activo)
**Cuando** pide su recibo
**Entonces** el PDF no muestra marca de agua

### Escenario 7: Cobro nunca lleva marca de agua
**Dado** cualquier cobro
**Cuando** pide su recibo
**Entonces** el PDF no muestra marca de agua, sin importar la fecha ni el monto

### Escenario 8: Notaría con logo
**Dado** una notaría con logo cargado
**Cuando** se genera cualquier recibo
**Entonces** el encabezado muestra el logo de la notaría

### Escenario 9: Notaría sin logo
**Dado** una notaría sin logo (`logo` vacío)
**Cuando** se genera cualquier recibo
**Entonces** responde 200, el encabezado muestra solo los datos textuales de la notaría, sin espacio vacío visible ni error

### Escenario 10: Footer en cada página
**Dado** un recibo de cualquier tipo
**Cuando** se genera el PDF
**Entonces** cada página muestra en el footer:
`Generado el dd/mm/yyyy HH:MM by notarial.devrob.net - Pagina n`
- La fecha y hora corresponden a `America/Mexico_City` en el momento de la generación
- `n` empieza en 1 y aumenta por página

### Escenario 11: Importe con letra
**Dado** un trámite con total 1200.00
**Cuando** se genera su recibo
**Entonces** el PDF muestra "MIL DOSCIENTOS PESOS 00/100 M.N."

**Dado** un cobro con monto 5000.50
**Entonces** el PDF muestra "CINCO MIL PESOS 50/100 M.N."

### Escenario 12: Código QR
**Dado** cualquier recibo
**Cuando** se genera el PDF
**Entonces** incluye un código QR cuyo contenido es el uuid del registro

### Escenario 13: Detalle de trámite con impuestos
**Dado** un trámite con cuota de 1000.00 y dos impuestos (Impuesto A: 200.00, Impuesto B: 150.00)
**Cuando** se genera su recibo
**Entonces** el PDF muestra la cuota, los dos impuestos en la tabla, impuestos totales de 350.00 y total de 1350.00

### Escenario 14: Trámite sin impuestos
**Dado** un trámite con cuota de 1000.00 y sin detalles de impuestos
**Cuando** se genera su recibo
**Entonces** el PDF muestra solo la cuota y el total de 1000.00, sin tabla vacía

### Escenario 15: Cobro sin fecha ni comentarios
**Dado** un cobro con `payment_date` nulo y `comments` vacío
**Cuando** se genera su recibo
**Entonces** responde 200 y muestra "Sin fecha" y un campo de comentarios vacío (sin texto "null")

### Escenario 16: Recibo no encontrado
**Dado** un id o uuid que no existe o pertenece a otra notaría
**Cuando** hace GET a cualquier ruta de recibo
**Entonces** responde **404** con `{"error": "Payment transaction not found"}` (trámite) o `{"error": "Payment not found"}` (cobro)

### Escenario 17: Multi-tenant
**Dado** un trámite con id=25 de la notaría 2
**Cuando** un usuario de la notaría 1 hace GET a `/api/v3/payment-transactions/25/receipt`
**Entonces** responde 404 (no 403) y no revela que el registro existe

### Escenario 18: ID inválido
**Cuando** hace GET a `/api/v3/payment-transactions/abc/receipt` o `/api/v3/payments/0/receipt`
**Entonces** responde **400** con `{"error": "Invalid id"}`

### Escenario 19: UUID inválido
**Cuando** hace GET a `/api/v3/payment-transactions/uuid//receipt` o con un uuid de más de 20 caracteres
**Entonces** responde **400** con `{"error": "Invalid uuid"}`

### Escenario 20: Sin autenticación
**Cuando** hace GET a cualquier ruta de recibo sin token válido
**Entonces** responde **401**, sin generar ningún PDF

### Escenario 21: Nombres con acentos y caracteres especiales
**Dado** un cliente "Iñaki Ángeles Ñúñez" y un procedimiento "Compraventa – Crédito hipotecario"
**Cuando** se genera el recibo
**Entonces** los caracteres se muestran correctamente (sin signos de interrogación ni cuadros)

### Escenario 22: Tabla de impuestos muy larga
**Dado** un trámite con 40 impuestos
**Cuando** se genera el recibo
**Entonces** la tabla continúa en las páginas siguientes, repite el encabezado de la tabla y el footer aparece en todas las páginas

## No Funcionales
- **Solo lectura**: ningún recibo modifica datos en la base
- **Sin persistencia**: el PDF se genera en el momento y no se guarda en disco ni en base
- **Rendimiento**: el tiempo de respuesta debe ser razonable para uso en mostrador; el objetivo concreto se define en Arch
- **Consistencia**: los montos se muestran con dos decimales y separador de miles (ej. `$1,350.00`)
- **Idioma**: el contenido del PDF está en español (México); los mensajes de error de la API están en inglés, según la convención del proyecto
- **Zona horaria**: `America/Mexico_City` para fechas y para el footer
- **Seguridad**: el PDF no incluye datos de otras notarías ni datos internos (contraseñas, tokens, rutas de archivo)
- **Formato**: el PDF es tamaño carta, vertical

## Validaciones
- `id`: entero mayor que 0
- `uuid`: no vacío, máximo 20 caracteres, sin espacios
- Pertenencia a la notaría del JWT en todas las consultas

## Casos Edge
- Trámite inactivo con detalles de impuestos → marca de agua y detalle normal
- Cobro con `payment_date` nulo → "Sin fecha"
- Trámite cuyo cliente o procedimiento ya no existe → el recibo se genera con el nombre guardado o con "—" en lugar de romper la generación
- Logo registrado pero archivo inexistente en disco → se omite el logo, el recibo se genera (ver "Puntos por confirmar")
- Montos con centavos cero → "00/100 M.N."
- Montos muy grandes (ej. 1,000,000.00) → "UN MILLÓN PESOS 00/100 M.N."

## Criterios de Rechazo
- El footer no tiene el formato exacto pedido, o la fecha no está en `America/Mexico_City`
- El recibo de un trámite de otra notaría es visible o se puede adivinar por respuesta
- El PDF contiene "null", "undefined" o texto de depuración
- La generación falla y devuelve un PDF incompleto en lugar de un error JSON
- Un cobro aparece con marca de agua "CANCELADO"
- El importe con letra no coincide con el total numérico
