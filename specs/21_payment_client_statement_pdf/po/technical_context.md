# Contexto Técnico

## Entidades Involucradas

Solo lectura. Esta spec no requiere migraciones.

### Datos del estado de cuenta (ya existen, spec 19)
`PaymentStatementService::build(int $clientId, int $notaryId, StatementQuery $query): ?ClientStatement`

`ClientStatement` ya trae todo lo necesario:
- `clientId`, `clientName`, `fromDate`, `toDate`
- `transactions[]` (`StatementTransaction`): `id`, `uuid`, `date`, `procedureName`, `reference`, `fee`, `taxes`, `total`
- `payments[]` (`StatementPayment`): `id`, `uuid`, `date` (nullable), `comment`, `amount`
- `pendingAmount`, `totalCharges`, `totalPayments`, `balance`

Los trámites cancelados ya vienen excluidos y los montos ya están redondeados y calculados en centavos. El PDF **no recalcula** nada de esto.

### Cliente (teléfono y correo): `payments_clients`
`ClientStatement` solo trae `clientId` y `clientName`. Para teléfono y correo hace falta el cliente completo: `PaymentClientService::getById()` (como hace `ReceiptService`) o el cliente que el servicio ya carga. Arch decide cómo evitar leerlo dos veces.

### Notaría: `notaria`
Datos de encabezado y logo vía `NotaryService` (`getByIdActive`, `getLogoInfo`), igual que `ReceiptService::buildNotary()`. El logo se incrusta como data URI y se omite si falta o no se puede leer.

## Endpoint

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v3/payment-clients/{id}/statement/pdf?from=YYYY-MM-DD&to=YYYY-MM-DD` | Estado de cuenta del cliente en PDF |

Protegido con middleware `auth`.

### Conflictos de ruta
Ya existe `/api/v3/payment-clients/{id}/statement` (dos segmentos tras el recurso). `/statement/pdf` tiene tres y no hay ninguna ruta con ese patrón. No hay colisión.

### Respuestas
| Caso | Status | Body |
|------|--------|------|
| Éxito | 200 | PDF binario. `Content-Type: application/pdf`, `Content-Disposition: inline; filename="..."`, `Cache-Control: no-store` |
| `{id}` no numérico | 400 | `{ "error": "Invalid ID format" }` |
| `{id}` ≤ 0, inexistente o de otra notaría | 404 | `{ "error": "Client not found" }` |
| `from` o `to` inválidos, o `from` > `to` | 400 | `{ "error": "Validation failed", "errors": { ... } }` |
| Sin token o token inválido | 401 | Middleware `auth` |
| Falla al armar o renderizar el PDF | 500 | `{ "error": "Could not generate statement" }` |

Los errores usan los helpers de `Response`. `Response::pdf()` ya existe y valida el nombre de archivo.

## Reglas del Libro (nuevas en esta spec)

El libro es una presentación del mismo cálculo que ya hace el servicio. No cambia ningún total.

1. Se fusionan `transactions` (cargos) y `payments` (abonos) en una sola lista.
2. Orden: fecha ascendente. Mismo día: cargo antes que abono. Mismo tipo y fecha: `id` ascendente. Cobros sin fecha (solo existen sin filtros): al final, ordenados por `id`.
3. Saldo corrido: empieza en `pendingAmount`. Cada cargo suma, cada abono resta.
4. El saldo de la última fila debe ser igual a `balance`. Si no lo es, es un defecto (ver Notas).
5. Se calcula en centavos enteros, igual que `PaymentStatementService`.
6. Primera fila "Saldo anterior": solo si hay `from`. Su saldo es `pendingAmount`.

### Columnas
| Columna | Trámite | Cobro |
|---------|---------|-------|
| Fecha | `dd/mm/yyyy` | `dd/mm/yyyy` o "—" |
| Folio | `T-000123` | `C-000123` |
| Concepto | `procedureName` + `reference`, o "—" | `comment`, o "Abono" si está vacío |
| Cargo | `total` | vacío |
| Abono | vacío | `amount` |
| Saldo | corrido | corrido |

El folio usa el mismo formato que los recibos (`T-%06d`, `C-%06d`). Ver `ReceiptType::folio()`.

### Leyenda y importe con letra
| Saldo final | Leyenda | Importe |
|-------------|---------|---------|
| > 0 | SALDO PENDIENTE | `balance` con letra |
| < 0 | SALDO A FAVOR | valor absoluto con letra |
| = 0 | SIN ADEUDO | sin importe con letra |

Se reutiliza `AmountInWordsConverter` del módulo Receipt.

## Dependencias

### Módulos existentes (reutilizar)
- **Auth**: JWT con `notaryId` (`Request::getNotaryId()`)
- **PaymentStatement**: `PaymentStatementService`, `StatementQuery` (validación de `from`/`to`), `ClientStatement`
- **PaymentClient**: teléfono y correo del cliente
- **Notary**: datos y logo de la notaría
- **Receipt**: `AmountInWordsConverter`, patrón de `ReceiptPdfRenderer` (Dompdf, opciones de seguridad, footer con `page_script`), `ReceiptType::folio()`
- **Core**: `Response::pdf()`, `Response::badRequest()`, `Response::notFound()`, `ServiceLocator`

### Librerías
Dompdf ya está instalado por la spec 18. No se agregan dependencias nuevas.

### Ubicación (propuesta, Arch decide)
Dentro de `src/Module/PaymentStatement/`:
```
├── Controller/PaymentStatementController.php   (nuevo método pdf)
├── Service/StatementPdfService.php             (arma los datos del PDF: notaría, cliente, libro)
├── DTO/StatementPdfData.php                    (datos listos para renderizar)
├── Renderer/StatementPdfRenderer.php           (HTML + Dompdf + footer)
├── Renderer/StatementHtmlBuilder.php
├── Renderer/templates/statement.php
```
Arch evalúa si el encabezado y footer se comparten con Receipt, sin cambiar el comportamiento de los recibos.

## Impacto en Sistema Existente

### Archivos a modificar
- `src/Config/routes.php`: una ruta nueva
- `src/bootstrap.php`: registro de los servicios nuevos en el contenedor
- `docs/openapi.yaml`: documentar el endpoint, parámetros, respuestas y el content-type `application/pdf`
- `README.md`: sección del endpoint, con el formato de las demás secciones de pagos
- `PaymentStatementController`: método nuevo para el PDF

### Sin cambios en
- Migraciones de base de datos
- El JSON de la spec 19 (contrato y comportamiento)
- El módulo Receipt (comportamiento de los recibos)
- API v1 y su frontend

## Consideraciones de Datos

- **Montos**: `double` en BD, redondeados a 2 decimales por el servicio. El PDF los formatea como `$1,234.56`.
- **Fechas**: `Y-m-d` en el servicio. El PDF las muestra como `dd/mm/yyyy`.
- **Multi-tenant**: todas las consultas filtran por `notary_id`, incluidos los datos de la notaría.
- **Consistencia**: se usa lo que entrega el servicio. Si el saldo corrido final no coincide con `balance`, se registra con `error_log` y no se corrige en silencio (patrón de spec 18).

## Notas para Implementación

1. **Orden de operaciones**: validar `{id}` (400/404) → validar `from`/`to` (400) → construir el estado (404 si el cliente no existe) → armar datos del PDF (notaría, cliente, libro) → renderizar → responder. Todo en memoria antes de enviar el primer encabezado.
2. **PDF completo o error**: igual que los recibos. Cualquier falla es JSON 500, nunca un PDF parcial. Se captura el buffer de salida durante el render.
3. **Escape de texto**: nombre del cliente, referencia, procedimiento y comentarios se escapan antes de entrar al HTML.
4. **Tabla multipágina**: encabezado de columnas repetido (`thead` con `display: table-header-group`), filas sin partir (`page-break-inside: avoid`) y resumen y leyenda al final sin cortarse.
5. **Colores y estilo**: reutilizar la paleta sobria de los recibos. Cargos y abonos distinguibles también en impresión en blanco y negro (no depender solo del color).
6. **Nombre del archivo**: solo caracteres seguros, por ejemplo `estado-de-cuenta-C-000005.pdf`, con el id del cliente.
7. **Pruebas**:
   - Unitarias: armado del libro (orden, desempate, saldo corrido, cobro sin fecha), leyenda de saldo, textos de periodo y escape de HTML.
   - Renderer: PDF válido (`%PDF-`), con y sin logo, sin movimientos y con muchos movimientos.
   - Integración: los 23 escenarios de `acceptance_criteria.md`. Énfasis en la coincidencia con el JSON de la spec 19 (1, 2, 6), multi-tenant (15) y la falla sin PDF parcial (23).

## Estimación

- **Complejidad**: Media. Los datos ya existen. El esfuerzo está en el libro cronológico y en el layout multipágina.
- **Riesgo**: Bajo. Principal riesgo: que el saldo corrido no cuadre con `balance` por orden o redondeo, y el aspecto de la tabla con muchas filas.

| Tarea | Estimación |
|-------|------------|
| Armado del libro y saldo corrido | 2 horas |
| Datos del PDF (notaría, cliente, leyendas) | 1.5 horas |
| Renderer, template y footer | 3 horas |
| Controller, ruta y errores | 1 hora |
| Pruebas unitarias e integración | 3 horas |
| OpenAPI y README | 1 hora |
| Revisión visual del PDF (pocas y muchas filas) | 1 hora |
| **Total** | **~12.5 horas** |
