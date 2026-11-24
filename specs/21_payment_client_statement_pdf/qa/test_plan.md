# Plan de Pruebas: Estado de Cuenta de Cliente en PDF

## Alcance
`GET /api/v3/payment-clients/{id}/statement/pdf?from=&to=` (módulo `PaymentStatement`, más `src/Shared/Pdf/`).

Dentro del alcance:
- Los 23 escenarios de `po/acceptance_criteria.md` y los casos edge del PO.
- Los criterios de `arch/technical_acceptance_criteria.md` (controller, services, ledger, renderer, seguridad, performance).
- Coincidencia de totales del PDF con el JSON de la spec 19.
- Multi-tenancy, solo lectura y ausencia de regresión en recibos (spec 18) y estado de cuenta JSON (spec 19).

Fuera del alcance: migración de Receipt a `Shared/Pdf` (Fase 8, opcional y aparte), pruebas de integración con BD automatizadas (el proyecto no tiene esa infraestructura).

## Entorno
- Docker (`api` PHP 8.3.37, `memory_limit` 128M, `db` MariaDB 11.4), rama `feature/21-payment-client-statement-pdf` (commit `002a1d1`).
- Token JWT generado con `JwtService` de la propia app (notaría 1 y notaría 2), sin tocar contraseñas de usuarios.
- Fecha de prueba: 2026-11-20.

## Tipos de pruebas

| Tipo | Cómo |
|------|------|
| Unitarias y de humo | `docker-compose exec api ./vendor/bin/phpunit` (suite completa) |
| API funcional | `curl` contra `localhost:8080`: códigos, cabeceras y cuerpo |
| Contenido del PDF | `pdftotext` (texto, orden de filas, importes) y rasterizado con `pdftoppm` para revisión visual (acentos, logo, columnas, multipágina, footer) |
| Consistencia con el JSON | Script que compara `totalCharges`, `totalPayments`, `balance` y `pendingAmount` del JSON contra el PDF en los 22 clientes reales de la notaría 1, sin filtro y con rango (44 casos) |
| Multi-tenancy | Tokens de las notarías 1 y 2 contra clientes de las notarías 1, 2 y 3; comparación de cuerpos del 404 |
| Solo lectura | `CHECKSUM TABLE` de las tablas de pagos, trámites, procedimientos, notarías y usuarios antes y después de las ejecuciones |
| Falla de generación | Notaría del token marcada inactiva de forma temporal (restaurada) |
| Seguridad | Textos con `<script>`, `<img onerror>`, comillas y HTML en nombre, teléfono, correo, referencia y comentarios |
| Volumen | Clientes con 150, 450 y 700 filas (tiempo, `memory_limit` 128M, logs) |

## Datos de prueba
Los datos reales de la notaría 1 se usaron solo para lecturas. Los escenarios que necesitan datos específicos (cancelados, cobro sin fecha, saldo a favor, saldo cero, cliente inactivo, XSS, volumen) se crearon en la notaría 2 ("DE PRUEBA", que estaba vacía) y se eliminaron al terminar. Los checksums de las tablas quedaron idénticos a los del inicio.

## Criterio de salida
Aprobado si: suite verde, 23 escenarios PASS, criterios técnicos cumplidos y sin bugs abiertos. Rechazado si hay bugs pendientes.

## Segunda ronda (2026-11-21)
Alcance: verificación de BUG_001 (commit `a0bc058`) y regresión. BUG_002 se toma como problema de ambiente por decisión del usuario y se re-probará más adelante en otras condiciones.
- Suite completa (`phpunit`).
- Fixtures en la notaría 2 (60/150 caracteres, URL larga, nombre de 200, importes de hasta `$99,999,999.99`, 150 filas) vía API; medición del borde derecho con `pdftotext -bbox` y revisión visual con `pdftoppm`.
- Regresión PDF contra JSON en los 22 clientes reales de la notaría 1, sin filtro y con rango (44 casos).
- Solo lectura: `CHECKSUM TABLE` antes y después; fixtures eliminados.
