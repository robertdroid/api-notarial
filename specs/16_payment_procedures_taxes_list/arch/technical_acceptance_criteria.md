# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
`specs/16_payment_procedures_taxes_list/po/acceptance_criteria.md`, con los ajustes de `implementation_plan.md`:
- **ADR-001**: respuesta como **array plano** (sin `{ "data": ... }`); notaría sin procedimientos → `200 []`.
- **ADR-004**: `defaultValue` es número JSON sin formato forzado (`500`, `1200.5`, `0`).

## Criterios por Capa

### Routing
- [ ] `GET /api/v3/payment-procedures/taxes` registrada **antes** de `GET /api/v3/payment-procedures/{id}` en `src/Config/routes.php`
- [ ] La ruta tiene `->withMiddleware('auth')`: sin token o token inválido → 401
- [ ] `GET /api/v3/payment-procedures/5`, `GET /api/v3/payment-procedures/5/taxes` y `GET /api/v3/payment-procedures` siguen respondiendo igual que antes
- [ ] No se agregan otros métodos: `POST /api/v3/payment-procedures/taxes` → 405 (comportamiento actual del router)

### Controller Layer
- [ ] `PaymentProcedureTaxController::listAll(Request): Response`
- [ ] Usa `$request->getNotaryId()`; ignora query params y body
- [ ] 200 con `[{ procedureId, procedureName, taxes: [{ taxId, taxName, defaultValue }] }]`
- [ ] Notaría sin procedimientos activos → 200 `[]`
- [ ] No captura excepciones de dominio (no hay casos 404/422 en este endpoint)

### Service Layer
- [ ] `PaymentProcedureTaxService::getAllWithTaxes(int $notaryId): ProcedureWithTaxes[]` delega al repository
- [ ] El constructor del Service **no cambia** (sin registro nuevo en `ServiceLocator`)
- [ ] El Service no importa nada de `App\Core\Http`

### Repository Layer
- [ ] `PaymentProcedureTaxRepository::findActiveProceduresWithTaxes(int $notaryId): ProcedureWithTaxes[]`
- [ ] **Una sola** query, desde `payment_procedures` con `LEFT JOIN payment_procedure_taxes` y `LEFT JOIN payment_taxes`
- [ ] `WHERE pp.id_notary = :notaryId AND pp.id_status = 1`
- [ ] `ppt.id_status = 1` en el `ON` del primer LEFT JOIN (no en el `WHERE`)
- [ ] `pt.id_notary = :notaryId2 AND pt.id_status = 1` en el `ON` del segundo LEFT JOIN (no en el `WHERE`)
- [ ] Placeholders distintos (`:notaryId`, `:notaryId2`) por los prepares nativos
- [ ] `ORDER BY` procedimiento (collation `utf8mb4_unicode_ci`), `pp.id_procedure`, impuesto (collation `utf8mb4_unicode_ci`), `pt.id_tax`
- [ ] Agrupación en PHP por `id_procedure`, conservando el orden de la query
- [ ] Un tax solo se agrega si `pt.id_tax` no es NULL (relación activa con impuesto inactivo o de otra notaría → no aparece)
- [ ] No usa `view_payment_procedure_taxes`
- [ ] Sin SQL de escritura

### Entity/DTO
- [ ] `Entity/ProcedureWithTaxes`: `final`, propiedades `readonly` tipadas; `taxes` es `PaymentProcedureTax[]` en forma de lista
- [ ] `DTO/ProcedureWithTaxesResponse`: `fromEntity()` y `fromCollection()`
- [ ] Forma exacta por procedimiento: `procedureId` (int), `procedureName` (string), `taxes` (lista)
- [ ] Forma exacta por tax: `taxId` (int), `taxName` (string), `defaultValue` (float); **sin** `procedureId` ni `status`
- [ ] `taxes` vacío se serializa como `[]` (nunca `{}` ni `null`)
- [ ] `defaultValue = 0.0` aparece en la respuesta (no se omite)
- [ ] `ProcedureTaxResponse` y `PaymentProcedureTax` **no cambian**

## Tests Requeridos

### Unit
- [ ] `DTO/ProcedureWithTaxesResponseTest`:
  - procedimiento con 2 taxes → forma exacta, claves en orden, sin `procedureId` dentro de taxes
  - procedimiento sin taxes → `taxes === []` y `array_is_list`
  - `defaultValue` 0.0 presente y de tipo float
  - colección vacía → `[]`
- [ ] `Service/PaymentProcedureTaxServiceTest`: `getAllWithTaxes` llama `findActiveProceduresWithTaxes` con el `notaryId` recibido (`once()`) y devuelve su resultado sin tocar los otros repos (`never()`)

### Manual / Integración (QA)
- [ ] Escenario 1: procedimiento con ISR e ISAI activos → ambos con `taxId`, `taxName`, `defaultValue`
- [ ] Escenario 2: procedimiento activo sin filas en `payment_procedure_taxes` → `taxes: []`
- [ ] Escenario 3: relación con `id_status = 2` → el tax no aparece; el procedimiento sí
- [ ] Escenario 4: relación activa, `payment_taxes.id_status = 2` → el tax no aparece; el procedimiento sí
- [ ] Caso edge: único tax del procedimiento inactivo (por relación o catálogo) → procedimiento con `taxes: []`
- [ ] Escenario 5: procedimiento con `id_status = 2` → no aparece
- [ ] Escenario 6: con token de la notaría A no aparece ningún procedimiento ni tax de B
- [ ] Escenario 7: notaría sin procedimientos activos → 200 `[]`
- [ ] Escenario 8: sin token / token inválido → 401
- [ ] Orden: procedimientos e impuestos alfabéticos sin distinguir mayúsculas/acentos (p. ej. `adjudicación` entre `Acta` y `Compraventa`)
- [ ] `defaultValue` 0 → aparece como `0`
- [ ] Regresión: `GET /payment-procedures/{id}` con id válido, `abc` (400) y `0` (404) sin cambios
- [ ] Coherencia: para cada procedimiento, `taxes` coincide con `GET /payment-procedures/{id}/taxes` (mismos ids, mismo orden, mismos valores)

## Criterios de Código
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] PSR-12; typed properties y return types; PHPDoc `@return ProcedureWithTaxes[]`
- [ ] Clases `final` excepto el Repository
- [ ] Sin cambios en `PaymentProcedure*`, `PaymentTax*` ni `Core/`

## Performance
- [ ] Exactamente **1** query a BD por request (más la de autenticación existente)
- [ ] Sin N+1: nada de llamar `findActiveByProcedure` en un loop
- [ ] El JOIN a `payment_procedure_taxes` aprovecha el UNIQUE `(id_procedure, id_tax)`; el de `payment_taxes`, su PK

## Seguridad
- [ ] JWT requerido
- [ ] `notaryId` siempre del token
- [ ] Tenencia en ambos catálogos: `pp.id_notary` en el `WHERE` y `pt.id_notary` en el `ON`
- [ ] Prepared statements; ningún valor concatenado en el SQL
