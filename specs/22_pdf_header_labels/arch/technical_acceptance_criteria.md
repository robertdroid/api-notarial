# Criterios de Aceptación Técnicos

Cambio de presentación en el renderer de PDFs. No hay capa Controller, Service ni Repository involucrada, así que esas secciones se marcan como "sin cambios" y las demás se adaptan a Renderer, Template y Tests.

## Controller Layer
- [ ] Sin cambios: ningún controller se modifica
- [ ] Endpoints, códigos HTTP, headers y nombres de archivo de los 5 endpoints de PDF idénticos a hoy

## Service Layer
- [ ] Sin cambios: `ReceiptService`, `StatementPdfService` y sus DTOs no se modifican
- [ ] `ReceiptNotary` conserva su firma (`rfc` sigue siendo `string`)

## Repository Layer
- [ ] Sin cambios: no hay queries nuevas ni modificadas
- [ ] El filtro por `notary_id` existente no se toca

## Renderer: `ReceiptHtmlBuilder`
- [ ] `orDash(?string $value, string $placeholder = '—'): string`
- [ ] Valor `null`, `''` o solo espacios devuelve `$placeholder`
- [ ] Valor con contenido devuelve `self::escape($value)` (escape HTML idéntico al actual)
- [ ] `$placeholder` no se escapa: solo se pasan literales del código, nunca datos de usuario
- [ ] Todas las llamadas existentes a `orDash` con un argumento siguen devolviendo `—`
- [ ] Docblock actualizado

## Templates
- [ ] `receipt.php` y `statement.php`: sin los textos `Notario titular:` ni `RFC:`
- [ ] Línea del RFC: `<div class="muted"><?= H::orDash($notary->rfc, '---') ?></div>`
- [ ] Línea del notario: `<div><?= H::orDash($notary->notaryName) ?></div>` (sin clase, `—` si vacío)
- [ ] Líneas de notaría, dirección y `Tel.` sin cambios (incluida la etiqueta `Tel.`)
- [ ] Orden de líneas: `notary-name`, notario, RFC, dirección, `Tel.`
- [ ] Bloque del `<td>` del encabezado idéntico en ambas plantillas
- [ ] Sin cambios en el bloque `<style>`, en el logo ni en la estructura de `<table class="header">`
- [ ] Sin cambios fuera de esas dos líneas por plantilla (cliente, teléfono, correo y demás conservan `—`)

## Tests
Se usa PHPUnit sobre el HTML que devuelven los builders, en las clases existentes.

En `ReceiptHtmlBuilderTest` y `StatementHtmlBuilderTest`:
- [ ] El HTML no contiene `Notario titular:` ni `RFC:`
- [ ] Con RFC `XAXX010101000`, el HTML contiene `<div class="muted">XAXX010101000</div>`
- [ ] Con RFC `''` muestra `<div class="muted">---</div>`
- [ ] Con RFC `'   '` (solo espacios) muestra `<div class="muted">---</div>`
- [ ] Con notario titular vacío muestra `<div>—</div>`
- [ ] El nombre de la notaría conserva `<div class="notary-name">…</div>`
- [ ] Una notaría sin RFC no cambia el `—` de cliente y teléfono (`<td class="k">Teléfono</td><td>—</td>`)
- [ ] Un RFC con HTML (`<script>`…) sale escapado (el test XSS actual del recibo ya lo cubre; replicar en el estado de cuenta)
- [ ] Orden de líneas: posición de la notaría < notario < RFC < dirección < `Tel.` (con `strpos`)
- [ ] Recibo cancelado: el encabezado cambia igual que en uno vigente (solo `receipt`)
- [ ] Estado de cuenta sin movimientos: el encabezado cambia igual (solo `statement`)

En `tests/Module/Receipt/Renderer/NotaryHeaderParityTest.php` (nuevo):
- [ ] El bloque del encabezado de recibo y estado de cuenta es idéntico para la misma `ReceiptNotary`, con RFC y sin RFC

Los tests existentes:
- [ ] `testEmptyValuesRenderAsDash`, `testMissingClientFieldsRenderAsADash` y el resto de la suite pasan sin modificar sus aserciones
- [ ] Los tests de `ReceiptPdfRendererTest` y `StatementPdfRendererTest` siguen generando un `%PDF` válido

## Código
- [ ] PSR-12 y `declare(strict_types=1)` conservados
- [ ] Tipos explícitos en la nueva firma
- [ ] Sin duplicar la lógica de `trim` y escape fuera de `orDash`
- [ ] Sin cambios en `composer.json` ni dependencias

## Performance
- [ ] Sin queries ni llamadas nuevas
- [ ] Sin impacto perceptible en el tiempo de generación

## Seguridad
- [ ] Escape HTML del RFC y del notario igual que hoy (`ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8)
- [ ] JWT y multi-tenant sin cambios en los endpoints existentes
- [ ] Sin entrada de usuario en `$placeholder`

## Fuera de alcance (verificar que no cambian)
- [ ] `docs/openapi.yaml` y `README.md`
- [ ] Migraciones y esquema
- [ ] CSS, logo, folio, QR, footer y cuerpo de los documentos
- [ ] `specs/18_payment_receipts_pdf/qa/test_results.md` (resultado histórico)
