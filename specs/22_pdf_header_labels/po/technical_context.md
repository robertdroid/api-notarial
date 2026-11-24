# Contexto Técnico

## Entidades Involucradas
- Ninguna nueva. Se lee la notaría ya cargada para el encabezado: `notary->name`, `notary->notaryName`, `notary->rfc`, `notary->phone` y la línea de dirección.

## Endpoints Sugeridos
Ninguno nuevo. Cambia el contenido de los PDFs de endpoints existentes:
- GET /api/v3/payment-transactions/{id}/receipt y `/uuid/{uuid}/receipt` (recibo de trámite, spec 18)
- GET /api/v3/payments/{id}/receipt y `/uuid/{uuid}/receipt` (recibo de cobro, spec 18)
- GET /api/v3/payment-clients/{id}/statement/pdf (estado de cuenta, spec 21)

## Dependencias
- Módulo `Receipt` (spec 18): plantilla `src/Module/Receipt/Renderer/templates/receipt.php` (líneas 75-79)
- Módulo `PaymentStatement` (spec 21): plantilla `src/Module/PaymentStatement/Renderer/templates/statement.php` (líneas 79-83)
- Helper `ReceiptHtmlBuilder::orDash()` (`src/Module/Receipt/Renderer/ReceiptHtmlBuilder.php:54`), alias `H` en las plantillas. Devuelve `—` para nulos o vacíos y es compartido con el cuerpo del PDF.

## Impacto en Sistema Existente
- Dos plantillas PHP: quitar los textos `Notario titular:` y `RFC:`.
- RFC vacío debe mostrar `---`, mientras que `orDash` seguirá devolviendo `—` para el resto. Esto requiere un camino distinto solo para el RFC (decisión de Arch).
- Sin cambios en CSS: las clases `notary-name` y `muted` se conservan.
- Búsqueda en `tests/`, `docs/` y `README.md`: no hay referencias a `RFC:` ni `Notario titular`. La única mención está en `specs/18_payment_receipts_pdf/qa/test_results.md` (resultado histórico, no se modifica).

## Consideraciones de Datos
- Sin migraciones ni cambios de esquema.
- Sin cambios en datos existentes. Notarías sin RFC verán `---`.

## Notas para Implementación
- Orden final de líneas: notaría (clase `notary-name`), notario titular (sin clase), RFC (`muted`), dirección (`muted`), `Tel.` (`muted`).
- Los dos encabezados son hoy copias idénticas. Arch decide si extrae un parcial común o hace el cambio mínimo en ambas.
- Verificar visualmente los 2 PDFs (recibo vigente, recibo cancelado y estado de cuenta), con y sin RFC.
- `docs/openapi.yaml` y README no cambian.
