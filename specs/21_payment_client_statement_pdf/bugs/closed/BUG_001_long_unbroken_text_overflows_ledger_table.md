# BUG_001: Un texto largo sin espacios desborda la tabla y corta las columnas de importes

## Severidad
**Media**. No hay pérdida ni alteración de datos en el servidor, pero el PDF entregado al cliente final queda con importes ilegibles (columna Saldo, y a veces Abono, fuera de la hoja).

## Estado
Closed (verificado por QA, 2026-11-21)

## Criterios incumplidos
- PO, Casos Edge: "Nombre de cliente o comentario muy largo: el texto se ajusta dentro de su celda sin romper la tabla."
- Arch, Renderer: "Tabla: ... texto largo ajustado en la celda."

## Pasos para reproducir
1. Tener un cliente con un trámite cuya `reference` (o un cobro cuyo `comments`) tenga 60 o más caracteres seguidos **sin espacios ni guiones** (por ejemplo, una URL pegada, un folio largo o texto sin separar).
2. `GET /api/v3/payment-clients/{id}/statement/pdf`
3. Abrir el PDF.

Reproducido en QA con la notaría de prueba (id 2):

| Caso | Contenido de `reference` | Resultado |
|------|--------------------------|-----------|
| Cliente 9211 | 20 y 40 caracteres seguidos | OK |
| Cliente 9211 | **60** caracteres seguidos (`ZZZZ…`) | Tabla más ancha que la hoja. **Saldo** fuera de la página y **Abono** cortada |
| Cliente 9209 | **150** caracteres seguidos en referencia y en comentario | Cargo, Abono y Saldo fuera de la hoja: no se ve ningún importe de esas filas |
| Cliente 9212 | Nombre del cliente de **200** caracteres sin espacios | El nombre sale cortado en el borde derecho (la tabla de movimientos no se afecta) |

## Resultado esperado
El texto se parte dentro de su celda (o se ajusta de otro modo) y las seis columnas quedan completas dentro de la hoja.

## Resultado actual
La celda de Concepto crece hasta el ancho de la cadena. La tabla se ensancha más allá de los 612 pt de la hoja y Dompdf recorta lo que sale. Los totales del resumen y la leyenda sí quedan bien, pero los importes por fila de esa tabla no se ven.

## Causa probable
En `src/Module/PaymentStatement/Renderer/templates/statement.php` el CSS usa solo `word-wrap: break-word` en `.ledger td` (línea 48). Dompdf no parte una cadena sin puntos de corte dentro de una tabla de layout automático. Falta `table-layout: fixed` en `.ledger` (con anchos fijos para las columnas de fecha, folio y los tres importes, y Concepto con el resto), y para el nombre del cliente una regla equivalente en `.kv`.

La prueba `StatementPdfRendererTest::testLongTextInACellDoesNotBreakTheRender` usa `'Texto-muy-largo-sin-espacios-áéíóú-'` repetido: tiene guiones, que son puntos de corte, y además solo verifica que el PDF se genere sin error, no su maquetación. Por eso no detecta el problema.

## Sugerencia de verificación del fix
- Con `table-layout: fixed`, repetir los casos de la tabla de arriba y comprobar visualmente que las seis columnas caben en la hoja.
- Agregar a la prueba de humo una cadena sin guiones ni espacios (≥ 150 caracteres) y comprobar con `pdftotext` que el último importe de la fila aparece en la salida.
- Verificar que el multipágina (150 filas) y el encabezado repetido siguen igual.

## Reproducción rápida (SQL, notaría 2 "DE PRUEBA", vacía en desarrollo)
```sql
INSERT INTO payment_procedures (id_notary, procedure_name, id_status) VALUES (2, 'Compraventa', 1);
SET @proc = LAST_INSERT_ID();
INSERT INTO payments_clients (id_notary, full_name, phone, email, id_status) VALUES (2, 'QA Largos', '', '', 1);
SET @c = LAST_INSERT_ID();
INSERT INTO payments_transactions (uuid,id_notary,id_client,id_procedure,reference,date,responsible,fee,taxes,total,comments,id_status)
VALUES ('qalong1',2,@c,@proc,REPEAT('Z',60),'2026-03-03 10:00:00','x',333,0,333,'',1);
SELECT @c;
-- Limpiar: DELETE FROM payments_transactions WHERE id_notary=2; DELETE FROM payments_clients WHERE id_notary=2; DELETE FROM payment_procedures WHERE id_notary=2;
```
Token de la notaría 2: generar un JWT con `notaryId = 2`.

## Evidencia
- Clientes 9211 (60 caracteres), 9209 (150 caracteres) y 9212 (nombre de 200 caracteres) de la notaría 2. Fixtures de QA ya eliminados; el SQL de arriba los recrea.

## Solución Aplicada
**Estado:** Resolved (pendiente de verificación y cierre por QA)

**Cambio** en `src/Module/PaymentStatement/Renderer/templates/statement.php` (solo CSS):
- `.ledger` y `.kv` pasan a `table-layout: fixed`, y `.kv td` suma `word-wrap: break-word`. Con layout automático Dompdf nunca parte una cadena sin puntos de corte; con layout fijo el ancho de la tabla ya no depende del contenido y el texto se corta dentro de su celda.
- Los anchos de columna pasan de píxeles a **porcentajes**: Fecha 12 %, Folio 10 %, Cargo, Abono y Saldo 15 % cada una, Concepto el resto (33 %); en `.kv`, la etiqueta 18 %. Motivo: en `table-layout: fixed` Dompdf 3.1.6 ignora los anchos absolutos (`Cellmap::calculate_column_widths` los toma como 0 porque usa `$frame_min`, que en layout fijo vale 0) y reparte las columnas en partes iguales; los porcentajes sí se respetan. Solo cuenta la fila del encabezado.
- Los tres importes caben hasta `$99,999,999.99` (comprobado con un cliente de prueba).

**Prueba de regresión** en `StatementPdfRendererTest::testUnbreakableTextStaysInsideThePage` (4 casos: 60 caracteres, 150, una URL larga y un nombre de cliente de 200). Mide con el callback `end_frame` de Dompdf el borde derecho de cada frame y exige que no pase del margen de la hoja (582 pt). El árbol de frames se libera al terminar `render()`, por eso se mide durante el pintado.
- Sin el fix: **falla** los 4 casos (700, 1207, 1071 y 1361 pt).
- Con el fix: pasan (582 pt).

**Verificación**
- Suite completa: 1084 tests, 2171 assertions, en verde (4 nuevos).
- Visual (rasterizado con poppler): los casos de la tabla de "Pasos para reproducir" ahora parten el texto dentro de la celda y se ven las seis columnas; el nombre de 200 caracteres se ajusta en 3 líneas; un trámite con referencia normal sigue igual; cliente real (notaría 1, con logo y rango) sin cambios.
- Efecto colateral aceptado: con las columnas porcentuales Concepto queda más angosto (≈33 % del ancho útil, antes ≈48 %). Los conceptos normales ("Procedimiento – referencia") siguen cabiendo, y los largos se parten en varias líneas.
- No se tocó Receipt, el servicio, el libro ni el JSON de la spec 19.

## Verificación de QA (2026-11-21): CERRADO
Commit verificado: `a0bc058`. Se recrearon los fixtures en la notaría 2 y se pidió el PDF por la API (los fixtures ya se eliminaron; checksums de tablas idénticos a los del inicio).

| Caso | Resultado |
|------|-----------|
| 60 caracteres seguidos en referencia | PASS. Se parte en 2 líneas dentro de Concepto; Cargo y Saldo visibles ($333.00 / $333.00) |
| 150 caracteres seguidos en referencia | PASS. 5 líneas dentro de la celda; importes visibles |
| URL de 150 caracteres en comentario de un cobro | PASS. Se parte en la celda; Abono $34.56 y Saldo $1,200.00 visibles |
| Nombre de cliente de 200 caracteres, teléfono de 20 y correo de 85 | PASS. El nombre ocupa 3 líneas dentro de la hoja; la tabla de movimientos no se afecta |
| Importes grandes (`$99,000,000.00`, `$99,999,999.99`) | PASS. Las seis columnas caben, sin partir importes |
| Multipágina (150 filas, 6 páginas) | PASS. Encabezado de columnas repetido, filas sin partir, footer en las 6 páginas |

- **Medición objetiva:** con `pdftotext -bbox`, el borde derecho máximo de todo el texto es 577.8 pt en los 5 PDF (límite 582 pt; hoja de 612 pt). Antes del fix las celdas llegaban a 700–1361 pt.
- **Suite completa:** 1084 tests, 2171 assertions, en verde (incluye los 4 casos nuevos de `testUnbreakableTextStaysInsideThePage`).
- **Regresión:** 44/44 comparaciones PDF contra JSON de la spec 19 (22 clientes reales de la notaría 1, sin filtro y con rango): `totalCharges`, `totalPayments` y `balance` idénticos.
- **Revisión visual:** acentos, encabezado, resumen y leyenda sin cambios.

**Observación cosmética, no bloqueante y anterior al fix:** los encabezados "Cargo", "Abono" y "Saldo" quedan alineados a la izquierda sobre importes alineados a la derecha, porque `.ledger th { text-align: left }` pesa más que `.num { text-align: right }`. Ya estaba en `002a1d1`. Se puede corregir con `.ledger th.num { text-align: right }` en una próxima iteración.
