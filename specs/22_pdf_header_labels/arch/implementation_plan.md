# Plan de Implementación: Encabezado de PDFs de Pagos sin Etiquetas

## Resumen

Cambio de presentación en el encabezado de notaría compartido por el recibo (spec 18) y el estado de cuenta (spec 21). Se eliminan las etiquetas `Notario titular:` y `RFC:` en las dos plantillas y el RFC vacío pasa a mostrarse como `---`.

Para eso `ReceiptHtmlBuilder::orDash()` recibe un segundo parámetro opcional `$placeholder` (default `—`). Solo la línea del RFC lo usa con `'---'`. El resto del PDF no cambia.

Sin cambios en endpoints, DTOs, Services, Repositories, base de datos, CSS ni `docs/openapi.yaml`.

## Arquitectura Propuesta

```
Controller → Service → ReceiptData / StatementPdfData (sin cambios)
                              │
                              ▼
        ReceiptHtmlBuilder::build()       StatementHtmlBuilder::build()
                              │                       │
                              ▼                       ▼
                  templates/receipt.php   templates/statement.php
                              │                       │
                              └───── bloque de encabezado (5 líneas) ─────┐
                                                                         │
        H::orDash($notary->name)                       → notary-name     │
        H::orDash($notary->notaryName)                 → sin clase       │
        H::orDash($notary->rfc, '---')   ← ÚNICO CAMBIO LÓGICO → muted   │
        H::orDash($addressLine)                        → muted           │
        'Tel. ' . H::orDash($notary->phone)            → muted  ◄────────┘

        H::orDash() sigue devolviendo '—' por defecto para el resto del PDF
```

## Módulos Afectados

- [ ] `Module/Receipt/Renderer/` - `ReceiptHtmlBuilder::orDash()` con parámetro opcional; plantilla `receipt.php`
- [ ] `Module/PaymentStatement/Renderer/` - plantilla `statement.php` (reutiliza `H` de Receipt, sin cambios en `StatementHtmlBuilder`)
- [ ] `tests/Module/Receipt/Renderer/` y `tests/Module/PaymentStatement/Renderer/` - cobertura del encabezado

## Tareas de Implementación

### Fase 1: Helper y plantillas
Estimación: 0.5 horas

#### 1.1 Parámetro `$placeholder` en `orDash`
- **Archivo(s):** `src/Module/Receipt/Renderer/ReceiptHtmlBuilder.php`
- **Tipo:** Modificar
- **Descripción:** Cambiar la firma a `orDash(?string $value, string $placeholder = '—'): string`. Devuelve `$placeholder` cuando el valor es nulo o `trim($value) === ''`; en otro caso `self::escape($value)`. Actualizar el docblock. El placeholder es una constante controlada por el código, no entrada de usuario, así que no se escapa.
- **Dependencias:** Ninguna

#### 1.2 Encabezado del recibo
- **Archivo(s):** `src/Module/Receipt/Renderer/templates/receipt.php` (líneas 76-77)
- **Tipo:** Modificar
- **Descripción:**
  - `<div>Notario titular: <?= H::orDash($notary->notaryName) ?></div>` pasa a `<div><?= H::orDash($notary->notaryName) ?></div>`.
  - `<div class="muted">RFC: <?= H::orDash($notary->rfc) ?></div>` pasa a `<div class="muted"><?= H::orDash($notary->rfc, '---') ?></div>`.
  - Las líneas de notaría, dirección y `Tel.` no se tocan.
- **Dependencias:** 1.1

#### 1.3 Encabezado del estado de cuenta
- **Archivo(s):** `src/Module/PaymentStatement/Renderer/templates/statement.php` (líneas 80-81)
- **Tipo:** Modificar
- **Descripción:** Mismo cambio exacto que 1.2. Los dos bloques deben quedar idénticos carácter por carácter.
- **Dependencias:** 1.1

### Fase 2: Tests
Estimación: 0.5 horas

#### 2.1 Tests del recibo
- **Archivo(s):** `tests/Module/Receipt/Renderer/ReceiptHtmlBuilderTest.php`
- **Tipo:** Modificar
- **Descripción:** Extender el helper `notary()` con parámetros `?string $rfc` y `string $notaryName` (con los defaults actuales) y agregar los casos de `technical_acceptance_criteria.md`, sección Tests.
- **Dependencias:** 1.1, 1.2

#### 2.2 Tests del estado de cuenta
- **Archivo(s):** `tests/Module/PaymentStatement/Renderer/StatementHtmlBuilderTest.php`
- **Tipo:** Modificar
- **Descripción:** Extender el helper `data()` con `string $rfc` y `string $titularName` (con los defaults actuales) y agregar los mismos casos que en 2.1.
- **Dependencias:** 1.1, 1.3

#### 2.3 Test de paridad de encabezados
- **Archivo(s):** `tests/Module/Receipt/Renderer/NotaryHeaderParityTest.php`
- **Tipo:** Crear
- **Descripción:** Construye un `ReceiptData` y un `StatementPdfData` con la misma `ReceiptNotary`, renderiza ambos con sus builders, extrae el `<td>` del encabezado (el bloque `notary-name` … `Tel.`) y afirma que son idénticos. Cubre el criterio de rechazo "los 2 PDFs quedan con encabezados distintos" sin extraer un parcial (ver ADR-002).
- **Dependencias:** 1.2, 1.3

### Fase 3: Verificación
Estimación: 0.25 horas

#### 3.1 Suite y análisis
- **Descripción:** Correr `docker-compose exec api composer test` (o el script equivalente del proyecto) y el análisis estático si existe. Toda la suite debe pasar; no deben fallar tests existentes que dependan de `—`.
- **Dependencias:** Fase 2

#### 3.2 Verificación visual
- **Descripción:** Generar y abrir los PDFs de: recibo de trámite vigente, recibo de cobro cancelado (marca de agua) y estado de cuenta. Cada uno con RFC y con RFC vacío. Revisar orden de líneas, estilos, logo y acentos. Dejar los resultados para QA en `specs/22_pdf_header_labels/qa/`.
- **Dependencias:** 3.1

## Orden de Implementación

1. 1.1: parámetro `$placeholder` en `orDash`
2. 1.2 y 1.3: plantillas (en paralelo, mismo cambio)
3. 2.1 y 2.2: tests por builder
4. 2.3: test de paridad
5. 3.1: suite completa
6. 3.2: verificación visual

**Total estimado: ~1.25 horas.**

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Cambiar el `—` de otros campos al tocar `orDash` | Baja | Alto | El parámetro es opcional con default `—`; ninguna llamada existente se modifica. Tests existentes (`testEmptyValuesRenderAsDash`, `testMissingClientFieldsRenderAsADash`) siguen verdes y se agrega un test explícito de no regresión |
| Los dos encabezados divergen en el futuro | Media | Medio | Test de paridad 2.3; deuda técnica registrada en ADR-002 |
| RFC de solo espacios mostrado en blanco | Baja | Bajo | `orDash` ya aplica `trim()`; test dedicado |
| Un tercer PDF reutiliza `orDash` y asume `—` | Muy baja | Bajo | Default sin cambios |
| Nombre largo rompe la maquetación | Muy baja | Bajo | No se tocan CSS ni estructura; solo se quita texto |

## Decisiones de Arquitectura

### ADR-001: Parámetro opcional `$placeholder` en `orDash`
- **Contexto:** `orDash` devuelve `—` y lo comparten el encabezado y el cuerpo de ambos PDFs. El RFC vacío debe mostrar `---` sin afectar nada más.
- **Decisión:** Agregar `string $placeholder = '—'` como segundo parámetro. El RFC se llama con `'---'`.
- **Alternativas descartadas:**
  - Helper nuevo (`orTripleDash` o similar): duplica la lógica de `trim` y escape.
  - Resolver `---` en `ReceiptService`/`ReceiptNotary`: mezcla presentación con el DTO de datos, y el estado de cuenta también lo consume.
  - `?:` en la plantilla (`trim($rfc) !== '' ? H::escape($rfc) : '---'`): lógica duplicada en las dos plantillas.
- **Consecuencias:** Cambio retrocompatible de una línea. El placeholder queda como literal en la plantilla, visible para quien lea el encabezado. Se puede reutilizar si otro campo necesita un marcador distinto.

### ADR-002: Cambio mínimo en las dos plantillas, sin parcial común
- **Contexto:** El bloque de encabezado está duplicado en `receipt.php` y `statement.php`. PO pregunta si conviene extraerlo.
- **Decisión:** Mantener el cambio mínimo en ambas plantillas y proteger la consistencia con un test de paridad (tarea 2.3).
- **Justificación:** Cada builder tiene su propio closure de render con `extract()`, así que un parcial exige tocar los dos builders y definir cómo se le pasan `$notary` y `$addressLine`. Eso es más superficie que el cambio mismo (2 líneas × 2 plantillas) y sale del alcance "solo presentación".
- **Consecuencias:** La duplicación sigue existiendo. Si se vuelve a tocar el encabezado, conviene extraer el parcial en una spec propia. Queda anotado como deuda técnica.
