# Plan de Pruebas: PDF Header Labels (spec 22)

## Alcance

Cambio de presentación en el encabezado de notaría de los PDFs de recibo (trámite y cobro) y de estado de cuenta:
- Se quitan las etiquetas `Notario titular:` y `RFC:`.
- Un RFC vacío o de solo espacios muestra `---`.
- `ReceiptHtmlBuilder::orDash()` recibe un `$placeholder` opcional (default `—`).

**Dentro del alcance:** `receipt.php`, `statement.php`, `ReceiptHtmlBuilder::orDash`, tests nuevos.
**Fuera del alcance (verificar que no cambian):** endpoints, contratos JSON, `docs/openapi.yaml`, README, migraciones, CSS, logo, folio, QR, footer, cuerpo de los documentos.

No hay capa Controller, Service ni Repository involucrada, por lo que no aplican pruebas de API con peticiones HTTP ni de multi-tenancy nuevas. Se verificó que los archivos de esas capas no están en el diff.

## Tipos de pruebas

| Tipo | Qué se verifica | Herramienta |
|------|-----------------|-------------|
| Unitarias | Suite completa sin regresiones | PHPUnit en Docker |
| HTML de los builders | Etiquetas ausentes, `---`, orden, escape, `—` conservado | `ReceiptHtmlBuilderTest`, `StatementHtmlBuilderTest` |
| Paridad | Encabezado idéntico en recibo y estado de cuenta | `NotaryHeaderParityTest` |
| Render PDF | Los renderers siguen generando un `%PDF` válido | `ReceiptPdfRendererTest`, `StatementPdfRendererTest` |
| Texto del PDF | Contenido y orden de las líneas en los 6 PDFs de evidencia | `pdftotext -enc UTF-8 -layout` |
| Revisión visual | Estilo, atenuado, marca de agua, acentos y `ñ` | pdf.js en Chrome sobre los 6 PDFs de evidencia |
| Revisión de alcance | Archivos tocados, sitios de llamada de `orDash`, ausencia de etiquetas en `src` | `git show`, `grep` |

## Casos cubiertos

| Escenario PO | Verificación |
|--------------|--------------|
| 1. Recibo sin etiquetas | Tests de builder + 4 PDFs de recibo |
| 2. Estado de cuenta sin etiquetas | Tests de builder + 2 PDFs de estado de cuenta |
| 3. Sin RFC → `---` | Tests con `''` y `'   '`; PDFs `_sin_rfc` |
| 4. Con RFC → sin prefijo | Test con `XAXX010101000`; PDFs `_con_rfc` |
| 5. Notario vacío → `—` | Test de builder en ambos documentos |
| 6. Formato conservado | Revisión visual + sin cambios en el bloque `<style>` |
| Edge: recibo cancelado | Test + PDF de cobro cancelado |
| Edge: estado de cuenta sin movimientos | Test en `StatementHtmlBuilderTest` |
| Edge: RFC con HTML | Test de escape en ambos builders |

## Limitaciones

- Los PDFs de evidencia no incluyen logo. El logo se cubre con los tests existentes (`testLogoOnlyRenderedWhenPresent`, `testLogoIsOnlyEmbeddedAsDataUri`) y con la ausencia de cambios en la estructura de `<table class="header">`.
- No se probó contra el endpoint real con JWT: el cambio no toca controllers ni servicios, y los PDFs de evidencia salen de los renderers reales.
- No se mide cobertura de código (no hay driver configurado en el contenedor).
