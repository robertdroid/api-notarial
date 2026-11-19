# Developer Notes: Recibos en PDF

## Contexto rápido

Feature **solo lectura**: 4 `GET` que devuelven un PDF (`application/pdf`) de un trámite o de un cobro. Sin migraciones, sin repositories nuevos. Módulo nuevo `src/Module/Receipt/`.

**Decisiones del equipo (ya cerradas):**
- Errores igual que los GET existentes (ADR-007): no uses `Invalid id` / `Payment transaction not found` del PO.
- Defaults del PO aprobados: folio `T-000123` / `C-000123`, cualquier usuario autenticado de la notaría, trámite inactivo con marca `CANCELADO`, logo faltante se omite con log, fecha = fecha del trámite.

**Empieza por la Fase 0 (spike)** del plan: valida Dompdf, JPEG, QR, marca de agua y footer en el contenedor antes de escribir el módulo.

## Archivos clave (existentes, solo lectura salvo indicación)

| Archivo | Para qué |
|---------|----------|
| `src/Core/Http/Router.php` | Regex `^…$` con `[^/]+`; no hay colisión entre las 4 rutas. **Modificar no hace falta.** |
| `src/Core/Http/Response.php` | **Modificar**: agregar `pdf()`. `send()` ya hace `echo` de un body `string` |
| `public/index.php` | Pone `Content-Type: application/json` por defecto; `Response::send()` lo reemplaza. Cualquier `Throwable` no capturado sale como 500 genérico `Internal Server Error` (por eso el controller captura y responde `Could not generate receipt`) |
| `src/Config/routes.php` | **Modificar**: 4 rutas (líneas ~90–100 son las de transacciones/cobros) |
| `src/bootstrap.php` | **Modificar**: registrar servicios nuevos en `ServiceLocator` |
| `src/Module/Payment/Controller/PaymentController.php` | Patrón a copiar: `parseId()`, `errorResponse()`, uso de `ServiceLocator::get()` en el constructor |
| `src/Module/PaymentTransaction/Service/PaymentTransactionService.php` | `getById(int, int)` / `getByUuid(string, int)` → `PaymentTransaction` o lanza `TransactionException` |
| `src/Module/Payment/Service/PaymentService.php` | Idem para `Payment` (`PaymentException`) |
| `src/Module/PaymentClient/Service/PaymentClientService.php` | `getById(int $id, int $notaryId): ?PaymentClient` → teléfono y correo |
| `src/Module/Notary/Service/NotaryService.php` | `getByIdActive()` y `getLogoInfo()` (devuelve `path`, `mimeType`, `size` o `null` si no hay logo o el archivo no existe) |
| `src/Shared/Uuid/ClientUuid.php` | `validate($v): ?string` (regex `^[A-Za-z0-9_-]{1,20}$`) |
| `src/Module/Payment/DTO/PaymentFieldRules.php` | `MAX_MONEY = 999999999.99`: tope del conversor |
| `tests/Module/Payment/Service/PaymentServiceTest.php` | Patrón de mocks para `ReceiptServiceTest` |

## Datos disponibles en las Entities (no hace falta consultar más)

- `PaymentTransaction`: `id`, `uuid`, `clientName`, `procedureName`, `date` (`Y-m-d`), `fee`, `taxes`, `total`, `reference`, `responsible`, `comments`, `statusId`, `details[]`. `clientName`/`procedureName` vienen vacíos (`''`) si el LEFT JOIN no encontró fila.
- `PaymentTransactionDetail`: `taxName`, `quantity`, `amount`, `date`, `comments` (ya ordenados por nombre de impuesto).
- `Payment`: `id`, `uuid`, `clientName`, `amount`, `paymentDate` (`?string`), `comments`.
- `PaymentClient`: `fullName`, `phone`, `email`. Se necesita `getById(clientId, notaryId)` porque las entities de trámite/cobro solo traen el nombre.
- `Notary`: `name`, `notaryName`, `city`, `address`, `phone`, `rfc`, `logo`. Tabla real: **`notarias`** (el PO escribió `notaria`).

## Código de referencia

### Rutas (`src/Config/routes.php`)
```php
use App\Module\Receipt\Controller\ReceiptController;

// Receipts (protected). The uuid routes have an extra segment, so they never collide with /{id}/receipt
$router->get('/api/v3/payment-transactions/{id}/receipt', [ReceiptController::class, 'transaction'])->withMiddleware('auth');
$router->get('/api/v3/payment-transactions/uuid/{uuid}/receipt', [ReceiptController::class, 'transactionByUuid'])->withMiddleware('auth');
$router->get('/api/v3/payments/{id}/receipt', [ReceiptController::class, 'payment'])->withMiddleware('auth');
$router->get('/api/v3/payments/uuid/{uuid}/receipt', [ReceiptController::class, 'paymentByUuid'])->withMiddleware('auth');
```

### `Response::pdf()`
```php
public static function pdf(string $binary, string $filename): self
{
    if (preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1) {
        throw new \InvalidArgumentException('Invalid file name');
    }

    return new self($binary, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Length' => (string) strlen($binary),
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ]);
}
```

### Controller (esqueleto)
```php
public function transaction(Request $request): Response
{
    $id = $this->parseId($request, TransactionError::TransactionNotFound->message());
    if ($id instanceof Response) {
        return $id;
    }

    return $this->respond(fn () => $this->service->buildForTransactionId($id, $request->getNotaryId()));
}

/** @param \Closure(): ReceiptData $build */
private function respond(\Closure $build): Response
{
    try {
        $data = $build();
        $pdf = $this->renderer->render($data);

        return Response::pdf($pdf, $data->fileName());
    } catch (TransactionException | PaymentException $e) {
        return Response::notFound($e->error->message());
    } catch (\Throwable $e) {
        error_log('[receipt] ' . $e::class . ': ' . $e->getMessage());

        return Response::serverError('Could not generate receipt');
    }
}
```
Si el `error` de ambas excepciones no comparte tipo, resuélvelo con un `match` por clase de excepción.

### Dompdf: opciones y footer
```php
$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);            // NO habilitar: el footer va por page_script
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('tempDir', sys_get_temp_dir());
$options->set('fontCache', sys_get_temp_dir() . '/dompdf-fonts');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->setPaper('letter', 'portrait');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();

$footer = sprintf('Generado el %s by notarial.devrob.net - Pagina ', $data->generatedAt->format('d/m/Y H:i'));
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
$canvas->page_script(function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($footer, $font): void {
    $text = $footer . $pageNumber;
    $size = 8;
    $width = $fontMetrics->getTextWidth($text, $font, $size);
    $canvas->text(($canvas->get_width() - $width) / 2, $canvas->get_height() - 28, $text, $font, $size, [0.4, 0.4, 0.4]);
});

$bytes = $dompdf->output();
```
`generatedAt` se construye con `new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'))` (el Service, vía reloj inyectable). Confirma en el spike la firma exacta del callback de `page_script` en la versión instalada.

### Convertidor de importes (pasos)
1. `$cents = (int) round($amount * 100);`
2. `$pesos = intdiv($cents, 100); $cc = $cents % 100;`
3. Convertir `$pesos` por grupos de 3 dígitos (millones, miles, unidades) con tablas: unidades 0–29 (incluye `VEINTIUNO…VEINTINUEVE`), decenas, centenas (`CIEN` solo si es exactamente 100; si no `CIENTO`).
4. Apócopes: `UNO → UN` delante de `MIL`/`MILLÓN`/`PESOS`; `VEINTIUNO → VEINTIÚN` en las mismas posiciones; `MIL` sin `UN` delante (`1000` → `MIL`); `1 000 000` → `UN MILLÓN`, `2 000 000` → `DOS MILLONES`.
5. Resultado: `"{LETRA} PESOS " . sprintf('%02d', $cc) . '/100 M.N.'`. Con `1` peso: `UN PESO 00/100 M.N.` (singular).

### Registro de servicios (`bootstrap.php`)
```php
ServiceLocator::register(ReceiptService::class, function () {
    return new ReceiptService(
        ServiceLocator::get(PaymentTransactionService::class),
        ServiceLocator::get(PaymentService::class),
        ServiceLocator::get(PaymentClientService::class),
        ServiceLocator::get(NotaryService::class),
        new AmountInWordsConverter(),
    );
});
ServiceLocator::register(ReceiptPdfRenderer::class, fn () => new ReceiptPdfRenderer(new ReceiptHtmlBuilder(), new QrCodeGenerator()));
```
Verifica que `PaymentClientService` y `PaymentTransactionService` ya estén registrados con esos nombres de clase antes de usarlos.

## Cosas a vigilar

1. **Escapar todo**: nombre de cliente, procedimiento, referencia, responsable, comentarios, nombre de impuesto, datos de notaría. Un solo `echo` sin `htmlspecialchars` es un hallazgo de seguridad.
2. **No habilitar `isPhpEnabled`** aunque la documentación de Dompdf lo sugiera para el footer. Se resuelve con `page_script`.
3. **No pasar rutas de archivo al HTML.** El logo se lee con `getLogoInfo()['path']`, se codifica en base64 y se inserta como `data:`; el mime sale de `getLogoInfo()['mimeType']`.
4. **PDF solo al final**: no hagas `echo` ni `header()` dentro del módulo; devuelve `Response`.
5. **Zona horaria**: `date_default_timezone_set` ya se hace en `index.php`, pero el footer debe usar `DateTimeZone('America/Mexico_City')` explícito para no depender de `.env`.
6. **Float**: nunca compares con `==`; usa tolerancia `0.005`. Para el conversor, siempre centavos enteros.
7. **Memoria**: Dompdf se come memoria con tablas grandes. Mide en el spike con 40 filas.
8. **`failOnWarning`/`beStrictAboutOutputDuringTests`** en `phpunit.xml.dist`: un `E_DEPRECATED` de Dompdf en las pruebas del renderer rompe la suite. Si pasa, fija la versión y silencia solo dentro del renderer (con `ob_start()`), no en `phpunit.xml.dist`.
9. **Router**: `/payment-transactions/uuid//receipt` (uuid vacío) no coincide con ninguna ruta y responde el 404 `Route not found…` del Router. Es lo esperado; ver ADR-007.

## Puntos para devolver al PO / QA (no bloquean el desarrollo)

1. **"UN MILLÓN PESOS"**: la ortografía correcta es **"UN MILLÓN DE PESOS 00/100 M.N."** (se añade "DE" a partir de millones exactos). El criterio del PO dice literalmente `UN MILLÓN PESOS`. Se implementa **literal al PO** hasta que lo confirme; es un cambio de una línea en el conversor. Mismo caso con `2,000,000` y `1,000,000,000` (fuera de rango).
2. **Escenario 19 (uuid vacío)**: devuelve 404 de ruta, no 400 (ver arriba).
3. **Escenarios 16, 18, 19**: se ajustan al contrato existente (ADR-007).
4. **Pruebas de integración**: el PO las pide, pero el proyecto no tiene infraestructura de integración. Se cubren con pruebas unitarias + verificación manual de QA.
5. **Tabla real de notarías**: `notarias`, no `notaria` como dice `technical_context.md` (el repository ya usa la correcta).
6. **Cliente o procedimiento "ya no existe"**: el PO dice "se genera con el nombre guardado", pero no hay nombre guardado en el trámite; se muestra `—`.
7. **Estimación**: ~15.5 h (ver plan), incluye el spike y los cambios de Dockerfile y `Response`.

## Comandos útiles

```bash
docker-compose exec api composer require dompdf/dompdf:^3.0 chillerlan/php-qrcode:^5.0
docker-compose exec api php -m | grep -Ei "dom|mbstring|gd"
docker-compose exec api php -r "print_r(gd_info());"      # JPEG Support debe ser true tras reconstruir
docker-compose build api && docker-compose up -d
docker-compose exec api composer test
```
