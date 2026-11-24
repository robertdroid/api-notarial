# Resultados de Pruebas: PDF Header Labels (spec 22)

## Resumen
- **Estado:** APROBADO
- **Tests Ejecutados:** 1106 (suite completa), de los cuales 69 son de los renderers de Receipt y PaymentStatement
- **Tests Pasados:** 1106
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (`OK, but there were issues!`: 1106 tests, 2228 assertions)
- **Cobertura:** No medida (sin driver de cobertura en el contenedor)
- **Nota:** el único aviso es `PHPUnit Deprecations: 1`, por la configuración XML de PHPUnit (esquema obsoleto). No tiene relación con este feature.

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Recibo sin etiquetas, en orden notaría, notario, RFC, dirección, `Tel.` | PASS | `testHeaderHasNoLabels`, `testHeaderLinesAreInOrder`; 4 PDFs de recibo revisados en texto y en imagen |
| 2 | Estado de cuenta con el mismo encabezado, sin etiquetas | PASS | Tests equivalentes en `StatementHtmlBuilderTest`; 2 PDFs revisados; `NotaryHeaderParityTest` en verde |
| 3 | RFC nulo o vacío muestra `---` | PASS | Tests con `''` y `'   '`; los 3 PDFs `_sin_rfc` muestran `---` atenuado |
| 4 | RFC con valor, sin prefijo y sin guiones | PASS | `XAXX010101000` en los 3 PDFs `_con_rfc`, atenuado |
| 5 | Notario vacío muestra `—` | PASS | `testEmptyNotaryNameKeepsTheEmDash` en ambos builders |
| 6 | Formato conservado (fuente, tamaño, negrita, color, logo) | PASS | Nombre en azul y negrita, RFC, dirección y teléfono atenuados, notario en estilo normal. `<style>` y estructura del header sin cambios en el diff. Logo cubierto por tests existentes (ver limitaciones) |
| NF | Sin cambios de endpoints, JSON, headers, nombres de archivo, esquema ni migraciones | PASS | El diff no toca controllers, services, repositories ni migraciones |
| NF | Acentos y caracteres especiales correctos | PASS | `Notaría Pública`, `Pérez Ñúñez`, `Querétaro`, `Iñaki Ángeles` se ven bien en los 6 PDFs |
| Val | RFC escapado para HTML; solo espacios se trata como vacío | PASS | `testRfcAndNotaryNameAreEscaped` (statement) y `testEveryUserValueIsEscaped` (receipt) |
| Edge | Recibo cancelado: el encabezado cambia igual | PASS | `testCancelledReceiptHasTheSameHeader`; el PDF muestra la marca de agua `CANCELADO` y el encabezado correcto |
| Edge | Estado de cuenta sin movimientos: el encabezado cambia igual | PASS | `testStatementWithoutMovementsHasTheSameHeader` |
| Edge | Notaría sin logo: sin columna de logo | PASS | Los 6 PDFs de evidencia no tienen logo y el encabezado se ve correcto |
| Rechazo | El PDF aún contiene `Notario titular:` o `RFC:` | PASS (no ocurre) | 0 coincidencias en los 6 PDFs ni en `src/` |
| Rechazo | RFC vacío mostrado como `—` o en blanco | PASS (no ocurre) | Muestra `---` |
| Rechazo | Cambia el `—` fuera de la línea del RFC | PASS (no ocurre) | Teléfono y correo del cliente siguen con `—` en el recibo de cobro y en el estado de cuenta; `testEmptyRfcDoesNotChangeTheDashElsewhere` |
| Rechazo | Los 2 PDFs con encabezados distintos | PASS (no ocurre) | `NotaryHeaderParityTest`: 3 casos más el de nombre y teléfono vacíos |

### Técnicos
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Controller, Service y Repository sin cambios | PASS | Solo cambian `ReceiptHtmlBuilder.php`, `receipt.php`, `statement.php` y tests |
| 2 | `orDash(?string $value, string $placeholder = '—'): string` | PASS | Firma exacta, con tipos explícitos |
| 3 | Valor nulo, vacío o de solo espacios devuelve el placeholder; con contenido devuelve `self::escape($value)` | PASS | `testOrDashUsesTheEmDashByDefaultAndAcceptsAPlaceholder` |
| 4 | El placeholder no se escapa y solo recibe literales | PASS | Las únicas 2 llamadas con 2 argumentos pasan `'---'` |
| 5 | Las llamadas existentes a `orDash` siguen devolviendo `—` | PASS | 18 de los 20 sitios de llamada usan un solo argumento; `testEmptyValuesRenderAsDash` y `testMissingClientFieldsRenderAsADash` pasan sin cambios |
| 6 | Plantillas sin `Notario titular:` ni `RFC:`; RFC con `'---'`; notario sin clase; `Tel.` conservado | PASS | Verificado en el diff del commit `540f9a0` |
| 7 | Bloque del encabezado idéntico en ambas plantillas | PASS | Las 5 líneas coinciden y `NotaryHeaderParityTest` lo asegura |
| 8 | Tests exigidos en `ReceiptHtmlBuilderTest` y `StatementHtmlBuilderTest` | PASS | Los 11 casos están presentes (el de cancelado solo en receipt, el de sin movimientos solo en statement) |
| 9 | `NotaryHeaderParityTest` nuevo | PASS | 4 tests en verde |
| 10 | `ReceiptPdfRendererTest` y `StatementPdfRendererTest` generan un `%PDF` válido | PASS | Todos en verde |
| 11 | PSR-12, `strict_types`, sin cambios en `composer.json` | PASS | Revisión visual del diff (sin linter): estilo coherente con el archivo y `composer.json` fuera del diff |
| 12 | Sin duplicar la lógica de `trim` y escape fuera de `orDash` | PASS | Las plantillas solo llaman a `H::orDash` |
| 13 | Seguridad: escape `ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8; sin entrada de usuario en `$placeholder` | PASS | Escape intacto |
| 14 | Fuera de alcance sin cambios (`openapi.yaml`, README, migraciones, CSS, spec 18) | PASS | Ninguno aparece en el diff del feature |

## Pruebas de API

Sin cambios de API. No hay endpoints nuevos ni modificados. Los 5 endpoints de PDF usan los mismos renderers, y los tests de `ReceiptPdfRenderer` y `StatementPdfRenderer` confirman que generan un PDF válido.

### Verificación de los PDFs de evidencia
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Recibo de trámite con RFC | `XAXX010101000` | Sin etiquetas, RFC atenuado en la 3.ª línea | Coincide | PASS |
| Recibo de trámite sin RFC | `''` | `---` atenuado en la 3.ª línea | Coincide | PASS |
| Recibo de cobro cancelado con RFC | `XAXX010101000` | Encabezado nuevo y marca de agua `CANCELADO` | Coincide | PASS |
| Recibo de cobro cancelado sin RFC | `''` | `---` y marca de agua | Coincide | PASS |
| Estado de cuenta con RFC | `XAXX010101000` | Mismo encabezado que el recibo | Coincide | PASS |
| Estado de cuenta sin RFC | `''` | `---` atenuado | Coincide | PASS |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| El filtro por `notary_id` no se toca | PASS | Ningún repository ni service aparece en el diff |
| Acceso cruzado entre notarías | N/A | No hay queries nuevas ni modificadas |

## Pendientes de la revisión visual (README de evidencia)
Los 5 puntos que el dev dejó para QA quedaron revisados:
- [x] Sin las etiquetas `Notario titular:` ni `RFC:`
- [x] Orden: notaría (destacada), notario, RFC (atenuado), dirección, `Tel. …`
- [x] Sin RFC se ve `---`, atenuado
- [x] Acentos y `ñ` correctos. El logo no se pudo ver porque los PDFs de evidencia no lo incluyen (ver limitaciones en `test_plan.md`)
- [x] `—` conservado en teléfono y correo del cliente

## Conclusión
El feature cumple los 6 escenarios del PO y los criterios técnicos. El cambio se limita a 3 archivos de producción, la suite completa pasa (1106 tests) y los 6 PDFs de evidencia se ven correctos en texto y en imagen. No se encontraron bugs.

Observaciones menores, sin efecto en la decisión:
- El logo no está en los PDFs de evidencia. Se confía en los tests existentes y en que la estructura del header no cambió.
- El aviso de PHPUnit sobre el esquema XML obsoleto es anterior a este feature.

**Decisión: APROBADO.**

## Fecha
2026-11-24
