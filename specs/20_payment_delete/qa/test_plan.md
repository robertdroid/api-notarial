# Plan de Pruebas: 20_payment_delete

## Alcance
Eliminar un pago (abono) con borrado físico:
- `DELETE /api/v3/payments/{id}`

Incluye `PaymentController::destroy`, `PaymentService::delete`, `PaymentRepository::delete`, la ruta, los tests unitarios y `docs/openapi.yaml`. También los efectos sobre estado de cuenta (spec 19), recibos PDF (spec 18) e idempotencia por UUID (spec 17).

Fuera de alcance: botón/confirmación en `notarial-v3`, anulación lógica, auditoría, eliminación de cargos, eliminación masiva (ver `po/story.md`).

## Documentos base
- `po/acceptance_criteria.md` (9 escenarios, validaciones, casos edge, criterios de rechazo)
- `arch/technical_acceptance_criteria.md` (Controller, Service, Repository, Tests, Código, Seguridad, Documentación; sección "Verificación QA" E1–E9)
- `arch/implementation_plan.md` (ADR-001 `rowCount()`, ADR-002 alcance de tests: el SQL real y el HTTP se verifican aquí, no con tests automáticos)

## Tipos de pruebas
| Tipo | Detalle |
|------|---------|
| Unitarias | Suite completa PHPUnit (989 tests), incluidos los 2 casos nuevos de `PaymentServiceTest::testDelete*` |
| API funcional | `curl` contra `localhost:8080` con JWT de notarías 1, 2 y 3: los 9 escenarios del PO, validaciones de `{id}`, 401, métodos y rutas vecinas |
| Datos sembrados | Clientes y abonos `QA20*` (ids ≥ 9000): cliente activo, inactivo y con un solo abono; abono sin fecha; abono de la notaría 2 con **mismo `uuid` y mismo `id_client`** que uno de la notaría 1; cargo de otra notaría con el mismo `id_client`. Todo se eliminó al final |
| Multi-tenancy | Tokens de notarías 2 y 3 contra un abono de la notaría 1; verificación en BD de que sigue existiendo; borrado en la notaría 1 sin tocar el abono homónimo de la 2 |
| Concurrencia | 6 rondas de 4 `DELETE` simultáneos sobre el mismo abono |
| Efectos cruzados | Estado de cuenta, ambos recibos PDF y `POST` con el uuid borrado |
| Integridad de BD | `CHECKSUM TABLE` de `payments_payments`, `payments_clients`, `payments_transactions` y conteos antes y después de sembrar y limpiar |
| Revisión de código | Diff de la rama contra `develop`: SQL con `id_notary`, sin transacciones, sin clases/migraciones nuevas, `php -l` |
| Documentación | OpenAPI validado con un parser YAML (`js-yaml`), sin `operationId` duplicados y con `$ref` resueltos |

## Entorno
- Docker: `notarial-api` (PHP 8.3.37), `notarial-db` (MariaDB 11.4), rama `feature/20-payment-delete`
- `payments_payments` es InnoDB (migración de spec 17 aplicada)
- Los JWT se generaron con el `JwtService` del propio proyecto (no hay credenciales de usuarios; no se cambió ninguna contraseña)
