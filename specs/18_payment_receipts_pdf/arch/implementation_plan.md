# Plan de Implementación: Recibos en PDF (Trámites y Cobros)

## Resumen

Módulo nuevo `Receipt`, de solo lectura. Reutiliza los Services existentes (`PaymentTransactionService`, `PaymentService`, `PaymentClientService`, `NotaryService`) para cargar datos filtrados por `notary_id`, arma un `ReceiptData` inmutable y lo renderiza a PDF con **Dompdf** (HTML/CSS → PDF en memoria). El controller responde `application/pdf` o JSON de error. No hay migraciones ni repositories nuevos.

Decisiones ya tomadas con el equipo:
- **Contrato de errores**: igual que los GET existentes (ver ADR-007). Los escenarios 16, 18 y 19 del PO se ajustan.
- **Puntos por confirmar del PO**: los 5 defaults quedan aprobados (folio `T-000123`/`C-000123`, cualquier usuario autenticado de la notaría, trámite inactivo con marca `CANCELADO`, logo faltante se omite con log, fecha = fecha del trámite).

## Arquitectura Propuesta

```
GET /api/v3/payment-transactions/{id}/receipt            ─┐
GET /api/v3/payment-transactions/uuid/{uuid}/receipt      │ AuthMiddleware (JWT → notaryId)
GET /api/v3/payments/{id}/receipt                         │
GET /api/v3/payments/uuid/{uuid}/receipt                 ─┘
        │
        ▼
ReceiptController            valida id/uuid → 400, mapea excepciones → 404/500
        │
        ▼
ReceiptService               arma ReceiptData (sin HTML, sin Dompdf)
   ├─ PaymentTransactionService::getById/getByUuid   (404 si no existe / otra notaría)
   ├─ PaymentService::getById/getByUuid              (404 si no existe / otra notaría)
   ├─ PaymentClientService::getById                  (teléfono y correo; null → "—")
   ├─ NotaryService::getByIdActive + getLogoInfo     (encabezado y logo; sin archivo → omitir + log)
   └─ AmountInWordsConverter                         (importe con letra)
        │  ReceiptData (DTO inmutable)
        ▼
ReceiptPdfRenderer           ReceiptData → string binario PDF
   ├─ ReceiptHtmlBuilder     plantilla PHP + htmlspecialchars en TODO valor
   ├─ QrCodeGenerator        uuid → data URI PNG
   └─ Dompdf                 render + footer con paginación (page_script)
        │  string PDF (en memoria, nunca a disco)
        ▼
Response::pdf($bytes, $filename)    200 + headers
```

Regla de oro: el PDF se genera **completo en memoria** antes de enviar el primer byte. Si algo falla, JSON 500; nunca un PDF parcial.

## Módulos Afectados

- [x] `Module/Receipt/` — **nuevo** (Controller, Service, Renderer, DTO, Exception)
- [x] `Core/Http/Response.php` — agregar factory `pdf()`
- [x] `Config/routes.php` — 4 rutas
- [x] `bootstrap.php` — registrar servicios en `ServiceLocator`
- [x] `composer.json` / `composer.lock` — Dompdf y librería QR
- [x] `docker/Dockerfile` — soporte JPEG en `gd` (ver ADR-005)
- [x] `docs/openapi.yaml` — 4 rutas con respuesta `application/pdf`
- [ ] `Module/PaymentTransaction`, `Module/Payment`, `Module/Notary`, `Module/PaymentClient` — **sin cambios** (solo se consumen sus Services)

## Estructura final

```
src/Module/Receipt/
├── Controller/ReceiptController.php
├── DTO/
│   ├── ReceiptData.php              (type, folio, fecha, uuid, notaría, cliente, líneas, total, letra, cancelado, generatedAt)
│   ├── ReceiptNotary.php            (nombre, titular, rfc, dirección, ciudad, teléfono, logoDataUri?)
│   ├── ReceiptClient.php            (nombre, teléfono, correo)
│   └── ReceiptTaxLine.php           (cantidad, concepto, importe)
├── Entity/ReceiptType.php           (enum Transaction | Payment: título, prefijo de folio, slug de archivo)
├── Exception/ReceiptGenerationException.php
├── Renderer/
│   ├── ReceiptPdfRenderer.php
│   ├── ReceiptHtmlBuilder.php
│   ├── QrCodeGenerator.php
│   └── templates/receipt.php        (una plantilla con secciones por tipo; un solo CSS)
└── Service/
    ├── ReceiptService.php
    ├── AmountInWordsConverter.php
    └── MoneyFormatter.php           (formato $1,350.00 y dd/mm/yyyy, independiente de locale)
```

## Tareas de Implementación

### Fase 0: Spike de renderizado (riesgo técnico primero)
Estimación: 1.5 horas

#### 0.1 Validar Dompdf en el contenedor
- **Archivo(s):** script desechable en el scratchpad (no se commitea)
- **Tipo:** Investigación
- **Descripción:** `docker-compose exec api composer require dompdf/dompdf:^3.0 chillerlan/php-qrcode:^5.0` y generar un PDF de prueba que valide, en este orden: (1) glifos `Iñaki Ángeles Ñúñez`, `–`, `—` con **DejaVu Sans** (viene con Dompdf, no hace falta agregar TTF); (2) logo PNG **y JPG** como data URI; (3) QR PNG vía GD; (4) marca de agua `position: fixed` + `rotate` + `opacity` repetida en 3 páginas; (5) footer con `page_script` y `{PAGE_NUM}` sin habilitar `isPhpEnabled`; (6) tabla de 40 filas con `thead { display: table-header-group }` repitiendo cabecera; (7) tiempo y memoria pico.
- **Dependencias:** ninguna
- **Salida:** confirmar o ajustar ADR-002, ADR-003, ADR-005 y ADR-006 antes de seguir. Si la marca de agua CSS falla, usar `page_script` con `set_opacity()` y `text()` rotado.

### Fase 1: Dependencias e infraestructura
Estimación: 1 hora

#### 1.1 Dependencias y Dockerfile
- **Archivo(s):** `composer.json`, `composer.lock`, `docker/Dockerfile`
- **Tipo:** Modificar
- **Descripción:** Fijar `dompdf/dompdf ^3.0` y `chillerlan/php-qrcode ^5.0` (ajustar a lo que valide el spike). En el Dockerfile agregar `libjpeg-dev` y `docker-php-ext-configure gd --with-jpeg` antes de `docker-php-ext-install gd`. Reconstruir imagen.
- **Dependencias:** 0.1

#### 1.2 `Response::pdf()`
- **Archivo(s):** `src/Core/Http/Response.php`
- **Tipo:** Modificar
- **Descripción:** `public static function pdf(string $binary, string $filename): self` con `Content-Type: application/pdf`, `Content-Length`, `Content-Disposition: inline; filename="…"`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. El body es string, `send()` ya lo imprime con `echo`. Validar que `$filename` solo contenga `[A-Za-z0-9._-]` (lo arma el servidor, pero se protege el header).
- **Dependencias:** ninguna

### Fase 2: Lógica pura (sin Dompdf)
Estimación: 3.5 horas

#### 2.1 AmountInWordsConverter
- **Archivo(s):** `src/Module/Receipt/Service/AmountInWordsConverter.php`
- **Tipo:** Crear
- **Descripción:** `convert(float $amount): string` → `MIL DOSCIENTOS PESOS 00/100 M.N.`. Trabajar en centavos enteros: `$cents = (int) round($amount * 100)` (evita errores de coma flotante), `pesos = intdiv($cents, 100)`, `cc = $cents % 100`. Reglas: `CIEN`/`CIENTO`, `VEINTIÚN` antes de sustantivo, apócope `UN` (`UN PESO`, `VEINTIÚN MIL`), `MIL` sin `UN` delante, `UN MILLÓN`/`DOS MILLONES`, `CERO PESOS 00/100 M.N.`. Rango soportado: 0 a 999,999,999.99 (= `MAX_MONEY`); fuera de rango lanza `\InvalidArgumentException`.
- **Dependencias:** ninguna

#### 2.2 MoneyFormatter
- **Archivo(s):** `src/Module/Receipt/Service/MoneyFormatter.php`
- **Tipo:** Crear
- **Descripción:** `money(float): string` → `$1,350.00` con `number_format($v, 2, '.', ',')` (no depende de `setlocale`). `date(?string $ymd): string` → `dd/mm/yyyy` o `Sin fecha`/`—` según el llamador.
- **Dependencias:** ninguna

#### 2.3 DTOs y `ReceiptType`
- **Archivo(s):** `src/Module/Receipt/DTO/*.php`, `src/Module/Receipt/Entity/ReceiptType.php`
- **Tipo:** Crear
- **Descripción:** Clases `final readonly`-style (propiedades `public readonly`, como el resto del proyecto). `ReceiptData` ya trae todo formateado como valores de dominio (no HTML). Sin lógica de presentación salvo `folio()` y `fileName()`.
- **Dependencias:** ninguna

#### 2.4 ReceiptService
- **Archivo(s):** `src/Module/Receipt/Service/ReceiptService.php`
- **Tipo:** Crear
- **Descripción:** `buildForTransactionId/Uuid(…, int $notaryId): ReceiptData` y `buildForPaymentId/Uuid(…)`. Orden: cargar registro (404 vía excepciones existentes) → cliente (`null` → "—") → notaría (`null` → `ReceiptGenerationException`, se loguea) → logo (`getLogoInfo`; si `hasLogo()` pero devuelve `null`, `error_log` de aviso y se omite) → armar modelo. Logo a data URI base64. Reloj inyectable (`\Closure $clock`) para que `generatedAt` sea testeable y siempre en `America/Mexico_City`. Si `abs(total - (fee + taxes)) > 0.005` o `taxes` ≠ suma de detalles: `error_log` como hallazgo y se muestra el valor guardado (no se recalcula). `cancelled = ($tx->statusId !== 1)`; en cobros siempre `false`.
- **Dependencias:** 2.1, 2.2, 2.3

### Fase 3: Renderer
Estimación: 4 horas

#### 3.1 QrCodeGenerator
- **Archivo(s):** `src/Module/Receipt/Renderer/QrCodeGenerator.php`
- **Tipo:** Crear
- **Descripción:** `dataUri(string $uuid): string`. Contenido = uuid tal cual, sin URL.
- **Dependencias:** 1.1

#### 3.2 Plantilla y HtmlBuilder
- **Archivo(s):** `src/Module/Receipt/Renderer/ReceiptHtmlBuilder.php`, `templates/receipt.php`
- **Tipo:** Crear
- **Descripción:** HTML/CSS tamaño carta vertical, fuente `DejaVu Sans`. Secciones: encabezado (logo solo si existe, sin hueco), título + folio + fecha + QR, cliente, detalle (trámite: procedimiento, referencia, responsable, cuota, tabla de impuestos solo si hay filas, impuestos totales, total; cobro: monto, fecha o `Sin fecha`, comentarios), importe con letra. Toda cadena pasa por `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`; vacíos → `—` o cadena vacía, nunca `null`. Tabla con `thead { display: table-header-group }` y `tr { page-break-inside: avoid }`. Marca de agua solo si `cancelled`.
- **Dependencias:** 2.3

#### 3.3 ReceiptPdfRenderer
- **Archivo(s):** `src/Module/Receipt/Renderer/ReceiptPdfRenderer.php`
- **Tipo:** Crear
- **Descripción:** `render(ReceiptData): string`. Opciones Dompdf: `isRemoteEnabled=false`, `isPhpEnabled=false`, `isFontSubsettingEnabled=true`, `defaultFont='DejaVu Sans'`, `chroot` restringido, papel `letter` portrait. Después de `render()`, footer con `$canvas->page_script(callable)` usando `{PAGE_NUM}` real: `Generado el {dd/mm/yyyy HH:MM} by notarial.devrob.net - Pagina {n}` con `generatedAt`. `ob_start()` alrededor de todo para que ningún warning contamine el binario; todo `Throwable` se envuelve en `ReceiptGenerationException`. Valida que el resultado empiece con `%PDF-` y no esté vacío.
- **Dependencias:** 3.1, 3.2

### Fase 4: HTTP
Estimación: 1.5 horas

#### 4.1 ReceiptController
- **Archivo(s):** `src/Module/Receipt/Controller/ReceiptController.php`
- **Tipo:** Crear
- **Descripción:** Cuatro métodos: `transaction`, `transactionByUuid`, `payment`, `paymentByUuid`. Validación idéntica a los controllers existentes (`parseId`: no entero → 400 `Invalid ID format`, `<= 0` → 404 con el mensaje del recurso; uuid con `ClientUuid::validate` → 400 `Invalid uuid format`). Captura `TransactionException`/`PaymentException` → 404 con `$e->error->message()`; cualquier otro `Throwable` → `error_log` + 500 `{"error":"Could not generate receipt"}`.
- **Dependencias:** 2.4, 3.3, 1.2

#### 4.2 Rutas y registro de servicios
- **Archivo(s):** `src/Config/routes.php`, `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Agregar después de las rutas actuales de cada recurso, todas con `->withMiddleware('auth')`. Registrar `AmountInWordsConverter`, `QrCodeGenerator`, `ReceiptPdfRenderer`, `ReceiptService` en `ServiceLocator`. Verificado contra `Router.php`: el regex es `^…$` con `[^/]+`, así que `/payment-transactions/{id}/receipt` (3 segmentos) y `/payment-transactions/uuid/{uuid}/receipt` (4 segmentos) no compiten entre sí ni con `/payment-transactions/{id}`. No dependen del orden de registro.
- **Dependencias:** 4.1

### Fase 5: Pruebas
Estimación: 3 horas

#### 5.1 Pruebas unitarias
- **Archivo(s):** `tests/Module/Receipt/**`
- **Tipo:** Crear
- **Descripción:** `AmountInWordsConverterTest` (0, 1, 16, 21, 100, 101, 1000, 1200, 21000, 1,000,000, 2,000,000, 5000.50, 0.99, 999,999,999.99, errores de flotante como 0.1+0.2, fuera de rango). `MoneyFormatterTest`. `ReceiptServiceTest` con mocks al estilo de `PaymentServiceTest`: trámite activo/inactivo, cobro sin fecha ni comentarios, cliente borrado, logo faltante, total inconsistente, `generatedAt` con reloj fijo en `America/Mexico_City`. `ReceiptHtmlBuilderTest`: escapado de `<script>`/`<img>` en nombres, ausencia de la cadena `null`, tabla de impuestos omitida si no hay filas, marca de agua solo si `cancelled`. `ReceiptPdfRendererTest` (humo): empieza con `%PDF-`, termina con `%%EOF`, 40 impuestos producen más de una página. `ResponsePdfTest`.
- **Dependencias:** 2.x, 3.x

#### 5.2 Verificación manual E2E (QA)
- **Descripción:** El proyecto **no tiene infraestructura de pruebas de integración HTTP/BD** (solo la suite `Unit`), por lo que los escenarios de rutas (200/400/401/404, multi-tenant) y la revisión visual (acentos, marca de agua, footer, QR escaneable, paginación) los cubre QA con la API levantada en Docker. Ver `technical_acceptance_criteria.md`.

### Fase 6: Documentación
Estimación: 45 min

#### 6.1 OpenAPI
- **Archivo(s):** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Cuatro rutas, respuesta `200` con `content: application/pdf: schema: {type: string, format: binary}`, headers `Content-Disposition` y `Cache-Control`, errores 400/401/404/500 con el esquema de error existente.
- **Dependencias:** 4.2

## Orden de Implementación

1. **0.1** Spike (decide ADRs y valida el entorno)
2. 1.1 Dependencias + Dockerfile · 1.2 `Response::pdf()`
3. 2.1 Conversor de importes (+ sus pruebas) · 2.2 `MoneyFormatter` · 2.3 DTOs
4. 2.4 `ReceiptService` (+ pruebas)
5. 3.1 QR · 3.2 Plantilla/HtmlBuilder · 3.3 Renderer (+ pruebas)
6. 4.1 Controller · 4.2 Rutas y bootstrap
7. 5.1 Pruebas restantes · 6.1 OpenAPI
8. Entrega a QA (5.2)

**Estimación total: ~15.5 horas** (los ~15 del PO + 1.5 del spike − ahorro de no agregar TTF propio, + Dockerfile y `Response::pdf`).

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| `gd` sin JPEG: logos `.jpg` (permitidos por `NotaryService`) no se renderizan | Alta | Medio | Dockerfile con `--with-jpeg`; el spike prueba PNG y JPG; si aun así falla, se omite logo con log (no rompe el recibo) |
| Dompdf no repite el footer/marca de agua en todas las páginas | Media | Alto | `page_script` para el footer (se ejecuta por página); marca de agua `position: fixed`; el spike lo valida con 3 páginas |
| Caracteres fuera de DejaVu (emoji, alfabetos no latinos) se ven como `?` | Baja | Bajo | Subsetting + DejaVu cubre latín extendido; documentado como límite conocido |
| Uso de memoria/tiempo en recibos con muchas filas | Media | Medio | Objetivo medible (ver criterios técnicos); si excede, simplificar CSS de tabla; no se admite paginar el PDF por streaming (se genera completo) |
| Warnings/deprecations de Dompdf ensucian la salida o rompen PHPUnit (`failOnWarning`) | Media | Medio | `ob_start()` en el renderer; fijar versión de Dompdf; en pruebas del renderer fallar solo ante excepciones |
| HTML injection vía nombre de cliente/comentarios | Media | Medio | `htmlspecialchars` en todo valor + `isRemoteEnabled=false` + `isPhpEnabled=false` + prueba dedicada |
| Escritura de caché de fuentes en `vendor/` sin permisos en producción | Media | Medio | `fontCache`/`tempDir` a `sys_get_temp_dir()`; verificar con `www-data` en el spike |
| Endpoint costoso sin rate limit (CPU por PDF) | Baja | Medio | Requiere JWT; fuera de alcance agregar rate limiting, se deja registrado como deuda |
| Valores guardados inconsistentes (`total ≠ fee + taxes`) | Baja | Bajo | Se muestra lo guardado y se loguea el hallazgo (decisión del PO) |

## Decisiones de Arquitectura

### ADR-001: Módulo `Receipt` separado, consumiendo Services de otros módulos
- **Contexto:** El recibo cruza cuatro dominios (trámite, cobro, cliente, notaría) y no pertenece a ninguno.
- **Decisión:** Módulo propio `Receipt` que depende de los **Services** de los demás (`PaymentTransactionService`, `PaymentService`, `PaymentClientService`, `NotaryService`), no de sus repositories. Nota: `PaymentService` sí inyecta `PaymentClientRepository`; aquí se prefiere el Service porque ya devuelve la Entity filtrada por notaría. Reutiliza el 404 multi-tenant y los mensajes de los Services existentes.
- **Consecuencias:** Cero cambios en los módulos origen y sin queries duplicadas. A cambio, `Receipt` se acopla a las firmas públicas de esos Services.

### ADR-002: Dompdf 3.x
- **Contexto:** Se necesita PDF con diseño HTML/CSS, UTF-8 y paginación. PHP puro, sin framework.
- **Decisión:** `dompdf/dompdf ^3.0`. Compatible con PHP 8.3 y con `php >= 8.2`. Requiere `ext-dom` y `ext-mbstring`: `dom` viene en `php:8.3-apache` y `mbstring` ya se instala en el Dockerfile.
- **Consecuencias:** Más pesado que FPDF/TCPDF en memoria, pero el diseño sobrio se mantiene en CSS en lugar de coordenadas manuales. No se habilita `isPhpEnabled` ni `isRemoteEnabled`.

### ADR-003: Fuente DejaVu Sans incluida en Dompdf (sin TTF propio)
- **Contexto:** El PO pide incluir una TTF para acentos y ñ.
- **Decisión:** Usar `DejaVu Sans`, que Dompdf ya trae con soporte Unicode y que cubre latín extendido, `–` y `—`. No se agrega ninguna fuente al repositorio. Se activa subsetting para reducir el tamaño del PDF.
- **Consecuencias:** Una dependencia menos que mantener. Si la identidad visual exige otra tipografía, se agrega después con `FontMetrics::registerFont`.

### ADR-004: QR con `chillerlan/php-qrcode` en PNG por data URI
- **Contexto:** Hay que elegir entre `endroid/qr-code` y `chillerlan/php-qrcode`. SVG depende de `php-svg-lib` dentro de Dompdf, menos predecible.
- **Decisión:** `chillerlan/php-qrcode ^5.0`, salida PNG con GD (ya instalado) incrustada como `data:image/png;base64,…`. Contenido = uuid tal cual.
- **Consecuencias:** Sin archivos temporales ni acceso remoto. Depende de `gd` con PNG, que ya existe.

### ADR-005: Imágenes (logo y QR) como data URI y `gd` con JPEG
- **Contexto:** `NotaryService` permite logos JPG y PNG y los guarda en `public/assets/{id}/logo/`. El `gd` del Dockerfile solo trae PNG (`libpng-dev`). Dompdf con `chroot` y sin remoto no debe leer rutas arbitrarias.
- **Decisión:** Leer el logo con `NotaryService::getLogoInfo()` (que ya valida que el archivo exista) y pasarlo al HTML como data URI. Agregar `libjpeg-dev` + `--with-jpeg` al Dockerfile. Nunca se pasa una ruta de archivo al HTML.
- **Consecuencias:** Requiere reconstruir la imagen. El PDF no expone rutas internas (criterio de seguridad del PO). Un logo corrupto se omite con aviso en log.

### ADR-006: Footer y paginación con `page_script`, marca de agua con CSS fijo
- **Contexto:** El footer debe llevar número de página real y la fecha de generación en cada página. `<script type="text/php">` exige `isPhpEnabled=true`, que ejecuta PHP embebido en el HTML y es un riesgo si algún dato se cuela sin escapar.
- **Decisión:** Footer con `$dompdf->getCanvas()->page_script(callable)` después del render (usa `{PAGE_NUM}` real). Marca de agua con un elemento `position: fixed` (se repite por página) con `transform: rotate` y `opacity`. El spike decide si se cambia a `page_script`.
- **Consecuencias:** `isPhpEnabled` queda en `false`. La fecha del footer es la de `generatedAt` (mismo valor para todas las páginas del mismo PDF).

### ADR-007: Contrato de errores igual al de los GET existentes
- **Contexto:** El PO escribió `Invalid id`/`Invalid uuid` (400 también para `0`) y `Payment transaction not found`. Los controllers actuales responden 400 `Invalid ID format`, 404 para `id <= 0`, 400 `Invalid uuid format` y 404 `Transaction not found`/`Payment not found`.
- **Decisión:** Los recibos **reutilizan exactamente** el contrato existente (aprobado por el equipo). Se actualizan los escenarios 16, 18 y 19 en QA.
- **Consecuencias:** Consistencia para el frontend. Un `uuid` vacío (`/uuid//receipt`) no coincide con ninguna ruta (`[^/]+`) y devuelve el 404 de ruta no encontrada del Router, no un 400. Es un efecto conocido del router, no un defecto de este feature.

### ADR-008: Generación síncrona, en memoria y sin caché
- **Contexto:** El PO excluye guardar o cachear PDFs y exige no devolver PDFs parciales.
- **Decisión:** `$dompdf->output()` completo → `string` → `Response::pdf()`. `Cache-Control: no-store`. Todo error durante la generación se convierte en JSON 500 antes de enviar headers de PDF.
- **Consecuencias:** El pico de memoria equivale al tamaño del PDF más el de Dompdf. Se medirá en el spike para fijar el objetivo.

### ADR-009: Folio derivado del id, formateado en el Entity de tipo
- **Contexto:** Las tablas no tienen columna de folio.
- **Decisión:** `ReceiptType::folio(int $id)` → `T-%06d` / `C-%06d`. Si el id supera 999,999 se imprime completo (sin truncar).
- **Consecuencias:** Sin migración. Si más adelante se quiere un folio consecutivo independiente, el cambio queda aislado en un solo método.
