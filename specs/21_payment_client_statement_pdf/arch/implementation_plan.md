# Plan de Implementación: Estado de Cuenta de Cliente en PDF

## Resumen

Un `GET` de solo lectura que devuelve el estado de cuenta de la spec 19 como PDF. No hay migraciones, repositories nuevos ni dependencias nuevas (Dompdf ya está por la spec 18).

- Los datos salen de `PaymentStatementService::build()`, en el mismo proceso. Los totales y el saldo del PDF son los del JSON.
- Un `StatementLedgerBuilder` puro fusiona trámites y cobros en un libro cronológico con saldo corrido en centavos. No recalcula totales: solo los presenta.
- `StatementPdfService` arma un `StatementPdfData` inmutable (notaría, cliente, libro, resumen, leyenda). No genera HTML ni usa Dompdf.
- La configuración segura de Dompdf, la captura del buffer, la validación del PDF y el footer con paginación se extraen a `src/Shared/Pdf/` y los usa el estado de cuenta.

Decisiones ya cerradas con el equipo:

| # | Decisión |
|---|----------|
| 1 | Orden del libro por `(fecha Y-m-d, tipo, id)`. Cargo antes que abono el mismo día. Cobros sin fecha al final |
| 2 | Teléfono y correo vía **segunda consulta** con `PaymentClientRepository::findByIdAndNotary()`. `ClientStatement` y el JSON de la spec 19 no cambian |
| 3 | Extraer lo compartido a `Shared/Pdf/`. Receipt se migra en una tarea final y separada, condicionada a que sus pruebas pasen sin cambios |
| 4 | Errores con el mismo patrón que `ReceiptController`: cualquier `Throwable` es 500 JSON |
| 5 | Sin límite funcional de filas. El spike mide Dompdf con volumen |

## Arquitectura Propuesta

```
GET /api/v3/payment-clients/{id}/statement/pdf?from=&to=      AuthMiddleware (JWT → notaryId)
        │
        ▼
PaymentStatementController::pdf
   1. parseId            → 400 Invalid ID format / 404 Client not found (id <= 0)
   2. StatementQuery     → 400 Validation failed
   3. try {
   │    StatementPdfService::build(id, notaryId, query)  → null = 404 Client not found
   │    StatementPdfRenderer::render(data)               → string PDF
   │    Response::pdf(bytes, data->fileName())
   │  } catch (\Throwable) → error_log + 500 "Could not generate statement"
        │
        ▼
StatementPdfService              arma StatementPdfData (sin HTML, sin Dompdf)
   ├─ PaymentStatementService::build()          ClientStatement (cliente + cargos + abonos + totales)
   ├─ PaymentClientRepository::findByIdAndNotary()   teléfono y correo (ADR-002)
   ├─ NotaryService::getByIdActive + LogoDataUriReader   encabezado y logo (null → StatementGenerationException)
   ├─ StatementLedgerBuilder::build()           list<StatementLedgerRow> con saldo corrido
   ├─ BalanceStatus::fromBalance()              PENDIENTE / A FAVOR / SIN ADEUDO
   └─ AmountInWordsConverter::convert()         importe con letra (no aplica en SIN ADEUDO)
        │  StatementPdfData (DTO inmutable)
        ▼
StatementPdfRenderer             StatementPdfData → string binario PDF
   ├─ StatementHtmlBuilder       plantilla PHP + escape en TODO valor
   └─ Shared\Pdf\DompdfDocumentRenderer   Dompdf seguro + buffer + valida %PDF- + footer con paginación
        │  string PDF (en memoria, nunca a disco)
        ▼
Response::pdf()                  200 + application/pdf, inline, no-store
```

Regla de oro (igual que Receipt): el PDF se genera completo en memoria antes de enviar el primer byte. Cualquier falla es JSON 500, nunca un PDF parcial.

## Módulos Afectados

- [x] `Module/PaymentStatement/` — Controller (método `pdf`), Service, Renderer, DTO, Entity, Exception nuevos
- [x] `Shared/Pdf/` — **nuevo**: `DompdfDocumentRenderer`, `LogoDataUriReader`, `PdfRenderException`
- [x] `Config/routes.php` — 1 ruta
- [x] `bootstrap.php` — registrar servicios
- [x] `docs/openapi.yaml`, `README.md` — documentación
- [x] `Module/Receipt/` — **solo en la Fase 8 (opcional)**: migrar el renderer y el logo a `Shared/Pdf`. Sin cambio de comportamiento
- [ ] `Module/PaymentClient`, `Module/Notary`, `Core/Http/Response` — sin cambios (solo se consumen)
- [ ] `PaymentStatementService`, `ClientStatement`, `StatementResponse`, `StatementRepository` — **sin cambios** (el JSON de la spec 19 queda intacto)

## Estructura final

```
src/Shared/Pdf/
├── DompdfDocumentRenderer.php        (html + generatedAt → binario PDF; opciones seguras, buffer, footer)
├── LogoDataUriReader.php             (Notary → ?data URI; null + error_log si falta o no se puede leer)
└── PdfRenderException.php

src/Module/PaymentStatement/
├── Controller/PaymentStatementController.php      (+ método pdf)
├── DTO/
│   ├── StatementPdfData.php                       (notaría, cliente, periodo, filas, resumen, leyenda, generatedAt)
│   └── StatementLedgerRow.php                     (kind, fecha, folio, concepto, cargo?, abono?, saldo)
├── Entity/
│   ├── BalanceStatus.php                          (enum Pending | InFavor | Settled: label + fromBalance)
│   └── LedgerRowKind.php                          (enum Opening | Charge | Payment)
├── Exception/StatementGenerationException.php
├── Renderer/
│   ├── StatementPdfRenderer.php
│   ├── StatementHtmlBuilder.php
│   └── templates/statement.php
└── Service/
    ├── StatementLedgerBuilder.php                 (puro, sin BD)
    └── StatementPdfService.php
```

Se reutilizan sin modificar: `ReceiptNotary` (DTO de encabezado), `ReceiptClient` (DTO de cliente), `MoneyFormatter`, `AmountInWordsConverter`, `ReceiptType::folio()`, `ReceiptHtmlBuilder::escape()/orDash()`.

## Tareas de Implementación

### Fase 0: Spike de Dompdf con volumen (riesgo técnico primero)
Estimación: 1 hora

#### 0.1 Medir tabla grande y paginación
- **Archivo(s):** script desechable en el scratchpad (no se commitea)
- **Tipo:** Investigación
- **Descripción:** Con el CSS de la plantilla de recibos, generar tablas de 200, 1,000 y 3,000 filas con 6 columnas. Validar: (1) `thead { display: table-header-group }` repite el encabezado en cada página; (2) `tr { page-break-inside: avoid }` no parte filas; (3) un bloque final (resumen + leyenda) con `page-break-inside: avoid` no se corta entre páginas; (4) texto largo en una celda se ajusta sin romper la tabla; (5) footer con `page_script` en todas las páginas; (6) tiempo y memoria pico en cada volumen (`memory_get_peak_usage`).
- **Dependencias:** ninguna
- **Salida:** confirmar el CSS de la tabla. Si el bloque final se corta, usar un contenedor sin saltos o un salto manual cuando quede poco espacio. Fijar el `memory_limit` mínimo y el umbral de aviso en el log (ADR-005). Anotar los números en `developer_notes.md`.

### Fase 1: Componentes compartidos `Shared/Pdf`
Estimación: 1.5 horas

#### 1.1 `PdfRenderException` y `DompdfDocumentRenderer`
- **Archivo(s):** `src/Shared/Pdf/PdfRenderException.php`, `src/Shared/Pdf/DompdfDocumentRenderer.php`
- **Tipo:** Crear
- **Descripción:** `render(string $html, \DateTimeImmutable $generatedAt): string`. Constructor con `string $chroot`. Mueve el código de `ReceiptPdfRenderer` sin cambiar su comportamiento: `ob_start()` y limpieza de niveles en `finally`; opciones `isRemoteEnabled=false`, `isPhpEnabled=false`, `isFontSubsettingEnabled=true`, `defaultFont='DejaVu Sans'`, `tempDir`, `fontCache`, `chroot`; papel `letter` portrait; footer `Generado el d/m/Y H:i by notarial.devrob.net - Pagina n` con `page_script`, `set_opacity(1.0)` antes de dibujar el texto; valida que el resultado empiece con `%PDF-`. Todo `Throwable` se envuelve en `PdfRenderException`.
- **Dependencias:** 0.1

#### 1.2 `LogoDataUriReader`
- **Archivo(s):** `src/Shared/Pdf/LogoDataUriReader.php`
- **Tipo:** Crear
- **Descripción:** `read(Notary $notary): ?string`. Misma lógica que `ReceiptService::logoDataUri()`: `hasLogo()`, `NotaryService::getLogoInfo()`, solo `image/png` e `image/jpeg`, `@file_get_contents`, data URI base64. Si falta o no se puede leer: `error_log` y `null`. Nunca lanza por el logo. El prefijo del log es `[statement]` o `[pdf]`; no debe decir `[receipt]`.
- **Dependencias:** ninguna

### Fase 2: Lógica pura y armado de datos (sin Dompdf)
Estimación: 3 horas

#### 2.1 Enums y DTOs
- **Archivo(s):** `Entity/LedgerRowKind.php`, `Entity/BalanceStatus.php`, `DTO/StatementLedgerRow.php`, `DTO/StatementPdfData.php`, `Exception/StatementGenerationException.php`
- **Tipo:** Crear
- **Descripción:**
  - `BalanceStatus::fromBalance(float)`: compara en centavos enteros (`(int) round($b * 100)`): `> 0` Pending, `< 0` InFavor, `0` Settled. `label()` devuelve `SALDO PENDIENTE`, `SALDO A FAVOR` o `SIN ADEUDO`.
  - `StatementLedgerRow`: `kind`, `?string $date` (Y-m-d), `folio`, `concept`, `?float $charge`, `?float $payment`, `float $balance`.
  - `StatementPdfData`: `ReceiptNotary $notary`, `ReceiptClient $client`, `?string $fromDate`, `?string $toDate`, `list<StatementLedgerRow> $rows`, `bool $hasMovements`, `pendingAmount`, `totalCharges`, `totalPayments`, `balance`, `BalanceStatus $status`, `?string $amountInWords`, `\DateTimeImmutable $generatedAt`, `int $clientId`. Métodos `periodLabel()` y `fileName()`.
  - `periodLabel()`: `Del dd/mm/yyyy al dd/mm/yyyy`, `Desde dd/mm/yyyy`, `Hasta dd/mm/yyyy` o `Todos los movimientos` (con `MoneyFormatter::date`).
  - `fileName()`: `estado-de-cuenta-C-%06d.pdf` con `ReceiptType::Payment->folio()`.
- **Dependencias:** ninguna

#### 2.2 `StatementLedgerBuilder`
- **Archivo(s):** `src/Module/PaymentStatement/Service/StatementLedgerBuilder.php`
- **Tipo:** Crear
- **Descripción:** `build(ClientStatement): list<StatementLedgerRow>`. Ver "Algoritmo del libro" abajo. Clase pura (sin BD ni reloj), fácil de probar.
- **Dependencias:** 2.1

#### 2.3 `StatementPdfService`
- **Archivo(s):** `src/Module/PaymentStatement/Service/StatementPdfService.php`
- **Tipo:** Crear
- **Descripción:** `build(int $clientId, int $notaryId, StatementQuery $query): ?StatementPdfData`.
  1. `statementService->build()`; si es `null`, devuelve `null` (el controller responde 404). No se hace ninguna otra consulta.
  2. Cliente: `PaymentClientRepository::findByIdAndNotary()` para teléfono y correo. Si regresa `null` (borrado entre las dos lecturas), usa el nombre del `ClientStatement` y deja teléfono y correo vacíos (se ven como "—").
  3. Notaría: `NotaryService::getByIdActive($notaryId)`; si es `null`, `error_log('[statement] notary N not found or inactive')` y `StatementGenerationException`. Arma `ReceiptNotary` con el logo de `LogoDataUriReader`.
  4. Libro con `StatementLedgerBuilder`; `BalanceStatus::fromBalance($statement->balance)`.
  5. Importe con letra: `Pending` → `convert(balance)`, `InFavor` → `convert(abs(balance))`, `Settled` → `null`.
  6. `generatedAt` con reloj inyectable (`\Closure $clock`) en `America/Mexico_City`, igual que `ReceiptService`.
  7. Si el saldo de la última fila del libro no coincide con `balance` (tolerancia 0.005): `error_log('[statement] ...')` y se muestra el valor del servicio sin corregir.
- **Dependencias:** 1.2, 2.1, 2.2

### Fase 3: Renderer
Estimación: 2.5 horas

#### 3.1 `StatementHtmlBuilder` y plantilla
- **Archivo(s):** `src/Module/PaymentStatement/Renderer/StatementHtmlBuilder.php`, `Renderer/templates/statement.php`
- **Tipo:** Crear
- **Descripción:** Mismo mecanismo que `ReceiptHtmlBuilder` (closure con `extract` y `ob_start`, plantilla con `require`). Mismo CSS y paleta sobria. Secciones:
  1. Encabezado de notaría (logo solo si existe).
  2. Título `ESTADO DE CUENTA`, periodo y fecha de emisión.
  3. Cliente: nombre, teléfono y correo, con "—" si faltan.
  4. Tabla del libro: Fecha, Folio, Concepto, Cargo, Abono, Saldo. Fila "Saldo anterior" si hay `from`. Si no hay movimientos, una fila "Sin movimientos en el periodo".
  5. Resumen: saldo anterior (solo con `from`), total de cargos, total de abonos y **saldo final**.
  6. Leyenda y importe con letra, con valor absoluto si es saldo a favor.

  Todo valor pasa por `escape()`/`orDash()`. Concepto de trámite: procedimiento y referencia unidos por ` – `; si solo hay uno, ese; si no hay ninguno, `—`. Concepto de cobro: comentario o `Abono`. Montos con `MoneyFormatter::money()`; saldo negativo en el libro con signo `-`, nunca solo con color.
  CSS de tabla: `thead { display: table-header-group }`, `tr { page-break-inside: avoid }`, `td { word-wrap: break-word }`. El bloque resumen y leyenda va en un contenedor con `page-break-inside: avoid` (se ajusta según el spike).
- **Dependencias:** 0.1, 2.1

#### 3.2 `StatementPdfRenderer`
- **Archivo(s):** `src/Module/PaymentStatement/Renderer/StatementPdfRenderer.php`
- **Tipo:** Crear
- **Descripción:** `render(StatementPdfData): string` = `htmlBuilder->build($data)` → `DompdfDocumentRenderer::render($html, $data->generatedAt)`. Envuelve `PdfRenderException` y cualquier otro `Throwable` en `StatementGenerationException`.
- **Dependencias:** 1.1, 3.1

### Fase 4: HTTP
Estimación: 1 hora

#### 4.1 Controller
- **Archivo(s):** `src/Module/PaymentStatement/Controller/PaymentStatementController.php`
- **Tipo:** Modificar
- **Descripción:** Inyectar `StatementPdfService` y `StatementPdfRenderer` por `ServiceLocator` en el constructor. Método `pdf(Request)`: `parseId` → `StatementQuery` → `try { build → null = 404; render; Response::pdf($bytes, $data->fileName()) } catch (\Throwable $e) { error_log('[statement] ' . $e::class . ': ' . $e->getMessage()); return Response::serverError('Could not generate statement'); }`. `show()` no cambia.
- **Dependencias:** 2.3, 3.2

#### 4.2 Ruta y registro
- **Archivo(s):** `src/Config/routes.php`, `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** `GET /api/v3/payment-clients/{id}/statement/pdf` con `->withMiddleware('auth')`, junto a la ruta de `statement`. Registrar `DompdfDocumentRenderer` (chroot = carpeta de templates del estado de cuenta), `LogoDataUriReader`, `StatementLedgerBuilder`, `StatementPdfService`, `StatementHtmlBuilder` y `StatementPdfRenderer`.
- **Dependencias:** 4.1

### Fase 5: Pruebas
Estimación: 2.5 horas

#### 5.1 Pruebas unitarias
- **Archivo(s):** `tests/Module/PaymentStatement/**`, `tests/Shared/Pdf/**`
- **Tipo:** Crear
- **Descripción:**
  - `StatementLedgerBuilderTest`: orden por fecha, cargo antes que abono el mismo día, desempate por `id`, cobros sin fecha al final, saldo corrido 1,000 → 600 → 800, fila "Saldo anterior" solo con `from`, saldo negativo, el saldo de la última fila es igual a `balance`, centavos sin deriva (0.1 + 0.2).
  - `BalanceStatusTest`: positivo, negativo, 0.00 y `-0.001` (cero).
  - `StatementPdfDataTest`: las cuatro variantes de `periodLabel()` y `fileName()` solo con `[A-Za-z0-9._-]`.
  - `StatementPdfServiceTest` (mocks al estilo `ReceiptServiceTest`): cliente inexistente → `null` sin consultar notaría, notaría inactiva → excepción, cliente sin teléfono y correo, cliente borrado entre lecturas, importe con letra para pendiente, a favor (valor absoluto) y cero (`null`), `generatedAt` con reloj fijo, logo faltante, saldo inconsistente solo se registra.
  - `StatementHtmlBuilderTest`: escape de `<script>` y `<img>` en nombre, referencia y comentario; ausencia de la cadena `null`; "Sin movimientos en el periodo"; concepto sin procedimiento; cobro sin comentario → "Abono"; cobro sin fecha → "—".
  - `StatementPdfRendererTest` (humo): `%PDF-` y `%%EOF`; con y sin logo; sin movimientos; 150 filas dan más de una página; texto largo; falla de render envuelta, sin fuga de salida y con niveles de buffer restaurados.
  - `DompdfDocumentRendererTest`: PDF válido, `PdfRenderException` si el HTML hace fallar el render, buffers restaurados.
- **Dependencias:** 2.x, 3.x

#### 5.2 Verificación manual E2E (QA)
- **Descripción:** El proyecto no tiene infraestructura de pruebas de integración HTTP/BD. QA cubre con la API en Docker los 23 escenarios del PO, con énfasis en la coincidencia con el JSON de la spec 19 (1, 2, 6), multi-tenant (15) y la falla sin PDF parcial (23).
- **Dependencias:** 4.2

### Fase 6: Documentación
Estimación: 1 hora

#### 6.1 OpenAPI y README
- **Archivo(s):** `docs/openapi.yaml`, `README.md`
- **Tipo:** Modificar
- **Descripción:** Ruta con parámetros `id`, `from` y `to`; respuesta `200` con `application/pdf` (`type: string, format: binary`) y headers `Content-Disposition` y `Cache-Control`; errores 400/401/404/500 con el esquema de error existente. README con el formato de las secciones de pagos.
- **Dependencias:** 4.2

### Fase 7: Revisión visual
Estimación: 1 hora

#### 7.1 Revisión del PDF
- **Descripción:** Con datos reales en Docker, generar y revisar a ojo: pocas filas, una página llena, varias páginas, saldo a favor, sin adeudo, cliente sin movimientos, con y sin logo, texto largo y acentos. Imprimir en blanco y negro para confirmar que cargos y abonos se distinguen.
- **Dependencias:** 4.2

### Fase 8 (opcional, separada): Migrar Receipt a `Shared/Pdf`
Estimación: 0.5 hora, fuera del total

#### 8.1 Receipt usa los componentes compartidos
- **Archivo(s):** `src/Module/Receipt/Renderer/ReceiptPdfRenderer.php`, `src/Module/Receipt/Service/ReceiptService.php`
- **Tipo:** Modificar
- **Descripción:** `ReceiptPdfRenderer` delega en `DompdfDocumentRenderer` y envuelve `PdfRenderException` en `ReceiptGenerationException`. `ReceiptService::logoDataUri()` delega en `LogoDataUriReader`, conservando el prefijo `[receipt]` en los logs o aceptando un prefijo.
- **Condición de entrega:** `ReceiptPdfRendererTest` y `ReceiptServiceTest` pasan **sin modificar una sola línea de las pruebas**. Si alguna requiere cambios, se revierte esta fase y Receipt queda como está. Va en un commit aparte de la feature.
- **Dependencias:** 1.1, 1.2, y que la feature esté estable

## Algoritmo del libro (`StatementLedgerBuilder`)

Entrada: `ClientStatement` (ya redondeado y en centavos por el servicio).

1. `running = toCents(pendingAmount)`. Si `fromDate !== null`, la primera fila es `Opening` con saldo `pendingAmount`.
2. Fusionar en una lista de ítems `(date, typeRank, id, ...)`:
   - trámite: `date = date`, `typeRank = 0`
   - cobro: `date = date ?? null`, `typeRank = 1`
3. Orden: fecha ascendente (los `null` al final), luego `typeRank`, luego `id`. Se ordena por la **fecha Y-m-d**, no por la hora: el desempate por `id` es el del PO. Se usa `usort` con una comparación completa y determinista (nunca depender de la estabilidad).
4. Recorrer: cargo → `running += toCents(total)`; abono → `running -= toCents(amount)`. Cada fila lleva `fromCents(running)`.
5. Devolver las filas. Los totales y el saldo final **no se calculan aquí**: salen de `ClientStatement`. La verificación contra `balance` la hace `StatementPdfService`.

Nota: el SQL de la spec 19 ordena trámites por fecha y hora. El orden del libro puede diferir del orden del JSON dentro del mismo día. No afecta ningún total ni el saldo final.

## Orden de Implementación

1. **0.1** Spike (fija CSS, memoria y umbrales)
2. 1.1 `DompdfDocumentRenderer` · 1.2 `LogoDataUriReader` (con sus pruebas)
3. 2.1 Enums y DTOs → 2.2 `StatementLedgerBuilder` (+ pruebas) → 2.3 `StatementPdfService` (+ pruebas)
4. 3.1 Plantilla y `StatementHtmlBuilder` → 3.2 `StatementPdfRenderer` (+ pruebas)
5. 4.1 Controller · 4.2 Ruta y bootstrap
6. 5.1 Pruebas restantes · 6.1 OpenAPI y README
7. 7.1 Revisión visual
8. Entrega a QA (5.2)
9. Fase 8 (opcional) en un commit aparte, solo con Receipt sin cambios en pruebas

**Estimación total: ~13.5 horas** (12.5 h del PO + 1 h de spike, con la extracción a `Shared/Pdf` absorbida en la fase de renderer).

| Fase | Horas |
|------|-------|
| 0 Spike | 1 |
| 1 Shared/Pdf | 1.5 |
| 2 Lógica y datos | 3 |
| 3 Renderer | 2.5 |
| 4 HTTP | 1 |
| 5 Pruebas | 2.5 |
| 6 Documentación | 1 |
| 7 Revisión visual | 1 |
| **Total** | **13.5** |

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Dompdf lento o con mucha memoria con miles de filas | Media | Medio | Spike con 200/1,000/3,000 filas; `memory_limit` mínimo documentado; `error_log` de aviso por encima del umbral. Sin límite funcional (decisión del PO) |
| Resumen y leyenda cortados entre páginas | Media | Medio | Contenedor con `page-break-inside: avoid`; el spike lo valida. Alternativa: salto manual |
| El saldo del libro no coincide con `balance` | Baja | Alto | Todo en centavos; el builder no recalcula totales; prueba que compara el saldo de la última fila con `balance`; si falla en producción se registra y no se corrige |
| El orden del libro difiere del JSON dentro del mismo día | Media | Bajo | Documentado; no afecta totales. QA lo sabe antes de comparar (escenarios 1 y 2) |
| Cobros sin fecha: el saldo filtrado difiere del no filtrado | Baja | Bajo | Consecuencia del default de la spec 19. Se muestra lo que entrega el servicio |
| Un saldo mayor a 999,999,999.99 hace fallar el conversor de importes | Muy baja | Bajo | Cae en 500 controlado; `PaymentFieldRules::MAX_MONEY` ya limita los montos individuales |
| Fuga de datos entre notarías en cliente, notaría o logo | Baja | Alto | `build()` devuelve `null` si el cliente no es de la notaría, y no se hace ninguna otra consulta; la segunda consulta del cliente y la notaría usan `notaryId` del token |
| La extracción a `Shared/Pdf` altera los recibos | Baja | Alto | Receipt **no se toca** en la feature. La Fase 8 es opcional y se revierte si las pruebas de Receipt requieren cambios |
| Inyección de HTML por nombres, referencias o comentarios | Media | Medio | `escape()` en todo valor, `isRemoteEnabled=false`, `isPhpEnabled=false`, prueba dedicada |
| Warnings de Dompdf contaminan la respuesta | Media | Medio | `ob_start()` y limpieza en `finally`, dentro de `DompdfDocumentRenderer` |
| Generar PDFs es costoso en CPU (sin rate limit) | Baja | Medio | Requiere JWT; el rate limiting queda como deuda, igual que en la spec 18 |

## Decisiones de Arquitectura

### ADR-001: Servicio del PDF separado, que consume `PaymentStatementService`
- **Contexto:** El PDF necesita los mismos números que el JSON, más datos de cliente y notaría.
- **Decisión:** `StatementPdfService` llama a `PaymentStatementService::build()` en el mismo proceso. No hay llamada HTTP ni consultas duplicadas de movimientos. El módulo `PaymentStatement` agrega el PDF sin que `PaymentStatementService` cambie.
- **Consecuencias:** Totales idénticos por construcción. El servicio del JSON queda intacto. El módulo `PaymentStatement` depende ahora de `Receipt` (DTOs y formateadores) y de `Notary`.

### ADR-002: Teléfono y correo con una segunda consulta al cliente
- **Contexto:** `ClientStatement` solo trae `clientId` y `clientName`. El PO dejó abierto cómo evitar leer el cliente dos veces.
- **Decisión:** `StatementPdfService` consulta `PaymentClientRepository::findByIdAndNotary()` después de que `build()` confirma que el cliente existe. No se modifica `ClientStatement`. `PaymentClientService` no se inyecta porque es `final`.
- **Consecuencias:** El contrato JSON y las pruebas de la spec 19 no se tocan. El costo es una consulta por clave primaria adicional por PDF, que es despreciable frente al render. Si el cliente desapareciera entre las dos lecturas, se usa el nombre del estado de cuenta y los demás campos salen como "—".

### ADR-003: Componentes de PDF compartidos en `Shared/Pdf`
- **Contexto:** El renderer de Receipt tiene opciones de seguridad, captura de buffer, validación y footer que el estado de cuenta necesita idénticos. Duplicarlos arriesga que diverjan en un código sensible.
- **Decisión:** Extraer `DompdfDocumentRenderer` y `LogoDataUriReader` a `src/Shared/Pdf/` (junto a `Shared/Pagination` y `Shared/Uuid`). El estado de cuenta los usa desde el inicio. Receipt se migra en la Fase 8, opcional, en un commit aparte y solo si sus pruebas pasan sin cambios. La excepción compartida es `PdfRenderException`; cada módulo la envuelve en la suya.
- **Consecuencias:** Una sola configuración segura de Dompdf. Mientras no se haga la Fase 8 hay duplicación temporal entre Receipt y `Shared/Pdf`, aceptada para no arriesgar los recibos. El footer pone la opacidad en 1.0 aunque el estado de cuenta no tenga marca de agua, para ser idéntico al de Receipt.

### ADR-004: Libro como función pura sobre `ClientStatement`, en centavos
- **Contexto:** El libro debe cuadrar con `balance` y probarse sin BD.
- **Decisión:** `StatementLedgerBuilder` solo ordena y acumula en centavos enteros, con el mismo `toCents` que el servicio. No calcula totales. Orden `(fecha Y-m-d, tipo, id)` con cobros sin fecha al final.
- **Consecuencias:** Sin deriva de coma flotante. El orden puede diferir del JSON dentro del mismo día, sin efecto en totales. La comprobación contra `balance` va en `StatementPdfService` y se registra sin corregir (patrón de la spec 18).

### ADR-005: Sin límite de filas; el costo de Dompdf se mide, no se acota
- **Contexto:** El PO no define límite y hoy no hay clientes con miles de movimientos.
- **Decisión:** No se pagina ni se limita. El spike mide tiempo y memoria con 200, 1,000 y 3,000 filas. Con esos números se documenta el `memory_limit` mínimo y se agrega un `error_log` de aviso al superar un umbral de filas.
- **Consecuencias:** El pico de memoria crece con las filas. Si algún cliente real lo excede, se abre una spec nueva (límite, paginación del periodo o generación en cola). Eso queda fuera de esta.

### ADR-006: Errores con el mismo patrón que Receipt
- **Contexto:** El PDF no puede enviarse parcial.
- **Decisión:** El controller valida `id` y fechas antes de generar. El 404 sale de `build() === null`. Cualquier `Throwable` durante el armado o el render es `error_log` más 500 JSON `Could not generate statement`. `Response::pdf()` se llama solo con el binario completo y validado.
- **Consecuencias:** Una notaría inexistente o inactiva en el token es un 500 controlado, igual que en los recibos.

### ADR-007: Reutilizar `ReceiptNotary` y `ReceiptClient` como DTOs de encabezado
- **Contexto:** El encabezado y los datos del cliente son idénticos a los del recibo.
- **Decisión:** `StatementPdfData` usa `ReceiptNotary` y `ReceiptClient` tal cual, en vez de crear copias.
- **Consecuencias:** Sin duplicación. Un cambio en esos DTOs afecta a los dos documentos. Si el estado de cuenta necesitara campos propios, se crean DTOs específicos entonces.
