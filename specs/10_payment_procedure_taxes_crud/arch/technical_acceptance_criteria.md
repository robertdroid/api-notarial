# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
`specs/10_payment_procedure_taxes_crud/po/acceptance_criteria.md`, con los ajustes de `implementation_plan.md`:
- **ADR-005**: POST → 201 **sin body**; PUT → **204** (no 200 con body); GET → array plano (sin `data`).
- **ADR-006**: el GET también excluye taxes con `payment_taxes.id_status = 2`.
- **ADR-007**: el bulk se permite siempre; los errores de items en bulk → 400 por índice.

## Criterios por Capa

### Database
- [ ] Migración `.db/pending/20261110_170000_add_unique_procedure_tax_to_payment_procedure_taxes.sql` con secciones UP/DOWN y pre-check de duplicados en comentario
- [ ] `SHOW INDEX FROM payment_procedure_taxes` muestra `uq_payment_procedure_taxes_procedure_tax` (Non_unique = 0) sobre `(id_procedure, id_tax)`
- [ ] Migración aplicada en local y movida a `.db/applied/`

### Core
- [ ] `Response::conflict(string)` → 409 `{ "error": "..." }`
- [ ] `Response::unprocessable(string)` → 422 `{ "error": "..." }`

### Controller Layer
- [ ] `GET /api/v3/payment-procedures/{id}/taxes` → 200 con `[{ procedureId, taxId, taxName, defaultValue }]`
- [ ] `GET` de procedimiento sin taxes → 200 `[]` (no 404)
- [ ] `GET` de procedimiento **inactivo** → 200 (la lectura está permitida)
- [ ] `POST /api/v3/payment-procedures/{id}/taxes` single `{taxId, defaultValue?}` → 201 sin body
- [ ] `POST` bulk `{taxes: [...]}` → 201 sin body
- [ ] `PUT /api/v3/payment-procedures/{id}/taxes/{taxId}` → 204
- [ ] `DELETE /api/v3/payment-procedures/{id}/taxes/{taxId}` → 204
- [ ] `{id}` no entero → 400 `Invalid ID format`; `{id}` ≤ 0 → 404 `Procedure not found`
- [ ] `{taxId}` no entero → 400 `Invalid tax ID format`; ≤ 0 → 404 `Tax is not assigned to this procedure`
- [ ] POST single: error de negocio del item → 404 / 409 / 422 según el catálogo
- [ ] POST bulk: error de negocio de cualquier item → 400 `Validation failed` + `errors["taxes.N.taxId"]`, reportando **todos** los items con error
- [ ] Sin rutas extra: `PATCH` o `GET .../taxes/{taxId}` → 405/404 del router
- [ ] Las 4 rutas tienen `->withMiddleware('auth')`: sin token → 401

### Service Layer
- [ ] `PaymentProcedureTaxService` recibe por constructor `PaymentProcedureTaxRepository`, `PaymentProcedureRepository` y `PaymentTaxRepository`
- [ ] Toda operación valida primero el procedimiento con `findByIdAndNotary($id, $notaryId)` → `ProcedureNotFound`
- [ ] `add` y `updateDefaultValue` rechazan un procedimiento con `statusId = 2` → `ProcedureInactive`; `getByProcedure` y `remove` **no**
- [ ] `add`: tax inexistente o de otra notaría → `TaxNotFound`; tax inactivo → `TaxInactive`; asignación activa → `AlreadyAssigned`
- [ ] `add`: asignación existente con status=2 → se rehabilita (status=1 + nuevo `defaultValue`), sin error
- [ ] `add`: con **cualquier** error de item, `upsertMany()` **no se llama** (todo o nada)
- [ ] `updateDefaultValue`: tax inexistente → `TaxNotFound`; tax inactivo → `TaxInactive`; sin asignación activa → `NotAssigned`
- [ ] `remove`: sin asignación activa (inexistente o ya con status=2) → `NotAssigned`. Un tax desactivado en el catálogo **sí** se puede remover
- [ ] El Service no importa nada de `App\Core\Http`
- [ ] Las excepciones extienden `\DomainException`, no `HttpException`

### Repository Layer
- [ ] `findActiveByProcedure`: JOIN con `payment_procedures` filtrando `pp.id_notary = :notaryId` y con `payment_taxes` filtrando `pt.id_notary = :notaryId`, más `ppt.id_status = 1` y `pt.id_status = 1`
- [ ] Orden alfabético natural por `tax_name` (`CONVERT ... COLLATE utf8mb4_unicode_ci`), con `id_tax` como desempate
- [ ] `findStatuses`: un solo query con `IN (...)` por placeholders → `array<taxId, status>`
- [ ] `upsertMany`: **una sola** sentencia `INSERT ... ON DUPLICATE KEY UPDATE default_value = VALUES(default_value), id_status = 1`
- [ ] `updateDefaultValue` y `deactivate`: UPDATE multi-tabla `JOIN payment_procedures pp` con `pp.id_notary = :notaryId`
- [ ] `PaymentTaxRepository::findByIdsAndNotary`: un solo query, resultado indexado por id; con `[]` no ejecuta SQL
- [ ] Nunca se hace `DELETE FROM payment_procedure_taxes`
- [ ] Todos los valores van por prepared statements (incluidos los `IN` dinámicos)

### Entity/DTO
- [ ] Entity `PaymentProcedureTax` final, readonly, con `fromRow()`; `defaultValue` es `float`
- [ ] `ProcedureTaxItem` es VO `final readonly`
- [ ] `AddProcedureTaxesRequest` expone `isBulk` y `items: ProcedureTaxItem[]` ya normalizados
- [ ] `defaultValue` ausente, `null`, negativo o `0` → se guarda `0.0` (POST); en PUT, negativo o `0` → `0.0`
- [ ] `defaultValue` string/bool/array → 400 `Default value must be a number`
- [ ] `taxId` string (`"12"`), float, bool, ≤ 0 → 400 `Tax ID must be a positive integer`
- [ ] Bulk: `taxes` vacío, no lista o > 50 items → 400; `taxId` duplicado → 400 en la 2ª ocurrencia
- [ ] `taxId` + `taxes` juntos, o ninguno → 400 `errors.body`
- [ ] `ProcedureTaxResponse` → exactamente `{ procedureId, taxId, taxName, defaultValue }`

## Tests Requeridos

### Unit
- [ ] `Entity/PaymentProcedureTaxTest`: `fromRow` con tipos string de PDO; `id_status` NULL → 1
- [ ] `DTO/DefaultValueRuleTest`: int, float, negativo, 0, -0.0, null, string, bool, número muy grande
- [ ] `DTO/AddProcedureTaxesRequestTest`: single válido, bulk válido, ambos, ninguno, `taxes` vacío, `taxes` objeto (no lista), 51 items, item no objeto, duplicados, tipos inválidos, claves de error `taxes.N.*`
- [ ] `DTO/UpdateProcedureTaxRequestTest`: requerido, null, string, negativo → 0
- [ ] `DTO/ProcedureTaxResponseTest`: forma exacta, sin `status`
- [ ] `Exception/ProcedureTaxErrorTest`: cada caso → mensaje y código HTTP esperados
- [ ] `Service/PaymentProcedureTaxServiceTest` (mocks de los 3 repos):
  - procedimiento ajeno → `ProcedureNotFound` en las 4 operaciones
  - procedimiento inactivo → `ProcedureInactive` en add/update; OK en get/remove
  - add: nuevo → `upsertMany` llamado con los items; rehabilitación (status=2) → `upsertMany` llamado
  - add bulk con 1 item inválido de 3 → excepción con el índice correcto y `upsertMany` **never()**
  - add con varios errores → todos los índices reportados
  - update/remove sin asignación activa → `NotAssigned`
  - remove con tax inactivo en el catálogo → OK

### Manual / Integración (QA)
- [ ] Cross-tenant: procedimiento de la notaría A con token de B → 404 en los 4 endpoints
- [ ] Cross-tenant: tax de la notaría B en procedimiento de A → 404 (single) / 400 (bulk)
- [ ] Rehabilitación: DELETE y luego POST del mismo tax → 201, la fila conserva `created_at` y el GET la muestra con el nuevo valor
- [ ] Bulk mixto (nuevo + rehabilitado) → ambas filas activas tras una sola llamada
- [ ] Bulk con un item inválido → `SELECT` confirma que no se insertó **ninguna** fila
- [ ] Doble POST simultáneo del mismo par → una sola fila en BD
- [ ] La tabla nunca tiene filas eliminadas físicamente (COUNT antes y después del DELETE)

## Criterios de Código
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] PSR-12; typed properties y return types
- [ ] Clases `final` excepto los Repositories (mockeables)
- [ ] Sin dependencias circulares: `PaymentProcedureTax` → `PaymentProcedure`/`PaymentTax` (repos y entities), nunca al revés
- [ ] No se modifican `PaymentProcedureController/Service` ni `PaymentTaxController/Service`
- [ ] Mensajes de error en inglés, idénticos al catálogo

## Performance
- [ ] Máximo 4 queries por request de POST (procedure, taxes, statuses, upsert), sin importar el tamaño del bulk
- [ ] Sin N+1: ni loops de `findByIdAndNotary` ni INSERT por item
- [ ] El GET usa el UNIQUE (prefijo `id_procedure`) y el PK de `payment_taxes`/`payment_procedures`

## Seguridad
- [ ] JWT requerido en las 4 rutas
- [ ] `notaryId` siempre del token, nunca del body/path
- [ ] Procedimiento y tax validados contra la notaría del token antes de escribir
- [ ] UPDATEs con JOIN a `payment_procedures` + `id_notary` (defensa en profundidad)
- [ ] Prepared statements en todos los queries, incluidos los `IN` y el multi-row INSERT
- [ ] Bulk limitado a 50 items
- [ ] Los 404 de procedimiento/tax ajeno son indistinguibles de un inexistente
