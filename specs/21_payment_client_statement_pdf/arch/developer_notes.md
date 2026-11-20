# Developer Notes: Estado de Cuenta de Cliente en PDF

## Contexto rápido

Feature **solo lectura**: un `GET` que devuelve en PDF el estado de cuenta que la spec 19 entrega en JSON. Sin migraciones y sin dependencias nuevas (Dompdf ya está por la spec 18). Se agrega al módulo `src/Module/PaymentStatement/` y se crea `src/Shared/Pdf/`.

**Decisiones del equipo (ya cerradas):**
- **Orden del libro:** `(fecha Y-m-d, tipo, id)`. Cargo antes que abono el mismo día. Cobros sin fecha al final.
- **Teléfono y correo:** segunda consulta con `PaymentClientRepository::findByIdAndNotary()`. **No** se toca `ClientStatement`, ni `StatementResponse`, ni el JSON de la spec 19.
- **Compartir con Receipt:** se extrae a `src/Shared/Pdf/` (`DompdfDocumentRenderer`, `LogoDataUriReader`). Receipt se migra **al final, aparte y opcional**, y solo si sus pruebas pasan sin cambios.
- **Errores:** mismo patrón que `ReceiptController` (cualquier `Throwable` → 500 JSON).
- **Volumen:** sin límite. El spike de Dompdf mide y documenta.

**Empieza por** el spike (Fase 0). Después `StatementLedgerBuilder` (Fase 2): es lógica pura, se prueba sin BD y de ella depende todo el libro.

**Regla de oro:** el PDF se genera completo en memoria antes de enviar el primer byte. Todo error es JSON, nunca un PDF parcial.

## Archivos clave (existentes)

| Archivo | Para qué |
|---------|----------|
| `src/Module/PaymentStatement/Service/PaymentStatementService.php` | `build(clientId, notaryId, StatementQuery): ?ClientStatement`. Totales en centavos y trámites cancelados ya excluidos. **No se modifica** |
| `src/Module/PaymentStatement/Entity/ClientStatement.php` | Entrada del libro: `transactions`, `payments`, `pendingAmount`, `totalCharges`, `totalPayments`, `balance`, `fromDate`, `toDate`. **No se modifica** |
| `src/Module/PaymentStatement/Entity/StatementTransaction.php` / `StatementPayment.php` | `date` es `Y-m-d` (el trámite siempre; el cobro es `?string`). El cobro trae `comment` (singular) |
| `src/Module/PaymentStatement/DTO/StatementQuery.php` | Validación de `from`/`to`. Se usa igual que en `show()` |
| `src/Module/PaymentStatement/Controller/PaymentStatementController.php` | Copia `parseId()` y `NOT_FOUND`. Se agrega `pdf()` y se inyectan dos servicios más |
| `src/Module/PaymentClient/Repository/PaymentClientRepository.php` | `findByIdAndNotary(int $id, int $notaryId): ?PaymentClient`. `PaymentClient` trae `fullName`, `phone`, `email`. No filtra por status |
| `src/Module/Receipt/Controller/ReceiptController.php` | Patrón de `respond()`: `try/catch \Throwable` → `error_log` + `Response::serverError()` |
| `src/Module/Receipt/Service/ReceiptService.php` | Referencia de `buildNotary()`, `logoDataUri()`, reloj inyectable y `warnIfInconsistent()` |
| `src/Module/Receipt/Renderer/ReceiptPdfRenderer.php` | **Origen** del código que pasa a `DompdfDocumentRenderer` (opciones, buffer, validación, footer) |
| `src/Module/Receipt/Renderer/ReceiptHtmlBuilder.php` | Patrón del closure con `extract` + `ob_start`; `escape()` y `orDash()` (estáticos, se reutilizan) |
| `src/Module/Receipt/Renderer/templates/receipt.php` | Origen del CSS (paleta, `.header`, `.section-title`, `.items`, `.words`) |
| `src/Module/Receipt/Service/MoneyFormatter.php` | `money()` → `$1,234.56`; `date()` → `dd/mm/yyyy` o fallback |
| `src/Module/Receipt/Service/AmountInWordsConverter.php` | `convert(float)`; lanza `InvalidArgumentException` si es < 0 o > `MAX_MONEY` |
| `src/Module/Receipt/Entity/ReceiptType.php` | `folio($id)` → `T-%06d` / `C-%06d` |
| `src/Module/Receipt/DTO/ReceiptNotary.php` / `ReceiptClient.php` | DTOs reutilizados para el encabezado y el cliente (ADR-007) |
| `src/Core/Http/Response.php` | `pdf($binary, $filename)` valida `^[A-Za-z0-9._-]+$`; `serverError()`, `notFound()`, `badRequest()` |
| `tests/Module/Receipt/Renderer/ReceiptPdfRendererTest.php` | Patrón de pruebas de humo: `%PDF-`, `%%EOF`, conteo de páginas, buffers restaurados |
| `src/Config/routes.php` / `src/bootstrap.php` | Una ruta; registros junto a `PaymentStatementService` (línea ~147) y `ReceiptPdfRenderer` (~194) |

## Código de referencia

### Ruta (`src/Config/routes.php`)
```php
// Same resource, three segments after it: no collision with /payment-clients/{id}/statement
$router->get('/api/v3/payment-clients/{id}/statement/pdf', [PaymentStatementController::class, 'pdf'])->withMiddleware('auth');
```

### Controller
```php
public function pdf(Request $request): Response
{
    $id = $this->parseId($request);
    if ($id instanceof Response) {
        return $id;
    }

    $query = StatementQuery::fromArray($request->getQueryParams());
    if (!$query->isValid()) {
        return Response::badRequest('Validation failed', $query->getErrors());
    }

    // The PDF is built completely before any header is sent: any failure is a JSON error, never a partial PDF
    try {
        $data = $this->pdfService->build($id, $request->getNotaryId(), $query);
        if ($data === null) {
            return Response::notFound(self::NOT_FOUND);
        }

        return Response::pdf($this->pdfRenderer->render($data), $data->fileName());
    } catch (\Throwable $e) {
        error_log('[statement] ' . $e::class . ': ' . $e->getMessage());

        return Response::serverError('Could not generate statement');
    }
}
```

### Registro (`src/bootstrap.php`)
```php
ServiceLocator::register(StatementLedgerBuilder::class, fn () => new StatementLedgerBuilder());

ServiceLocator::register(LogoDataUriReader::class, function () {
    return new LogoDataUriReader(ServiceLocator::get(NotaryService::class));
});

ServiceLocator::register(StatementPdfService::class, function () {
    return new StatementPdfService(
        ServiceLocator::get(PaymentStatementService::class),
        ServiceLocator::get(PaymentClientRepository::class),
        ServiceLocator::get(NotaryService::class),
        ServiceLocator::get(LogoDataUriReader::class),
        ServiceLocator::get(StatementLedgerBuilder::class),
        new AmountInWordsConverter()
    );
});

ServiceLocator::register(StatementPdfRenderer::class, function () {
    return new StatementPdfRenderer(
        new StatementHtmlBuilder(),
        new DompdfDocumentRenderer(__DIR__ . '/Module/PaymentStatement/Renderer/templates')
    );
});
```
`PaymentStatementService` ya está registrado: los nuevos van **después** de él y de `NotaryService`.

### Libro: orden y saldo corrido
```php
// Items: [date ?string, rank int (0 = charge, 1 = payment), id int, ...]
usort($items, static function (array $a, array $b): int {
    // Undated payments (only possible without filters) go last
    return [$a['date'] === null, $a['date'] ?? '', $a['rank'], $a['id']]
       <=> [$b['date'] === null, $b['date'] ?? '', $b['rank'], $b['id']];
});

$running = self::toCents($statement->pendingAmount);
foreach ($items as $item) {
    $running += $item['rank'] === 0 ? self::toCents($item['amount']) : -self::toCents($item['amount']);
    $rows[] = new StatementLedgerRow(/* ... */, balance: self::fromCents($running));
}
```
`<=>` entre arrays compara elemento por elemento. `false < true`, así que los fechados van primero. `toCents`/`fromCents` son los mismos del servicio (`(int) round($x * 100)` y `round($c / 100, 2)`).

### Leyenda y letra
```php
$status = BalanceStatus::fromBalance($statement->balance);
$words = match ($status) {
    BalanceStatus::Pending => $this->amountInWords->convert($statement->balance),
    BalanceStatus::InFavor => $this->amountInWords->convert(abs($statement->balance)),
    BalanceStatus::Settled => null,
};
```

### Footer (`DompdfDocumentRenderer`, movido de `ReceiptPdfRenderer`)
```php
$text = sprintf('Generado el %s by notarial.devrob.net - Pagina ', $generatedAt->format('d/m/Y H:i'));
$font = $dompdf->getFontMetrics()->getFont(self::FONT);

$dompdf->getCanvas()->page_script(
    static function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($text, $font): void {
        $line = $text . $pageNumber;
        $width = $fontMetrics->getTextWidth($line, $font, 8);
        $canvas->set_opacity(1.0);
        $canvas->text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 28, $line, $font, 8, [0.4, 0.4, 0.4]);
    }
);
```
`generatedAt` ya viene en `America/Mexico_City` desde el servicio.

### Tabla multipágina (CSS de la plantilla)
```css
.ledger { width: 100%; border-collapse: collapse; }
.ledger thead { display: table-header-group; }          /* header repeated on every page */
.ledger tr { page-break-inside: avoid; }                /* a row is never split */
.ledger td { padding: 5px 6px; border-bottom: 1px solid #e3e7ec; vertical-align: top; word-wrap: break-word; }
.num { text-align: right; }
.closing { page-break-inside: avoid; }                  /* summary + legend stay together (check in the spike) */
```

## Qué mostrar en cada columna

| Columna | Trámite | Cobro | Saldo anterior |
|---------|---------|-------|----------------|
| Fecha | `dd/mm/yyyy` | `dd/mm/yyyy` o `—` | vacía |
| Folio | `T-000123` | `C-000123` | vacío |
| Concepto | procedimiento ` – ` referencia, solo uno de los dos, o `—` | comentario o `Abono` | `Saldo anterior` |
| Cargo | `total` | vacío | vacío |
| Abono | vacío | `amount` | vacío |
| Saldo | corrido | corrido | `pendingAmount` |

Saldo negativo con signo `-` (`-$300.00`). En la leyenda "SALDO A FAVOR" va el valor absoluto.

## Cosas a vigilar

1. **No toques** `PaymentStatementService`, `ClientStatement`, `StatementResponse` ni `StatementRepository`. El JSON de la spec 19 debe quedar idéntico. Corre sus pruebas al final.
2. **No toques Receipt** en la feature. La Fase 8 es opcional y va en un commit aparte. Si una prueba de Receipt necesita cambios, se revierte.
3. **No recalcules totales** en el builder ni en el renderer. Resumen y saldo final salen de `ClientStatement`. El libro solo acumula para mostrar el saldo de cada fila.
4. **Centavos, nunca `float` sumado directo** en el libro. La comparación de cero en `BalanceStatus` también va en centavos (`-0.001` es SIN ADEUDO).
5. **404 antes de todo lo demás**: si `build()` devuelve `null`, no consultes cliente ni notaría. Ahí está la garantía multi-tenant del 404 idéntico (escenario 15).
6. **`notaryId` del token** en la segunda consulta del cliente y en `getByIdActive`. Nunca uses un id que venga de la URL.
7. **Escape en todo**: nombre, teléfono, correo, procedimiento, referencia, comentario y datos de notaría. Usa `H::escape()`/`H::orDash()`. Nada de `echo $x` sin escape en la plantilla.
8. **Logo**: solo data URI. Nunca pases una ruta de archivo al HTML. Un logo faltante se omite y se registra.
9. **Prefijo de logs**: `[statement]` en el feature. `LogoDataUriReader` no debe escribir `[receipt]`.
10. **Cliente inactivo se consulta**: no filtres por `id_status`.
11. **Trámites cancelados ya vienen excluidos** por el servicio: el PDF no agrega filas ni marca de agua.
12. **Cobros sin fecha**: solo existen sin filtros. Van al final con fecha `—` y suman al total (el servicio ya los incluyó).
13. **`from=` vacío** se trata como no enviado (lo resuelve `StatementQuery`). Parámetros desconocidos se ignoran.
14. **`AmountInWordsConverter` lanza** si el importe supera `MAX_MONEY`: es un 500 controlado, no un caso a manejar aparte.
15. **`ob_start()`** vive dentro de `DompdfDocumentRenderer`. No lo repitas en el controller ni en el servicio.
16. **Nombre de archivo**: `estado-de-cuenta-C-000005.pdf`. Solo `[A-Za-z0-9._-]`, o `Response::pdf()` lanza `InvalidArgumentException` (eso caería en el 500).
17. **`PaymentClientService` es `final`**: inyecta `PaymentClientRepository` (ADR-002).
18. **Orden por fecha, no por hora**: el SQL de la spec 19 ordena trámites por fecha y hora, y el libro por `(Y-m-d, tipo, id)`. El orden puede diferir del JSON dentro del mismo día. Es esperado y no afecta totales.
19. **Memoria**: Dompdf con muchas filas. Revisa los números del spike antes de dar la feature por buena.

## Puntos para devolver al PO / QA (no bloquean el desarrollo)

1. **Orden dentro del mismo día**: el libro ordena por `id` y no por hora, como pidió el PO. QA debe saber que el orden de filas puede diferir del JSON dentro del mismo día, sin afectar totales.
2. **Pruebas de integración**: el PO pide los 23 escenarios, y el proyecto no tiene infraestructura de BD en pruebas. Se cubren con unitarias y de humo más la verificación manual de QA (igual que las specs 18 y 19).
3. **Volumen**: no hay límite. Los números del spike se agregan abajo cuando existan.
4. **Segunda lectura del cliente**: una consulta por PK extra. Es una decisión consciente (ADR-002).
5. **Estimación**: ~13.5 h (ver plan), 1 h más que el PO por el spike con volumen. La Fase 8 (0.5 h) es opcional.

### Resultados del spike (Fase 0)

Medido en el contenedor `api` (PHP 8.3, Dompdf 3, DejaVu Sans, tabla de 6 columnas, 1 fila de cada 17 con texto largo sin espacios), con `memory_limit=-1` para medir el pico real.

| Filas | Tiempo | Memoria pico | Páginas | Observaciones |
|-------|--------|--------------|---------|---------------|
| 200 | 3.5 s | 56 MB | 8 | |
| 1,000 | 9.0 s | 292 MB | 36 | |
| 3,000 | 49.9 s | 1,702 MB | 108 | Memoria y tiempo crecen más que lineal |

Punto de quiebre (mismo script, sin `-1`):

| `memory_limit` | Filas que alcanzan | Primera que falla |
|----------------|--------------------|-------------------|
| 128M (default del contenedor) | 500 (122 MB) | 600 |
| 256M | 800 (216 MB) | 1,000 |

Costo aproximado: **0.25 MB por fila**, más ~25 MB base.

**El agotamiento de memoria es un `Fatal error`, no una excepción.** El `try/catch (\Throwable)` del controller no lo captura: la respuesta sería un 500 vacío de Apache/PHP en vez del JSON `Could not generate statement`. Como el PDF se arma entero en memoria antes de enviar, igualmente no sale un PDF parcial.

CSS validado (se usa tal cual en `templates/statement.php`):
- `thead { display: table-header-group }` repite el encabezado desde la página 2 (comprobado con `pdftotext` página por página).
- `tr { page-break-inside: avoid }` no parte filas; `word-wrap: break-word` ajusta el texto largo sin romper la tabla.
- `.closing { page-break-inside: avoid }` mantiene resumen y leyenda juntos: con 24 a 50 filas siempre quedaron completos en la última página. Cuando no caben, pasan enteros a una página nueva (con 27 a 30 filas esa última página solo trae el bloque final, sin encabezado de tabla).
- Footer con `page_script` en todas las páginas.

`memory_limit` mínimo recomendado: **128M alcanza hasta ~500 filas; 256M hasta ~800 filas; para 1,000 filas o más se necesitan 512M o más y decenas de segundos**. Umbral de aviso en el log: **400 filas** (`StatementPdfRenderer::ROW_WARNING_THRESHOLD`, ~100 MB, un 80 % del límite de 128M).

> Decisión pendiente para el PO/arquitecto (ADR-005): sin límite funcional, un cliente con más de ~500 movimientos rompe el PDF con el `memory_limit` por defecto. Opciones: subir `memory_limit`, limitar las filas con un 422 claro, o paginar por periodo.

## Comandos útiles

```bash
docker-compose up -d
docker-compose exec api composer test
docker-compose exec api vendor/bin/phpunit tests/Module/PaymentStatement
docker-compose exec api vendor/bin/phpunit tests/Shared/Pdf
docker-compose exec api vendor/bin/phpunit tests/Module/Receipt     # debe seguir en verde sin cambios

# Prueba manual (token con POST /api/v3/auth/login)
curl -H "Authorization: Bearer $TOKEN" -o estado.pdf "http://localhost:8080/api/v3/payment-clients/5/statement/pdf"
curl -H "Authorization: Bearer $TOKEN" -o estado.pdf "http://localhost:8080/api/v3/payment-clients/5/statement/pdf?from=2026-03-01&to=2026-03-31"
curl -i -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/5/statement/pdf?from=2026-02-31"   # 400 JSON
curl -i -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/abc/statement/pdf"                 # 400
curl -i -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/999/statement/pdf"                # 404
curl -i "http://localhost:8080/api/v3/payment-clients/5/statement/pdf"                                                    # 401

# Comparar contra el JSON de la spec 19 (mismo cliente y rango)
curl -H "Authorization: Bearer $TOKEN" "http://localhost:8080/api/v3/payment-clients/5/statement?from=2026-03-01&to=2026-03-31"

# Primer byte del PDF
head -c 5 estado.pdf    # %PDF-
```
