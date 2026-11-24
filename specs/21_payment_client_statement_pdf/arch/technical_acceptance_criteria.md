# Criterios de Aceptación Técnicos: Estado de Cuenta de Cliente en PDF

## Controller Layer (`PaymentStatementController::pdf`)
- [ ] `GET /api/v3/payment-clients/{id}/statement/pdf` registrado con `->withMiddleware('auth')`, junto a la ruta de `statement`
- [ ] Sin token o token inválido: 401 (middleware)
- [ ] `{id}` no numérico: 400 `{ "error": "Invalid ID format" }`
- [ ] `{id}` ≤ 0: 404 `{ "error": "Client not found" }`
- [ ] `from`/`to` inválidos o `from` > `to`: 400 `{ "error": "Validation failed", "errors": {...} }` con `StatementQuery` (la misma clase que el JSON). No se genera PDF
- [ ] Orden: `parseId` → `StatementQuery` → armado → render → `Response::pdf()`
- [ ] Cliente inexistente o de otra notaría: 404 `Client not found`, con el mismo mensaje en ambos casos
- [ ] Éxito: 200 con `Content-Type: application/pdf`, `Content-Disposition: inline; filename="estado-de-cuenta-C-000005.pdf"`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` (todo de `Response::pdf()`)
- [ ] Cualquier `Throwable` en armado o render: `error_log('[statement] ...')` y 500 `{ "error": "Could not generate statement" }`
- [ ] Nunca se envía un encabezado de PDF ni un PDF parcial ante una falla
- [ ] `show()` (JSON de la spec 19) no cambia de comportamiento ni de firma
- [ ] El controller no contiene lógica de negocio ni de presentación

## Service Layer

### `StatementPdfService`
- [ ] `build(int $clientId, int $notaryId, StatementQuery $query): ?StatementPdfData`
- [ ] Obtiene los movimientos y totales **solo** de `PaymentStatementService::build()`. No hay consultas propias de movimientos
- [ ] Si `build()` devuelve `null`, devuelve `null` sin consultar notaría ni cliente
- [ ] Teléfono y correo vía `PaymentClientRepository::findByIdAndNotary($id, $notaryId)` (ADR-002). No inyecta `PaymentClientService` (es `final`)
- [ ] Cliente `null` en esa segunda lectura: usa el nombre del estado de cuenta, teléfono y correo vacíos
- [ ] Notaría vía `NotaryService::getByIdActive($notaryId)`; si es `null`, `error_log` y `StatementGenerationException`
- [ ] Logo vía `LogoDataUriReader`; un logo faltante o ilegible se omite y el PDF se genera igual
- [ ] No se filtra por `id_status` del cliente (el cliente inactivo se consulta)
- [ ] Importe con letra: saldo > 0 → `convert(balance)`; saldo < 0 → `convert(abs(balance))`; saldo 0 → `null`
- [ ] Leyenda con `BalanceStatus::fromBalance()`, comparando en centavos enteros
- [ ] `generatedAt` con reloj inyectable, en `America/Mexico_City`
- [ ] Si el saldo de la última fila ≠ `balance` (tolerancia 0.005): `error_log` y se muestra el valor del servicio sin corregirlo
- [ ] No escribe en BD ni abre transacciones
- [ ] Los totales, el saldo anterior y el saldo final del PDF son los de `ClientStatement`. No se recalculan

### `StatementLedgerBuilder`
- [ ] Clase pura: sin BD, sin reloj, sin I/O
- [ ] Fusiona `transactions` (cargos) y `payments` (abonos) en una lista
- [ ] Orden: fecha Y-m-d ascendente → cargo antes que abono → `id` ascendente. Cobros sin fecha al final, por `id`
- [ ] El orden es total y determinista (comparación completa en `usort`)
- [ ] Saldo corrido en centavos enteros: empieza en `pendingAmount`, cada cargo suma y cada abono resta
- [ ] Primera fila `Opening` ("Saldo anterior") solo si `fromDate !== null`, con saldo `pendingAmount`
- [ ] Folio con `ReceiptType::folio()`: `T-000123` / `C-000123`
- [ ] Concepto de trámite: procedimiento y referencia unidos por ` – `; si falta uno, el otro; si faltan ambos, `—`
- [ ] Concepto de cobro: comentario, o `Abono` si está vacío
- [ ] El saldo de la última fila es igual a `balance` del servicio
- [ ] No calcula `totalCharges`, `totalPayments` ni `balance`

### `BalanceStatus`
- [ ] `> 0` → `SALDO PENDIENTE`, `< 0` → `SALDO A FAVOR`, `0` → `SIN ADEUDO`, evaluado en centavos (`-0.001` es cero)

## Repository Layer
- [ ] **Sin repositories nuevos ni cambios en `StatementRepository`**
- [ ] Todas las lecturas del feature filtran por `notary_id` del token: movimientos y saldo anterior (en `PaymentStatementService`), cliente (`findByIdAndNotary`) y notaría (`getByIdActive($notaryId)`)
- [ ] Sin `INSERT`, `UPDATE`, `DELETE` ni transacciones
- [ ] Sin migraciones

## Entity/DTO
- [ ] `StatementPdfData`, `StatementLedgerRow`: propiedades `public readonly` tipadas, sin lógica de presentación salvo `periodLabel()` y `fileName()`
- [ ] `LedgerRowKind` y `BalanceStatus`: enums respaldados
- [ ] `periodLabel()`: `Del dd/mm/yyyy al dd/mm/yyyy` | `Desde dd/mm/yyyy` | `Hasta dd/mm/yyyy` | `Todos los movimientos`
- [ ] `fileName()` cumple `^[A-Za-z0-9._-]+$` (lo valida `Response::pdf()`)
- [ ] Se reutilizan `ReceiptNotary` y `ReceiptClient` sin modificarlos (ADR-007)
- [ ] `ClientStatement`, `StatementResponse` y el contrato JSON de la spec 19 **sin cambios**
- [ ] Nunca se expone una Entity en la respuesta: el PDF sale de DTOs

## Renderer
- [ ] `StatementPdfRenderer::render(StatementPdfData): string`, completo en memoria
- [ ] `DompdfDocumentRenderer` (`Shared/Pdf`): `isRemoteEnabled=false`, `isPhpEnabled=false`, `isFontSubsettingEnabled=true`, `defaultFont='DejaVu Sans'`, `tempDir` y `fontCache` en `sys_get_temp_dir()`, `chroot` restringido, papel `letter` portrait
- [ ] `ob_start()` al inicio y limpieza de niveles en `finally`: ninguna salida de Dompdf llega al cuerpo de la respuesta
- [ ] Valida que el resultado sea un string que empiece con `%PDF-`; si no, `PdfRenderException`
- [ ] Footer en **todas** las páginas: `Generado el dd/mm/yyyy HH:MM by notarial.devrob.net - Pagina n` (`America/Mexico_City`, `n` desde 1), con `page_script`, sin `isPhpEnabled`
- [ ] El HTML solo incrusta data URIs. No hay rutas de archivo ni URLs remotas
- [ ] **Todo** valor que llega a la plantilla pasa por `escape()`/`orDash()` (nombre, teléfono, correo, procedimiento, referencia, comentario, datos de notaría)
- [ ] Tipografía DejaVu Sans (acentos y ñ)
- [ ] Montos `$1,234.56` con `MoneyFormatter::money()`; fechas `dd/mm/yyyy` con `MoneyFormatter::date()`. Sin dependencia de `setlocale`
- [ ] Tabla: `thead { display: table-header-group }` (encabezado repetido), `tr { page-break-inside: avoid }`, texto largo ajustado en la celda
- [ ] Resumen y leyenda de saldo al final, sin cortarse entre páginas
- [ ] Saldo negativo con signo `-` y cargos y abonos en columnas distintas: se distinguen en blanco y negro
- [ ] Sin movimientos: fila "Sin movimientos en el periodo", y el saldo se sigue mostrando
- [ ] Saldo a favor: "SALDO A FAVOR" con el importe en valor absoluto, y nunca solo un número negativo
- [ ] Sin código QR, marca de agua ni filas de trámites cancelados

## Tests

### Unitarias
- [ ] `StatementLedgerBuilderTest`: orden, desempate por `id`, cargo antes que abono, cobros sin fecha al final, saldo corrido (1,000 → 600 → 800), "Saldo anterior" solo con `from`, saldo negativo, saldo final = `balance`, sin deriva en centavos
- [ ] `BalanceStatusTest`: positivo, negativo, cero y `-0.001`
- [ ] `StatementPdfDataTest`: las 4 variantes de `periodLabel()` y `fileName()` seguro
- [ ] `StatementPdfServiceTest`: cliente inexistente → `null`; notaría inactiva → excepción; sin teléfono ni correo; cliente borrado entre lecturas; importe con letra (pendiente, a favor, cero); reloj fijo; logo faltante; saldo inconsistente solo se registra; ninguna consulta cuando el cliente no existe
- [ ] `StatementHtmlBuilderTest`: escape de `<script>` e `<img>` en nombre, referencia y comentario; sin la cadena `null`; "Sin movimientos en el periodo"; concepto sin procedimiento; cobro sin comentario → "Abono"; cobro sin fecha → "—"
- [ ] `StatementPdfRendererTest` (humo): `%PDF-` y `%%EOF`; con y sin logo; sin movimientos; 150 filas dan más de una página; texto largo; falla de render envuelta, sin fuga de salida y con buffers restaurados
- [ ] `DompdfDocumentRendererTest`: PDF válido, falla envuelta en `PdfRenderException`, buffers restaurados
- [ ] `docker-compose exec api composer test` en verde, incluidas **todas** las pruebas de `Receipt` y de `PaymentStatement` (JSON) sin modificar

### Verificación manual (QA, API en Docker)
- [ ] Los 23 escenarios de `po/acceptance_criteria.md`
- [ ] Coincidencia de `totalCharges`, `totalPayments`, `balance` y `pendingAmount` con el JSON de la spec 19 en los escenarios 1, 2 y 6
- [ ] Multi-tenant (escenario 15): el cliente de otra notaría da el mismo 404 que uno inexistente
- [ ] Falla de generación (escenario 23): JSON 500, sin PDF parcial ni encabezados de PDF
- [ ] Revisión visual: acentos, logo, varias páginas, encabezado repetido, footer, saldo a favor, sin adeudo, impresión en blanco y negro
- [ ] Verificar que ninguna tabla cambia: sin `INSERT`/`UPDATE` durante las pruebas

## Código
- [ ] PSR-12, `declare(strict_types=1)` en todos los archivos
- [ ] Propiedades tipadas; clases `final` salvo las que las pruebas deban extender (`DompdfDocumentRenderer`, si hace falta)
- [ ] SOLID: Controller delega; Service arma datos; Renderer solo presenta; el libro es una función pura
- [ ] Sin cambios en el módulo Receipt dentro de la feature (la Fase 8 es opcional y va en un commit aparte)

## Performance
- [ ] Sin N+1: el armado hace las consultas de la spec 19 más una lectura del cliente y una de la notaría
- [ ] El spike (Fase 0) documenta tiempo y memoria con 200, 1,000 y 3,000 filas
- [ ] `memory_limit` mínimo documentado en `developer_notes.md`
- [ ] `error_log` de aviso al superar el umbral de filas definido por el spike
- [ ] Sin paginación ni límite de movimientos (decisión del PO)

## Seguridad
- [ ] JWT requerido (`auth`)
- [ ] Multi-tenant: `notary_id` del token en cliente, movimientos, saldo anterior y notaría
- [ ] No se revela si un cliente existe en otra notaría
- [ ] Texto de usuario escapado, sin acceso remoto y sin PHP en el HTML
- [ ] `Content-Disposition` solo con caracteres seguros
- [ ] `Cache-Control: no-store`
- [ ] Solo lectura
