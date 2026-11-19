# Resultados de Pruebas: 18_payment_receipts_pdf

## Resumen
- **Estado:** APROBADO (con observaciones no bloqueantes)
- **Tests Ejecutados:** 946 unitarios + 21 peticiones de API + 4 renders de PDF sintéticos + revisión visual
- **Tests Pasados:** 946 unitarios; 21/21 peticiones con el resultado esperado o con desvío ya aceptado en arch (ADR-007)
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (946 tests, 1831 aserciones)
- **Cobertura:** no medida (no hay driver de cobertura configurado)
- **Nota:** 1 deprecación de PHPUnit por el esquema del XML de configuración. Ya existía antes de esta feature (ver spec 17).

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Recibo de trámite por id | PASS | 200, `application/pdf`, `recibo-tramite-T-000001.pdf` (trámite 1, notaría 1) |
| 2 | Recibo de trámite por uuid | PASS | 200 con el mismo contenido que por id (el PDF cambia solo en bytes por `generatedAt`) |
| 3 | Recibo de cobro por id | PASS | 200, `recibo-cobro-C-000001.pdf` (cobro 1) |
| 4 | Recibo de cobro por uuid | PASS | 200 con el mismo contenido que por id |
| 5 | Trámite inactivo lleva marca de agua | PASS (sintético) | Render sintético con `cancelled=true` en las 2 páginas. No hay trámites inactivos en la BD de desarrollo (ver observación O4) |
| 6 | Trámite activo sin marca de agua | PASS | Trámite 71 (revisión visual, sin marca). Trámite 1 revisado solo por texto |
| 7 | Cobro nunca lleva marca de agua | PASS (sintético) | Cobro sintético sin fecha (revisión visual, sin marca). Cobro 1 revisado solo por texto |
| 8 | Notaría con logo | PASS | Notaría 1 tiene logo: se muestra en el encabezado |
| 9 | Notaría sin logo | PASS (sintético) | Render sintético con logo `null`: sin hueco ni error. La notaría 4 no tiene logo, pero no se generó recibo de ella (ver O4) |
| 10 | Footer en cada página | PASS | `Generado el 19/11/2026 10:52 by notarial.devrob.net - Pagina 1`; en sintético de 2 páginas, `Pagina 1` y `Pagina 2` |
| 11 | Importe con letra | PASS | Trámite 71: 26,400.00 → `VEINTISÉIS MIL CUATROCIENTOS PESOS 00/100 M.N.`. Sintéticos: 1200 → `MIL DOSCIENTOS PESOS 00/100 M.N.`; 5000.50 → `CINCO MIL PESOS 50/100 M.N.` |
| 12 | Código QR | PASS (parcial) | Aparece en el PDF. No se decodificó con lector (ver limitaciones) |
| 13 | Detalle de trámite con impuestos | PASS | Trámite 71: 5 impuestos en la tabla, impuestos totales 11,400.00 y total 26,400.00 (suma coincide) |
| 14 | Trámite sin impuestos | PASS con observación | Sin tabla de impuestos. Muestra además la línea `Impuestos totales $0.00` (ver O2) |
| 15 | Cobro sin fecha ni comentarios | PASS | Render sintético: `Fecha: Sin fecha`, `Fecha de pago: Sin fecha`, comentarios vacíos, sin `null` |
| 16 | Recibo no encontrado | PASS con desvío aceptado | 404 `Transaction not found` / `Payment not found` según ADR-007 (el PO pedía `Payment transaction not found`). Ver O3 |
| 17 | Multi-tenant | PASS | Token de notaría 1 contra trámite 31 y cobro 18 de notaría 3, por id y por uuid: 404 con el mismo cuerpo que un inexistente. Token de notaría 2 contra ids de notaría 1: 404 |
| 18 | ID inválido | PASS con desvío aceptado | `abc` → 400 `Invalid ID format` (PO: `Invalid id`). `0` y `-5` → 404 (PO: 400), según ADR-007. Ver O3 |
| 19 | UUID inválido | PASS con desvío aceptado | 21 caracteres y con punto → 400 `Invalid uuid format` (PO: `Invalid uuid`). `uuid//` → 404 de ruta (esperado por ADR-007) |
| 20 | Sin autenticación | PASS | Sin token → 401 en las 4 rutas; token inválido → 401 `Token inválido o expirado` |
| 21 | Acentos y especiales | PASS | `Iñaki Ángeles Ñúñez`, `Compraventa – Crédito hipotecario`, `ALM DISEÑOS EMPRESARIALES` y `Notario titular: LIC. ...` se ven bien, sin `?` ni cuadros |
| 22 | Tabla de impuestos muy larga | PASS (sintético) | 40 impuestos: 2 páginas, encabezado de tabla repetido, footer en ambas. No hay trámite real con más de 5 impuestos |

### No funcionales
| Criterio | Estado | Notas |
|----------|--------|-------|
| Solo lectura | PASS | Conteos antes y después: `payments_transactions` 44, `payments_transaction_details` 31, `payments_payments` 19, `payments_clients` 23 |
| Sin persistencia del PDF | PASS | No se escribió ningún PDF en disco ni en BD por la API (revisado en código) |
| Rendimiento | NO MEDIDO | Objetivo de arch: < 1.5 s por recibo de 1 página. No se midió (ver O4) |
| Consistencia de importes | PASS | `$1,400.00`, `$26,400.00`, `$5,000.50`: 2 decimales y separador de miles |
| Idioma | PASS | Contenido en español; mensajes de error de la API en inglés |
| Zona horaria | PASS | Footer 10:52 con UTC del contenedor 16:52 (UTC-6, `America/Mexico_City`) |
| Seguridad del contenido | PASS | Ningún id de notaría, ruta de archivo, token ni contraseña en el PDF |
| Formato | PASS | Carta vertical |

### Técnicos (arch)
| # | Área | Estado | Notas |
|---|------|--------|-------|
| Controller | 4 rutas con `auth`, `parseId`/`parseUuid`, 200/400/404/500 | PASS | Verificado en código y por API. Headers del 200 exactos: `Content-Type`, `Content-Disposition`, `Content-Length`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` |
| Service | Notaría inactiva → 500, logo faltante → se omite con log, total inconsistente → log y se muestra lo guardado | PASS | Código revisado. Cubierto por `ReceiptServiceTest` (pasa en la suite) |
| Renderer | Escapado, `isPhpEnabled=false`, `isRemoteEnabled=false`, `%PDF-`, `ob_start()` | PASS | Escapado comprobado con `<img>`, `<?php>` y `<script>` como texto literal. Remote y PHP no ejecutados |
| Repository | Sin repositories nuevos, filtro por `id_notary` | PASS | Las consultas son las de los services existentes; las pruebas de tenant lo confirman |
| Core | `Response::pdf()` con headers y validación de nombre | PASS | `ResponsePdfTest` pasa; headers verificados en API |
| Imágenes | `gd` con JPEG, `dom`, `mbstring` | PASS | `php -m` y `gd_info()` |
| Tests | Unit según lista de arch | PASS | Suite completa en verde; sin warnings nuevos |
| Seguridad | JWT en 4 rutas, 404 y nunca 403 entre notarías, nombre de archivo construido en servidor | PASS | Ver multi-tenancy y pruebas de 401 |

## Pruebas de API

Todas las llamadas fueron contra `http://localhost:8080/api/v3` con tokens de las notarías 1 y 2.

### GET /payment-transactions/{id}/receipt
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Trámite existente | id=1, notaría 1 | 200 PDF | 200 `recibo-tramite-T-000001.pdf` | PASS |
| Trámite de otra notaría | id=31 (notaría 3), token notaría 1 | 404 | 404 `Transaction not found` | PASS |
| Trámite con id no entero | `abc` | 400 | 400 `Invalid ID format` | PASS (desvío aceptado, O3) |
| Trámite con id negativo | `-5` | 404 (ADR-007) | 404 `Transaction not found` | PASS |
| Trámite inexistente | id=999999 | 404 | 404 `Transaction not found` | PASS |
| Trámite 71 (5 impuestos) | id=71 | 200 PDF | 200, total 26,400.00 | PASS |

### GET /payment-transactions/uuid/{uuid}/receipt
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Trámite existente | uuid=1jxlwn | 200 PDF | 200, mismo contenido que id=1 | PASS |
| Trámite de otra notaría | uuid=A9dkZc (notaría 3) | 404 | 404 `Transaction not found` | PASS |
| UUID de 21 caracteres | `abcdefghijklmnopqrstu` | 400 | 400 `Invalid uuid format` | PASS (desvío aceptado, O3) |
| UUID vacío | `uuid//` | 404 de ruta | 404 `Route not found` | PASS (ADR-007) |

### GET /payments/{id}/receipt
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Cobro existente | id=1, notaría 1 | 200 PDF | 200 `recibo-cobro-C-000001.pdf` | PASS |
| Cobro de otra notaría | id=18 (notaría 3) | 404 | 404 `Payment not found` | PASS |
| ID cero | `0` | 404 (ADR-007) | 404 `Payment not found` | PASS (desvío aceptado, O3) |
| Cobro inexistente | id=999999 | 404 | 404 `Payment not found` | PASS |
| Token de notaría 2 | id=1 | 404 | 404 `Payment not found` | PASS |

### GET /payments/uuid/{uuid}/receipt
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Cobro existente | uuid=KOiLNR | 200 PDF | 200, mismo contenido que id=1 | PASS |
| Cobro de otra notaría | uuid=Us2Gvq (notaría 3) | 404 | 404 `Payment not found` | PASS |
| UUID con punto | `ab.cd` | 400 | 400 `Invalid uuid format` | PASS |
| UUID inexistente | `zzzzzz` | 404 | 404 `Payment not found` | PASS |

### Sin autenticación
| Caso | Expected | Actual | Estado |
|------|----------|--------|--------|
| 4 rutas sin token | 401 | 401 | PASS |
| Token inválido | 401 | 401 `Token inválido o expirado` | PASS |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Trámite de notaría 3 pedido con token de notaría 1 (id y uuid) | PASS | 404, mismo cuerpo que inexistente |
| Cobro de notaría 3 pedido con token de notaría 1 (id y uuid) | PASS | 404, mismo cuerpo que inexistente |
| Token de notaría 2 (sin registros) contra ids de notaría 1 | PASS | 404 |
| Logo de notaría 1 en su propio recibo | PASS | Solo se usa el logo de la notaría del token |

## Observaciones (no bloqueantes)

- **O1 — "UN MILLÓN PESOS":** la ortografía correcta es "UN MILLÓN DE PESOS 00/100 M.N.". Se implementó literal al PO (ver `developer_notes.md`). Requiere confirmación del PO.
- **O2 — Trámite sin impuestos:** el PDF muestra `Impuestos totales $0.00` además de cuota y total. El escenario 14 del PO dice "solo la cuota y el total". Requiere confirmación del PO.
- **O3 — Desvíos de ADR-007:** mensajes de error (`Invalid ID format`, `Transaction not found`, `Payment not found`, `Invalid uuid format`), id `0` o negativo → 404 (PO: 400), y `uuid//` → 404 de ruta (PO: 400). Son decisiones de arch ya cerradas; se documentan para que el PO las conozca.
- **O4 — Pruebas sin datos reales:** no hay trámite inactivo en la BD de desarrollo (marca `CANCELADO` probada con render sintético), no hay trámite real con más de 5 impuestos (40 impuestos probados con render sintético), no se midió el tiempo de respuesta ni la memoria, no se decodificó el QR con lector y la notaría 4 (sin logo) no tiene recibos generados para probar el caso con datos reales.
- **O5 — Deprecación de PHPUnit:** ya existía antes de esta feature (configuración XML). No afecta a esta entrega.

## Bugs
Ninguno. No se creó `specs/18_payment_receipts_pdf/bugs/`.

## Conclusión
La feature cumple los criterios funcionales del PO con los desvíos ya acordados en arch (ADR-007) y los de la sección de observaciones. Los 4 endpoints responden como se especifica, el aislamiento entre notarías funciona, las rutas están protegidas con JWT, el contenido se escapa correctamente, la BD no cambia y la suite unitaria está en verde.

Las observaciones O1 y O2 requieren confirmación del PO. O4 son pruebas que quedan pendientes de datos reales; no bloquean la aprobación.

**Estado: APROBADO.** Pendiente de deploy: no hay migraciones en esta feature, así que no hay compuerta de BD.

## Fecha
2026-11-19
