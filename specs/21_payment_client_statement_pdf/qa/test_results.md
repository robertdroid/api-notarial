# Resultados de Pruebas: Estado de Cuenta de Cliente en PDF

## Resumen
- **Estado:** RECHAZADO
- **Tests Ejecutados:** 1080 unitarias/humo + 23 escenarios del PO + 44 comparaciones PDF contra JSON + 20 casos de API adicionales
- **Tests Pasados:** 1080/1080 unitarias; 23/23 escenarios del PO (el 23 con reserva por BUG_002); 44/44 comparaciones PDF contra JSON. El **caso edge de texto largo FALLA** (BUG_001)
- **Bugs Encontrados:** 2 (ambos de severidad Media, abiertos en `bugs/open/`)

Los 23 escenarios del PO pasan. El rechazo se debe a dos bugs abiertos: uno de maquetación con texto largo (BUG_001, incumple un caso edge del PO y un criterio técnico) y uno de robustez con volumen alto (BUG_002, incumple el escenario 23 en un caso extremo y requiere decisión del PO).

## Pruebas Unitarias
- **Resultado:** PASS. `OK, but there were issues!`: 1080 tests, 2163 assertions, 1 PHPUnit Deprecation (ajena a la feature)
- Incluye sin modificar las pruebas de `Receipt` y de `PaymentStatement` (JSON). Ningún archivo de `src/Module/Receipt`, `PaymentStatementService`, `ClientStatement`, `StatementResponse` ni `StatementRepository` cambió en la rama
- **Cobertura:** no medida (no hay Xdebug/pcov configurado en el contenedor)
- **Observación:** `testLongTextInACellDoesNotBreakTheRender` usa un texto con guiones y solo verifica que se genere un PDF. No habría detectado BUG_001

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | PDF sin filtros | PASS | Cliente 5 (real) y cliente de prueba con 5 cargos y 4 abonos. 200, `application/pdf`, `inline`, empieza con `%PDF-`, periodo "Todos los movimientos", sin "Saldo anterior". Totales idénticos al JSON |
| 2 | Rango `from` y `to` | PASS | "Del 01/03/2026 al 31/03/2026", primera fila "Saldo anterior" $200.00 = `pendingAmount` del JSON, solo movimientos del rango |
| 3 | Solo `from` o solo `to` | PASS | "Desde 01/03/2026" con "Saldo anterior"; "Hasta 31/03/2026" sin esa fila |
| 4 | Libro cronológico con saldo corrido | PASS | Saldos 300 → 200 → 1,200 → 800 → 1,000 → 1,500 → 1,000 → 1,050 → 1,025 correctos y saldo final = `balance`. La secuencia literal 1,000 → 600 → 800 está cubierta por `StatementLedgerBuilderTest` |
| 5 | Cargo antes que abono el mismo día | PASS | 20/03: trámite `T-` antes que cobro `C-` (el cobro se insertó con la hora más temprana) |
| 6 | Trámite cancelado excluido | PASS | Trámite cancelado de $1,000 ausente: sin fila ni marca de agua, y fuera del saldo (cargos $2,050 = JSON) |
| 7 | Cliente inactivo | PASS | `id_status`=2: 200 con el PDF completo |
| 8 | Cliente sin movimientos | PASS | "Sin movimientos en el periodo", resumen $0.00, "SIN ADEUDO" |
| 9 | Rango sin movimientos con saldo anterior | PASS | Fila "Saldo anterior" $800.00, "Sin movimientos en el periodo", saldo final $800.00, "SALDO PENDIENTE" |
| 10 | Saldo pendiente | PASS | "SALDO PENDIENTE", importe y letra (p. ej. "MIL VEINTICINCO PESOS 00/100 M.N.", "DOCE MIL QUINIENTOS CINCUENTA PESOS 00/100 M.N.", "NOVENTA Y NUEVE PESOS 99/100 M.N."). El importe de $1,200.00 del ejemplo no se ejecutó literal |
| 11 | Saldo a favor | PASS | "SALDO A FAVOR" con $300.00 y "TRESCIENTOS PESOS 00/100 M.N."; el saldo de la fila y el saldo final llevan signo `-$300.00` además de la leyenda |
| 12 | Sin adeudo | PASS | "SIN ADEUDO" sin importe con letra |
| 13 | Cobro sin fecha, sin filtros | PASS | Al final, fecha "—", suma a abonos ($250.00) y al saldo |
| 14 | Cobro sin fecha, con filtro | PASS | Con `from` y con `to` no aparece ni suma |
| 15 | Cliente inexistente o de otra notaría | PASS | 404 `{"error":"Client not found"}` en ambos casos. Cuerpos byte a byte idénticos (md5 igual) |
| 16 | Encabezado de la notaría | PASS | Notaría 1: logo, titular, RFC, dirección, ciudad y teléfono. Notaría 2 (logo registrado pero archivo inexistente): se omite el logo, el PDF se genera y se registra `[pdf] notary 2 has a logo registered but the file does not exist` |
| 17 | Muchos movimientos | PASS | 150 filas = 6 páginas. Encabezado de columnas repetido, filas sin partir, resumen y leyenda completos al final |
| 18 | Footer | PASS | `Generado el 20/11/2026 16:32 by notarial.devrob.net - Pagina n` en las 6 páginas, `n` desde 1, hora en `America/Mexico_City` (UTC−6, el servidor registra UTC) |
| 19 | `id` no numérico, cero o negativo | PASS | `abc` → 400 `Invalid ID format`; `0` y `-3` → 404 `Client not found` |
| 20 | Fechas inválidas | PASS | `01/03/2026`, `2026-3-1`, `2026-02-31` → 400 `Validation failed` con `errors.from`. Sin PDF |
| 21 | Rango invertido | PASS | 400 con `errors.to` ("To must not be before from") |
| 22 | Sin autenticación | PASS | Sin token y con token inválido → 401 |
| 23 | Falla al generar el PDF | PASS con reserva | Notaría del token inactiva: 500 JSON `Could not generate statement`, sin `Content-Disposition` ni PDF, y con el 404 de cliente inexistente antes. **No cubre el agotamiento de memoria (BUG_002)** |

**No funcionales y casos edge del PO**

| Criterio | Estado | Notas |
|----------|--------|-------|
| Solo lectura | PASS | `CHECKSUM TABLE` idéntico antes/después en 8 tablas tras 44 comparaciones, errores y volumen |
| Multi-tenant | PASS | Ver sección Multi-tenancy |
| Consistencia con el JSON | PASS | 44/44: `totalCharges`, `totalPayments`, `balance` y, con rango, `pendingAmount`, en los 22 clientes reales de la notaría 1 |
| Montos y fechas | PASS | `$1,234.56` y `dd/mm/yyyy` |
| Tipografía | PASS | Acentos, ñ y mayúsculas acentuadas ("SUBDIVISIÓN Y SERVIDUMBRE", "Ñandú") correctos |
| Cabeceras | PASS | `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline; filename="estado-de-cuenta-C-000005.pdf"` |
| Parámetro vacío y desconocidos | PASS | `from=` se trata como no enviado y `foo=bar` se ignora (mismo PDF) |
| Último día del rango incluido | PASS | `to=2026-03-20` y `from=to=2026-03-15` incluyen movimientos de las 08:00 y 18:00 |
| Sin teléfono ni correo | PASS | "—" |
| Procedimiento eliminado o sin referencia | PASS | Solo la referencia, o "—" |
| Cobro sin comentario | PASS | "Abono" |
| HTML y caracteres especiales | PASS | `<script>`, `<img onerror>`, `&`, comillas y `<b>` salen como texto literal (nombre, teléfono, correo, referencia, comentario) |
| Decimales de punto flotante | PASS | 100 + 10.10 + 20.20 − 30.30 − 0.01 = $99.99 exacto |
| **Texto largo ajustado en su celda** | **FAIL** | **BUG_001**: con ≥ 60 caracteres sin espacios la tabla se sale de la hoja y se cortan Abono y Saldo |
| Rendimiento | Con reserva | 150 filas: 6.3 s. 450 filas: 7.5 s (con aviso en log). 700 filas: fatal de memoria, **BUG_002** |

### Técnicos
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Controller: ruta con `auth`, orden `parseId` → `StatementQuery` → armado → render, 400/404/401, `show()` sin cambios | PASS | Verificado por API y por diff: solo se añade `pdf()` |
| 2 | Controller: `Throwable` → `error_log('[statement] ...')` + 500 JSON, sin cabeceras de PDF | PASS | Verificado con notaría inactiva y en `docker logs`. El agotamiento de memoria no es `Throwable` (BUG_002) |
| 3 | `StatementPdfService`: usa solo `PaymentStatementService::build()`, `null` sin más consultas, notaría inactiva → excepción, cliente inactivo se consulta, cliente borrado entre lecturas, reloj inyectable, importe con letra, saldo inconsistente solo se registra | PASS | Revisado en código y con pruebas unitarias en verde; API confirma 404 sin más lecturas y 500 por notaría |
| 4 | `StatementLedgerBuilder`: orden total `(fecha, tipo, id)`, cobros sin fecha al final, centavos enteros, "Saldo anterior" solo con `from`, folios `T-`/`C-`, conceptos | PASS | Verificado en API (E2–E5, E13) y en código. Clase no `final` documentada: la usan las pruebas |
| 5 | `BalanceStatus` en centavos (`-0.001` es cero) | PASS | `BalanceStatusTest` |
| 6 | Repositories: sin nuevos, sin cambios en `StatementRepository`, sin escrituras ni migraciones | PASS | `git diff` limpio en esos archivos; checksums idénticos |
| 7 | DTO/Entity: `readonly`, enums respaldados, `periodLabel()` y `fileName()`, se reutilizan `ReceiptNotary`/`ReceiptClient`, contrato JSON sin cambios | PASS | Las 4 variantes de periodo verificadas por API |
| 8 | Renderer: opciones Dompdf seguras (sin remoto, sin PHP, `chroot`), `ob_start` y limpieza, valida `%PDF-` | PASS | Revisado en código; `DompdfDocumentRendererTest` en verde |
| 9 | Renderer: footer en todas las páginas con `page_script` | PASS | 6 de 6 páginas |
| 10 | Renderer: todo valor de usuario escapado, solo data URIs | PASS | Revisión de la plantilla (todos pasan por `H::escape`/`H::orDash`) y prueba con XSS |
| 11 | Renderer: `thead` repetido, `tr` sin partir, resumen y leyenda juntos al final | PASS | Verificado en 6 páginas |
| 12 | Renderer: **texto largo ajustado en la celda** | **FAIL** | BUG_001. Falta `table-layout: fixed` |
| 13 | Renderer: saldo negativo con signo `-`, "SALDO A FAVOR" con valor absoluto, sin QR ni marca de agua ni cancelados | PASS | |
| 14 | Tests: suite verde con pruebas de Receipt y PaymentStatement sin modificar | PASS | 1080/1080 |
| 15 | Código: `strict_types`, PSR-12, Controller delega, sin cambios en Receipt | PASS | Todos los `.php` de clase tienen `declare(strict_types=1)`. La plantilla `statement.php` no, igual que `receipt.php` (convención del proyecto, no se considera defecto) |
| 16 | Performance: sin N+1, spike documentado, `memory_limit` documentado, aviso en log > 400 filas | PASS | Aviso confirmado con 450 y 700 filas |
| 17 | Performance: "Sin paginación ni límite" (decisión del PO) | Con reserva | Cumple lo pedido, pero ver BUG_002: ADR-005 sigue abierta |
| 18 | Seguridad: JWT, multi-tenant, no se revela otra notaría, `Content-Disposition` seguro, `no-store`, solo lectura | PASS | |
| 19 | Seguridad: no exponer rutas internas en errores | **FAIL (BUG_002)** | Con `display_errors=1` (contenedor sin `php.ini`) el fatal de memoria muestra `/var/www/html/vendor/dompdf/...` en una respuesta 200 |

## Pruebas de API

### GET /api/v3/payment-clients/{id}/statement/pdf
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Sin filtros | cliente 5, token notaría 1 | 200 PDF, headers seguros | 200, `application/pdf`, `inline; filename="estado-de-cuenta-C-000005.pdf"`, `no-store`, `nosniff`, 39,694 bytes | PASS |
| Rango | `?from=2026-03-01&to=2026-03-31` | 200, saldo anterior $200 | 200, "Del 01/03/2026 al 31/03/2026", saldo anterior $200.00 | PASS |
| Solo from / solo to | `?from=…` / `?to=…` | 200 | 200, "Desde…" / "Hasta…" | PASS |
| `from` vacío | `?from=` | como sin filtro | PDF idéntico (39,694 bytes) | PASS |
| Parámetro desconocido | `?foo=bar` | ignorado | 200, mismo PDF | PASS |
| `from` = `to` | `?from=2023-10-13&to=2023-10-13` | 200 | 200 | PASS |
| id no numérico | `/abc/statement/pdf` | 400 | 400 `Invalid ID format` | PASS |
| id cero | `/0/…` | 404 | 404 `Client not found` | PASS |
| id negativo | `/-3/…` | 404 | 404 `Client not found` | PASS |
| Cliente inexistente | `/999/…` | 404 | 404 `Client not found` | PASS |
| Cliente de otra notaría | `/15/…` (notaría 3) con token notaría 1 | 404 igual | 404 `Client not found` | PASS |
| Fecha formato `dd/mm/yyyy` | `?from=01/03/2026` | 400 | 400 `Validation failed`, `errors.from` | PASS |
| Fecha sin ceros | `?from=2026-3-1` | 400 | 400, `errors.from` | PASS |
| Fecha inexistente | `?from=2026-02-31` | 400 | 400, `errors.from` | PASS |
| `to` inválido | `?to=foo` | 400 | 400, `errors.to` | PASS |
| Rango invertido | `?from=2026-04-01&to=2026-03-01` | 400 | 400, `errors.to` | PASS |
| Sin token | — | 401 | 401 `Token no proporcionado` | PASS |
| Token inválido | `abc.def.ghi` | 401 | 401 `Token inválido o expirado` | PASS |
| Notaría del token inactiva | cliente existente | 500 JSON, sin PDF | 500 `Could not generate statement`, sin `Content-Disposition` | PASS |
| Notaría inactiva, cliente inexistente | cliente 99999 | 404 | 404 `Client not found` | PASS |
| Texto largo sin espacios (60 / 150 caracteres) | referencia/comentario | ajustado en celda | Tabla desborda la hoja, se pierden Abono y Saldo | **FAIL (BUG_001)** |
| 700 filas | cliente con 700 trámites, 128M | 500 JSON | **200** con HTML "Fatal error" y ruta del servidor | **FAIL (BUG_002)** |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Notaría 1 pide cliente de la notaría 2 | PASS | 404 `Client not found` |
| Notaría 1 pide cliente inexistente | PASS | 404 con cuerpo **byte a byte idéntico** al anterior (md5 igual) |
| Notaría 2 pide cliente 5 (notaría 1) | PASS | 404 |
| Notaría 2 pide cliente 15 (notaría 3) | PASS | 404 |
| Encabezado del PDF | PASS | Cada PDF muestra los datos de la notaría del token (notaría 1 con logo; notaría 2 sin logo) |
| Notaría inactiva en el token con cliente inexistente | PASS | 404 antes de consultar la notaría |

## Conclusión
La feature cumple los 23 escenarios del PO, la consistencia total con el JSON de la spec 19 (44/44 casos con datos reales), el multi-tenant, el solo-lectura y la suite completa (1080 tests). El PDF se ve profesional: acentos, logo, saldo corrido, leyendas, importe con letra, encabezado repetido y footer correctos.

**Se rechaza** porque hay dos bugs abiertos:

1. **BUG_001 (Media)**: un texto de 60 o más caracteres seguidos sin espacios en una referencia o comentario desborda la tabla y deja fuera de la hoja las columnas de importes. Incumple el caso edge del PO y un criterio técnico. La prueba existente no lo detecta porque usa guiones. Fix probable: `table-layout: fixed` con anchos de columna en `.ledger` (y ajuste del nombre del cliente).
2. **BUG_002 (Media, requiere decisión del PO)**: con unas 600 filas o más, el agotamiento de memoria responde **200** con el HTML del error de PHP y la ruta del servidor, no el 500 JSON del escenario 23. Está documentado como riesgo (ADR-005) pero la descripción de "500 vacío" no coincide con lo observado. Requiere elegir entre subir `memory_limit`, un límite con 422 o paginar, y fijar `display_errors=0` en la imagen. Hoy no afecta a datos reales (máximo 6 trámites por cliente en desarrollo).

**No se mergea el PR #21.** Tras la corrección de BUG_001 (y la decisión sobre BUG_002), pasar a `bugs/resolved/` para su verificación y cierre.

Limpieza: los datos de prueba de la notaría 2 se eliminaron (checksums de tablas idénticos a los originales) y la notaría 2 quedó activa. Quedaron en los contenedores dos archivos temporales sin riesgo: `/tmp/mint.php` en `notarial-api` y `/tmp/fixtures.sql`, `/tmp/fx2.sql`, `/tmp/fx3.sql` en `notarial-db`.

## Fecha
2026-11-20
