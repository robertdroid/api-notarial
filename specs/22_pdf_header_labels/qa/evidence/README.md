# Evidencia para QA — Spec 22

PDFs generados con los renderers reales (`ReceiptPdfRenderer`, `StatementPdfRenderer`) y datos de ejemplo, cada uno con RFC (`XAXX010101000`) y sin RFC (`''`):

| Archivo | Documento |
|---------|-----------|
| `recibo_tramite_vigente_con_rfc.pdf` / `_sin_rfc.pdf` | Recibo de trámite vigente |
| `recibo_cobro_cancelado_con_rfc.pdf` / `_sin_rfc.pdf` | Recibo de cobro cancelado (marca de agua) |
| `estado_cuenta_con_rfc.pdf` / `_sin_rfc.pdf` | Estado de cuenta con movimientos |

## Pendiente: revisión visual

El desarrollador **no pudo abrir los PDFs a imagen** (el entorno no tiene `pdftoppm`), así que la revisión visual queda para QA. Revisar en cada PDF:

- [ ] Sin las etiquetas `Notario titular:` ni `RFC:`
- [ ] Orden: notaría (destacada), notario, RFC (atenuado), dirección, `Tel. …`
- [ ] Sin RFC se ve `---` (tres guiones), atenuado
- [ ] Logo en su lugar, acentos y `ñ` correctos
- [ ] `—` conservado en los demás campos vacíos (cliente, teléfono, etc.)

Cubierto por tests automáticos (HTML): ausencia de etiquetas, `---` con RFC vacío o de solo espacios, orden de líneas, escape HTML y paridad entre recibo y estado de cuenta (`NotaryHeaderParityTest`).
