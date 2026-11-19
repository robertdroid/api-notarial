# Plan de Pruebas: 18_payment_receipts_pdf

## Alcance
Recibos en PDF (solo lectura) para trámites y cobros, en 4 endpoints `GET`:
- `/api/v3/payment-transactions/{id}/receipt`
- `/api/v3/payment-transactions/uuid/{uuid}/receipt`
- `/api/v3/payments/{id}/receipt`
- `/api/v3/payments/uuid/{uuid}/receipt`

Incluye el módulo `src/Module/Receipt/`, `Response::pdf()` y las dependencias `dompdf/dompdf` y `chillerlan/php-qrcode`.

Fuera de alcance: envío de recibos por correo, almacenamiento del PDF, firma electrónica, pruebas automatizadas de integración HTTP (el proyecto no tiene esa infraestructura; ver arch).

## Documentos base
- `po/acceptance_criteria.md` (22 escenarios, validaciones, casos edge, criterios de rechazo)
- `arch/technical_acceptance_criteria.md` (Controller, Service, Renderer, Repository, DTO, Core, tests, seguridad, rendimiento)
- `arch/developer_notes.md` (decisiones cerradas: errores según ADR-007, folio `T-000123` / `C-000123`, trámite inactivo con marca `CANCELADO`)

## Tipos de pruebas
| Tipo | Detalle |
|------|---------|
| Unitarias | Suite completa PHPUnit (946 tests, 1831 aserciones), incluidos `tests/Module/Receipt/**` y `tests/Core/Http/ResponsePdfTest.php` |
| Entorno | Extensiones `dom`, `mbstring`, `gd` con JPEG, presentes en el contenedor |
| API funcional | `curl` contra `localhost:8080` con JWT de notarías 1, 2 y 3: happy path, validaciones, 401 |
| Multi-tenancy | Token de notaría 1 contra trámite 31 y cobro 18 (notaría 3), y token de notaría 2 (sin registros) |
| Render de PDFs | Renderer real dentro del contenedor con datos sintéticos (acentos, XSS, 40 impuestos, cobro sin fecha, marca de agua) |
| Revisión visual | Página 1 del trámite 71 real (logo, QR, tabla, letra) y de los PDFs sintéticos (marca de agua, footer, repetición de encabezado). Los demás PDFs reales se revisaron por texto |
| Integridad | Conteo de filas en BD antes y después de las pruebas (solo lectura) |
| Revisión de código | `ReceiptController`, `ReceiptService`, `ReceiptPdfRenderer`, DTOs |

## Entorno
Docker local (`notarial-api`, MariaDB 11.4). Los JWT se generaron dentro del contenedor con `JwtService` de la app, para las notarías 1 a 4. Los tokens se guardaron en el scratchpad local, fuera del repositorio, y no se incluyen en esta documentación. Los datos sintéticos se generaron con el renderer en el contenedor y tampoco forman parte del repositorio.

Las pruebas son de solo lectura sobre la BD de desarrollo: no se insertaron ni modificaron filas.

## Limitaciones
- No se crearon trámites inactivos ni datos con 40 impuestos en la BD de desarrollo para no escribir datos; esos casos se cubrieron con renders sintéticos.
- No se decodificó el código QR con lector; se verificó que aparece en el PDF.
- No se midieron tiempos ni memoria por recibo (objetivos de arch: < 1.5 s y < 64 MB).
