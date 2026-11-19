# Criterios de Aceptación Técnicos: Recibos en PDF

## Controller Layer (`ReceiptController`)
- [ ] 4 endpoints `GET`, todos con `->withMiddleware('auth')`:
  - `/api/v3/payment-transactions/{id}/receipt`
  - `/api/v3/payment-transactions/uuid/{uuid}/receipt`
  - `/api/v3/payments/{id}/receipt`
  - `/api/v3/payments/uuid/{uuid}/receipt`
- [ ] `id`: `filter_var(..., FILTER_VALIDATE_INT)`; no entero → **400** `Invalid ID format`; `<= 0` → **404** con el mensaje del recurso (igual que `PaymentController::parseId`)
- [ ] `uuid`: `ClientUuid::validate()` (1–20 caracteres `A-Za-z0-9_-`, sin trim ni cambio de mayúsculas) → **400** `Invalid uuid format`
- [ ] 404 trámite: `Transaction not found`; 404 cobro: `Payment not found` (mensajes de `TransactionError`/`PaymentError`, no se duplican)
- [ ] 500 con `{"error":"Could not generate receipt"}` ante cualquier fallo de render o de armado; el detalle va a `error_log`, nunca al cliente
- [ ] El controller no contiene HTML, ni importes, ni lógica de folio
- [ ] Respuesta 200 solo cuando el PDF completo ya existe en memoria
- [ ] Headers del 200: `Content-Type: application/pdf`, `Content-Disposition: inline; filename="recibo-tramite-T-000025.pdf"` (o `recibo-cobro-C-000010.pdf`), `Content-Length` exacto, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`
- [ ] Sin token → 401 por `AuthMiddleware`, sin llegar al servicio
- [ ] Ninguna ruta nueva modifica o sombrea las existentes (`/payment-transactions/{id}`, `/payments/{id}`, `?uuid=`)

## Service Layer (`ReceiptService`, `AmountInWordsConverter`, `MoneyFormatter`)
- [ ] Carga el registro con los Services existentes, que ya filtran por `notaryId` del JWT; un registro de otra notaría es indistinguible de uno inexistente (404, nunca 403)
- [ ] Cliente borrado o inexistente → teléfono, correo y nombre en `—`, sin excepción
- [ ] Procedimiento borrado → `—`
- [ ] Notaría no encontrada o inactiva → `ReceiptGenerationException` (500), con log
- [ ] Logo: `hasLogo()` pero `getLogoInfo()` devuelve `null` → se omite y se registra aviso en log; el recibo se genera
- [ ] Logo se entrega como data URI; ninguna ruta de archivo llega al HTML ni al PDF
- [ ] `cancelled = statusId !== 1` solo para trámites; los cobros son siempre `false`
- [ ] Footer: `generatedAt` en `America/Mexico_City` (zona explícita, no depende del default de PHP); formato `dd/mm/yyyy HH:MM` en 24 h
- [ ] Reloj inyectable para pruebas
- [ ] Importes redondeados a 2 decimales antes de formatear; `total` mostrado = valor guardado (no se recalcula)
- [ ] Si `abs(total − (fee + taxes)) > 0.005` o `taxes` ≠ suma de detalles: `error_log` como hallazgo, el recibo muestra lo guardado
- [ ] `AmountInWordsConverter`: trabaja en centavos enteros (`(int) round($amount * 100)`); `1200.00` → `MIL DOSCIENTOS PESOS 00/100 M.N.`; `5000.50` → `CINCO MIL PESOS 50/100 M.N.`; `1000000.00` → `UN MILLÓN PESOS 00/100 M.N.` (literal del PO, ver nota en `developer_notes.md`); `0` → `CERO PESOS 00/100 M.N.`; fuera de 0–999,999,999.99 → `InvalidArgumentException`
- [ ] `MoneyFormatter`: `$1,350.00` independiente de `setlocale`; fecha `dd/mm/yyyy`
- [ ] Sin escrituras en BD: ningún método del módulo ejecuta `INSERT/UPDATE/DELETE` ni abre transacción
- [ ] Sin escrituras de PDF en disco

## Renderer Layer (`ReceiptPdfRenderer`, `ReceiptHtmlBuilder`, `QrCodeGenerator`)
- [ ] Dompdf configurado con `isPhpEnabled = false`, `isRemoteEnabled = false`, `chroot` restringido, `defaultFont = DejaVu Sans`, subsetting activo, papel `letter` vertical
- [ ] **Todo** valor dinámico pasa por `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
- [ ] Ningún valor llega al HTML como `null`/`undefined`; campos opcionales vacíos se muestran como `—` o vacío según el criterio del PO (`Sin fecha` en cobros)
- [ ] Encabezado: logo solo si existe (sin hueco), nombre de notaría, notario titular, RFC, dirección y ciudad, teléfono
- [ ] Título `RECIBO DE TRÁMITE` / `RECIBO DE COBRO`, folio (`T-000025` / `C-000010`), fecha dd/mm/yyyy, QR
- [ ] QR: contenido exacto = uuid, sin URL ni prefijo
- [ ] Trámite: procedimiento, referencia, responsable, cuota, tabla de impuestos (cantidad, concepto, importe) **solo si hay filas**, impuestos totales, total
- [ ] Cobro: monto, fecha de pago (o `Sin fecha`), comentarios (vacío, sin la palabra `null`)
- [ ] Importe con letra consistente con el total mostrado
- [ ] Marca de agua `CANCELADO` en cada página solo si `cancelled`; jamás en cobros
- [ ] Footer en cada página, texto exacto: `Generado el dd/mm/yyyy HH:MM by notarial.devrob.net - Pagina n` con `n` real desde 1
- [ ] Tabla larga: continúa en la página siguiente, repite el encabezado de la tabla, no parte una fila entre páginas
- [ ] La salida empieza con `%PDF-`; si el render falla o la salida está vacía → `ReceiptGenerationException`
- [ ] `ob_start()`/`ob_end_clean()` envolviendo el render; ningún warning llega al cuerpo de la respuesta
- [ ] Todo `Throwable` interno se convierte en `ReceiptGenerationException`

## Repository Layer
- [ ] **No se crean repositories nuevos.** Se reutilizan `PaymentTransactionRepository`, `PaymentRepository`, `PaymentClientRepository`, `NotaryRepository` a través de sus Services
- [ ] Todas las consultas existentes filtran por `id_notary` (verificado: `HEADER_SQL` con `JOIN … AND c.id_notary = t.id_notary`)
- [ ] `uuid` se compara tal cual (`utf8mb3_bin`, sensible a mayúsculas) y usa el índice único `(id_notary, uuid)`

## Entity/DTO
- [ ] `ReceiptData`, `ReceiptNotary`, `ReceiptClient`, `ReceiptTaxLine`: propiedades tipadas `public readonly`
- [ ] `ReceiptType` enum con `title()`, `folio(int $id)`, `fileName(int $id)`
- [ ] Las Entities de dominio (`PaymentTransaction`, `Payment`, `Notary`) no salen del Service: el Renderer solo conoce `ReceiptData`
- [ ] El PDF no incluye datos internos: ni ids de notaría, ni rutas de archivo, ni tokens ni contraseñas

## Core / Infraestructura
- [ ] `Response::pdf(string $binary, string $filename): Response` agregado, con validación del nombre de archivo
- [ ] `composer.json`/`composer.lock` con `dompdf/dompdf ^3.0` y `chillerlan/php-qrcode ^5.0` (versiones finales según spike)
- [ ] Dockerfile: `gd` con JPEG; imagen reconstruida; `dom` y `mbstring` presentes (`php -m`)
- [ ] `fontCache`/`tempDir` en una ruta escribible por `www-data`
- [ ] Servicios registrados en `bootstrap.php`

## Tests
- [ ] Unit: `AmountInWordsConverterTest` (0, 1, 16, 21, 100, 101, 1000, 1200, 21000, 1 000 000, 2 000 000, 5000.50, 0.99, 999 999 999.99, 0.1+0.2, valor fuera de rango)
- [ ] Unit: `MoneyFormatterTest`
- [ ] Unit: `ReceiptServiceTest` (trámite activo/inactivo, cobro sin fecha/comentarios, cliente borrado, logo faltante, total inconsistente, reloj fijo con zona `America/Mexico_City`, 404 propagado)
- [ ] Unit: `ReceiptHtmlBuilderTest` (escapado de HTML en nombre y comentarios, sin `null`, tabla omitida sin filas, marca de agua condicionada)
- [ ] Unit: `ReceiptPdfRendererTest` de humo (`%PDF-`, `%%EOF`, 40 impuestos → más de 1 página)
- [ ] Unit: `ResponsePdfTest` (headers y rechazo de nombre de archivo inválido)
- [ ] La suite completa sigue en verde (`composer test`), sin warnings (`failOnWarning=true`)
- [ ] **Nota de alcance:** el repositorio no tiene pruebas de integración HTTP/BD (`phpunit.xml.dist` solo define la suite `Unit`). Los escenarios 1–4, 16–20 y la revisión visual se verifican por QA contra la API en Docker; no se introduce infraestructura de integración en este feature

## Verificación manual para QA (no automatizable aquí)
- [ ] Escenarios de ruta: 200 ×4, 400 (id no numérico, uuid de >20 caracteres o con espacios), 404 (inexistente, otra notaría, `id = 0`), 401 sin token
- [ ] `GET /payment-transactions/uuid//receipt` devuelve **404 de ruta no encontrada** (limitación del Router, ADR-007), no 400
- [ ] Un usuario de la notaría 1 pide un trámite y un cobro de la notaría 2 por id y por uuid → 404 y mismo cuerpo que un id inexistente
- [ ] Visual: acentos y ñ (`Iñaki Ángeles Ñúñez`, `Compraventa – Crédito hipotecario`), logo PNG y JPG, sin logo, marca de agua en todas las páginas, footer con `Pagina 1..n`, QR escaneable = uuid, tabla de 40 impuestos con cabecera repetida
- [ ] Hora del footer comparada con la hora de México en el momento de la petición
- [ ] Ningún registro cambia en BD tras pedir recibos (conteo de filas antes/después)

## Código
- [ ] PSR-12, `declare(strict_types=1)` en cada archivo
- [ ] Propiedades y retornos tipados
- [ ] SOLID: el Service no conoce Dompdf; el Renderer no conoce repositories; el Controller no conoce ninguno de los dos detalles
- [ ] Sin dependencias circulares entre módulos

## Performance
- [ ] Objetivo propuesto (se confirma o ajusta en el spike): recibo de 1 página **< 1.5 s**; recibo de 40 impuestos (~3 páginas) **< 4 s**; memoria pico **< 64 MB** (límite por defecto de PHP: 128 MB)
- [ ] Máximo 5 consultas por recibo de trámite (cabecera, detalles, cliente, notaría, notaría para logo) y 4 por cobro; sin N+1
- [ ] Fuente subseteada y sin imágenes remotas

## Seguridad
- [ ] JWT requerido en las 4 rutas
- [ ] Multi-tenant: `notaryId` solo sale del JWT, nunca del request
- [ ] Un recibo de otra notaría responde 404 y no se puede distinguir de uno inexistente
- [ ] `isPhpEnabled=false`, `isRemoteEnabled=false`: un nombre de cliente con `<img src="http://…">` o `<?php ?>` no dispara peticiones ni ejecuta código
- [ ] Mensajes de error genéricos hacia el cliente; el detalle técnico solo en log
- [ ] El nombre de archivo del header lo construye el servidor (`recibo-{tipo}-{folio}.pdf`), nunca con datos del usuario
