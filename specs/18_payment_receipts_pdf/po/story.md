# Recibos en PDF (Trámites y Cobros)

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** descargar un recibo en PDF de un trámite o de un cobro
**Para** entregarle al cliente un comprobante profesional con los datos de la notaría, el detalle de lo cobrado y el total

## Contexto

La notaría cobra en dos situaciones distintas, y cada una tiene su propio recibo:

| Concepto | Entidad | Qué representa | Recibo |
|----------|---------|----------------|--------|
| **Trámite** | `payments_transactions` (spec 11) | Cargo al cliente por un procedimiento: cuota de honorarios + impuestos con detalle | Recibo de trámite |
| **Cobro** | `payments_payments` (spec 17) | Dinero que el cliente entrega (anticipo, abono) | Recibo de cobro |

Hoy el sistema guarda estos datos, pero no hay forma de entregar un comprobante al cliente. Este feature agrega la generación del PDF a partir de un registro existente. **No modifica datos**: es una operación de solo lectura.

### Identificación del recurso
- El recibo se pide por **id** (interno) o por **uuid** (el generado por el frontend). Ambos son válidos.
- Siempre se filtra por la notaría del usuario autenticado. Un recibo de otra notaría se trata como inexistente.

### Diseño del recibo
Un documento carta, vertical, con estilo sobrio y profesional:

1. **Encabezado**: logo de la notaría (si tiene), nombre de la notaría, notario titular, RFC, dirección y teléfono.
2. **Título y folio**: "RECIBO DE TRÁMITE" o "RECIBO DE COBRO", con folio, fecha y un código QR con el uuid del registro.
3. **Cliente**: nombre, teléfono y correo.
4. **Detalle**:
   - *Trámite*: procedimiento, referencia, responsable, cuota de honorarios, tabla de impuestos (cantidad, concepto, importe), impuestos totales y **total**.
   - *Cobro*: monto, fecha de pago y comentarios.
5. **Importe con letra**: por ejemplo "MIL DOSCIENTOS PESOS 00/100 M.N.".
6. **Marca de agua "CANCELADO"** cuando el trámite está inactivo (estatus 2). Los cobros no tienen estatus, así que nunca la llevan.
7. **Footer en cada página**: `Generado el dd/mm/yyyy HH:MM by notarial.devrob.net - Pagina n`
   - Fecha y hora en `America/Mexico_City`, al momento de generar el PDF.
   - `n` es el número de página, empezando en 1.

## Alcance

### Incluido
- `GET` del recibo de **trámite** por id y por uuid
- `GET` del recibo de **cobro** por id y por uuid
- Respuesta como PDF (`application/pdf`), visible en el navegador
- Diseño con encabezado, logo opcional, detalle, importe con letra, código QR y footer con paginación
- Marca de agua "CANCELADO" para trámites inactivos
- Validación de pertenencia a notaría (multi-tenant)
- Documentación OpenAPI

### Excluido
- Guardar, archivar o cachear los PDFs generados
- Envío del recibo por correo o WhatsApp
- Folio consecutivo independiente (ver "Puntos por confirmar")
- Plantillas configurables por notaría
- Firmas, sellos o líneas de firma
- Recibos de múltiples monedas (solo pesos mexicanos)
- Verificación pública del recibo a partir del QR (el QR solo contiene el uuid)
- Recibos para acts, deeds u otros módulos

## Usuarios Afectados

- **Empleados de notaría**: generan e imprimen recibos para entregar a clientes
- **Administradores**: consultan y reimprimen recibos
- **Clientes finales**: reciben el comprobante (no acceden directamente a la API)

## Valor de Negocio

- **Comprobante formal**: el cliente recibe un documento con los datos de la notaría, el detalle y el total
- **Trazabilidad**: cada recibo lleva folio, uuid y fecha de generación
- **Seguridad**: aislamiento estricto por notaría, sin exponer datos de otras notarías
- **Sin riesgo sobre datos**: la operación es de solo lectura

## Puntos por confirmar

Estos puntos no están definidos y afectan el resultado. Propongo un default para cada uno; el equipo debe aprobarlo o cambiarlo antes de Arch.

1. **Folio**: las tablas no tienen columna de folio. *Propuesta*: usar el id con prefijo, `T-000123` para trámites y `C-000123` para cobros. ¿Es correcto o prefieren otro formato?
2. **Permisos**: *Propuesta*: cualquier usuario autenticado de la notaría puede generar recibos, igual que los GET existentes. ¿Solo administradores?
3. **Trámite inactivo**: *Propuesta* (ya confirmada): se genera el PDF con marca de agua "CANCELADO". ¿Confirmado?
4. **Logo sin archivo**: si la notaría tiene `logo` registrado pero el archivo ya no existe en disco, *propuesta*: se omite el logo sin error (el recibo se genera igual).
5. **Fecha del trámite**: para el recibo se usa la fecha del trámite (`date`), no la fecha de creación del registro. ¿Correcto?

## Preguntas abiertas para Arch

- Librería de PDF (propuesta: Dompdf) y librería de QR. Ambas son dependencias nuevas.
- Conflicto de rutas: `GET /payment-transactions/receipt?uuid=` colisiona con `GET /payment-transactions/{id}`. Por eso propongo rutas por uuid en otra forma (ver `technical_context.md`).
