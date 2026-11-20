# Estado de Cuenta de Cliente en PDF (Payments)

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** descargar el estado de cuenta de un cliente en PDF, con sus cargos (trámites), abonos (cobros) y saldo, opcionalmente acotado por rango de fechas
**Para** entregarle al cliente un documento formal y fácil de entender que muestre cuánto ha cargado, cuánto ha pagado y cuánto debe

## Contexto

La spec 19 entregó el estado de cuenta en JSON (`GET /api/v3/payment-clients/{id}/statement`). La spec 18 entregó recibos en PDF de trámites y cobros. Esta spec une ambas: genera el PDF del estado de cuenta, que en v1 se conocía como `report/statement` y en v3 queda fuera de alcance de la spec 19 (ver `19_payment_client_statement/po/story.md`).

Hoy el cliente solo puede recibir recibos sueltos. No hay un documento único que muestre el historial y el saldo. Este feature **no modifica datos**: es una operación de solo lectura.

### Reutilización, no duplicación
El PDF consume `PaymentStatementService` **dentro del mismo proceso** (sin llamada HTTP a la propia API). Así los datos, el saldo y la exclusión de trámites cancelados son idénticos al JSON de la spec 19. El renderer reutiliza el patrón del módulo Receipt (Dompdf, encabezado de notaría con logo, footer con paginación e importe con letra).

### Decisiones de negocio

| Tema | Decisión |
|------|----------|
| Ruta | `GET /api/v3/payment-clients/{id}/statement/pdf?from=&to=` |
| Formato de movimientos | Libro cronológico con saldo corrido: una sola tabla que mezcla cargos y abonos por fecha |
| Trámites cancelados (estatus 2) | Se excluyen del PDF (sin fila ni marca de agua), igual que en el JSON |
| Cliente inactivo | Se puede consultar. El 404 aplica solo si no existe o es de otra notaría |
| Sin movimientos en el rango | Se genera el PDF igual, con "Sin movimientos en el periodo" y el saldo |
| Orden en un mismo día | El cargo va antes que el abono |
| Cobros sin fecha | Solo aparecen sin filtros de fecha, al final y con fecha "—" |
| Permisos | Cualquier usuario autenticado de la notaría |
| Volumen | Sin paginación ni límite de movimientos. Si hay muchas filas, la tabla continúa en la página siguiente |
| Extras | Importe con letra del saldo y leyenda según saldo. Sin código QR |

### Diseño del documento
Carta, vertical, con el mismo estilo sobrio de los recibos:

1. **Encabezado**: logo de la notaría (si tiene), nombre de la notaría, notario titular, RFC, dirección y teléfono.
2. **Título**: "ESTADO DE CUENTA", con el periodo y la fecha de emisión.
   - Con filtros: `Del dd/mm/yyyy al dd/mm/yyyy`. Con solo `from`: `Desde dd/mm/yyyy`. Con solo `to`: `Hasta dd/mm/yyyy`.
   - Sin filtros: "Todos los movimientos".
3. **Cliente**: nombre, teléfono y correo.
4. **Libro de movimientos** (tabla): Fecha, Folio, Concepto, Cargo, Abono y Saldo.
   - Primera fila "Saldo anterior" con el saldo previo al `from`. Solo aparece cuando hay `from`.
   - Trámites: Folio `T-000123`, Concepto = procedimiento y referencia. Cargo = total del trámite.
   - Cobros: Folio `C-000123`, Concepto = comentario del cobro (o "Abono" si no tiene). Abono = monto.
   - Saldo = saldo anterior + cargos − abonos acumulados hasta esa fila.
   - El encabezado de la tabla se repite en cada página.
5. **Resumen**: saldo anterior, total de cargos, total de abonos y **saldo final**.
6. **Importe con letra y leyenda del saldo**:
   - Saldo positivo: "SALDO PENDIENTE".
   - Saldo negativo: "SALDO A FAVOR" (la notaría debe al cliente), con el importe en valor absoluto.
   - Saldo cero: "SIN ADEUDO".
7. **Footer en cada página**: `Generado el dd/mm/yyyy HH:MM by notarial.devrob.net - Pagina n`, en `America/Mexico_City`, igual que los recibos.

## Alcance

### Incluido
- `GET /api/v3/payment-clients/{id}/statement/pdf` con filtros opcionales `from` y `to`
- Respuesta `application/pdf`, visible en el navegador (`inline`)
- Mismas validaciones y errores 400 que el JSON (spec 19)
- Libro cronológico con saldo corrido, resumen, importe con letra y leyenda de saldo
- Encabezado con datos y logo de la notaría, footer con paginación
- Aislamiento multi-tenant
- Documentación en `docs/openapi.yaml` y README

### Excluido
- Guardar, archivar o cachear los PDFs generados
- Envío por correo o WhatsApp
- Código QR, firmas o sellos
- Plantillas configurables por notaría
- Marca de agua o filas para trámites cancelados
- Otras monedas (solo pesos mexicanos)
- Paginación de datos o límite de movimientos
- Migrar el frontend a la nueva URL
- Eliminar o deprecar `GET /api/v1/payment/client-statement` y `report/statement` de v1
- Cambios de esquema o migraciones

## Usuarios Afectados

- **Empleados de notaría**: generan e imprimen el estado de cuenta.
- **Clientes finales**: reciben el documento (no acceden directamente a la API).

## Valor de Negocio

- **Documento formal**: un solo PDF con historial y saldo, en vez de recibos sueltos.
- **Saldo legible**: la leyenda y el importe con letra hacen el saldo entendible sin interpretar signos.
- **Consistencia**: los números del PDF son los mismos que los del JSON de la spec 19.
- **Sin riesgo sobre datos**: operación de solo lectura.

## Preguntas abiertas para Arch

- Cómo fusionar y ordenar trámites y cobros en el libro, calculando el saldo corrido sin recalcular los totales. Los valores del servicio se muestran tal cual.
- Si el encabezado y footer se comparten con Receipt (componente común) o se duplican, sin cambiar el comportamiento de los recibos.
- Forma de exponer los movimientos a un consumidor de PDF sin cambiar el contrato JSON de la spec 19.
- Memoria y tiempo de Dompdf con clientes de muchos movimientos (hoy no hay casos de miles).
- Nombre del archivo, por ejemplo `estado-de-cuenta-{clientId}.pdf`, solo con caracteres seguros para `Content-Disposition`.
