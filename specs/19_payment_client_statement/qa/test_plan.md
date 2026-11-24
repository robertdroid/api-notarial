# Plan de Pruebas: 19_payment_client_statement

## Alcance
Estado de cuenta de cliente (solo lectura) en un endpoint `GET`:
- `/api/v3/payment-clients/{id}/statement?from=YYYY-MM-DD&to=YYYY-MM-DD`

Incluye el módulo `src/Module/PaymentStatement/` (Controller, Service, Repository, Entities, DTOs), la ruta, el registro en `bootstrap.php`, `docs/openapi.yaml` y `README.md`.

Fuera de alcance: PDF del estado de cuenta, migración del frontend, retiro de v1, paginación, cambios de esquema (ver `po/story.md`).

## Documentos base
- `po/acceptance_criteria.md` (21 escenarios, validaciones, casos edge, criterios de rechazo)
- `arch/technical_acceptance_criteria.md` (Controller, Service, Repository, Entity/DTO, Tests, Código, Performance, Seguridad, Documentación)
- `arch/developer_notes.md` (decisiones cerradas: defaults del PO aprobados, filtro de rango en SQL, sin migración de índices, cobros sin fecha al final)

## Tipos de pruebas
| Tipo | Detalle |
|------|---------|
| Unitarias | Suite completa PHPUnit (987 tests), incluidos los 41 de `tests/Module/PaymentStatement/**` |
| API funcional | `curl` contra `localhost:8080` con JWT de notarías 1, 2 y 3: los 21 escenarios del PO, validaciones, bordes de fecha, 401 y métodos no permitidos |
| Datos sembrados | La BD de desarrollo no tiene trámites cancelados, cobros sin fecha ni movimientos con hora. Se sembraron clientes `QA19 *` (ids ≥ 9000) con esos casos, incluyendo filas de **otra notaría con el mismo `id_client`** para probar que no alteran totales. Todo se eliminó al final |
| Multi-tenancy | Tokens de notarías 1, 2 y 3 cruzados contra clientes de las otras notarías |
| Solo lectura | `CHECKSUM TABLE` de las 4 tablas antes y después de toda la ronda de pruebas de API |
| Verificación cruzada | Saldo esperado calculado con SQL independiente para los 22 clientes reales de la notaría 1 en 4 combinaciones de filtros (88 comparaciones) contra la API |
| Rendimiento | Cliente sembrado con 500 trámites (450 activos) + 500 cobros; latencia por HTTP, tiempo en PHP sin Apache y comparación contra endpoints simples |
| EXPLAIN | Las 4 consultas contra MariaDB 11.4 |
| Revisión de código | Controller, Service, Repository, DTOs, Entities; búsqueda de `BETWEEN`, `DATE(`, escrituras y `strict_types` |
| Documentación | OpenAPI, README y `specs/_index.md` |

## Entorno
- Docker: `notarial-api` (PHP 8.3.37), `notarial-db` (MariaDB 11.4), rama `feature/19-payment-client-statement`
- Migración de spec 17 (`payments_payments` InnoDB + índice `(id_notary, id_client)`) **ya aplicada** en este entorno
- Los JWT se generaron con `JwtService` del propio proyecto (no hay credenciales de usuarios; no se cambió ninguna contraseña)
