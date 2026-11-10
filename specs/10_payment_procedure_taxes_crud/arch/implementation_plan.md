# Plan de Implementación: Impuestos por Procedimiento (Payment Procedure Taxes)

## Resumen

Nuevo módulo `PaymentProcedureTax` que expone 4 endpoints como sub-recurso de `/payment-procedures/{id}` sobre la tabla legacy `payment_procedure_taxes` (MyISAM, sin PK, sin `id_notary`). La tenencia se infiere del procedimiento: **toda operación arranca validando que el procedimiento pertenece a la notaría del token**.

Diferencias clave respecto a los catálogos anteriores (07/08/09):

1. **Sub-recurso con identidad compuesta** `(id_procedure, id_tax)`: no hay ID propio. Se agrega un índice `UNIQUE (id_procedure, id_tax)` (ADR-001).
2. **El Service orquesta 3 repositorios**: reutiliza `PaymentProcedureRepository` y `PaymentTaxRepository` para validar existencia, tenencia y status, sin modificar sus Services ni Controllers.
3. **Primeros endpoints con 409 y 422**: se agregan `Response::conflict()` y `Response::unprocessable()` (ADR-003).
4. **POST con dos formas de body** (individual y bulk) que se normalizan a una lista interna de items. Una sola escritura `INSERT ... ON DUPLICATE KEY UPDATE` cubre altas y rehabilitaciones (ADR-002).
5. **Errores de dominio por enum + excepción** en lugar del retorno `true|string|null`. Aquí hay 6 errores distintos con 3 códigos HTTP diferentes (ADR-004).

## Arquitectura Propuesta

### Diagrama de Componentes

```
HTTP Request  /api/v3/payment-procedures/{id}/taxes[/{taxId}]
    │
    ▼
Router ──► AuthMiddleware (JWT → notaryId)  ── 401
               │
               ▼
     PaymentProcedureTaxController
       │  parseId('id')     → 400 "Invalid ID format" | 404 "Procedure not found"
       │  parseId('taxId')  → 400 "Invalid tax ID format" | 404 "Tax is not assigned to this procedure"
       │  DTO::fromArray()  → 400 "Validation failed" + errors
       │  catch ProcedureTaxException        → match(error) → 404 | 409 | 422
       │  catch InvalidProcedureTaxItemsException
       │        single → código HTTP del error del item
       │        bulk   → 400 + errors["taxes.N.taxId"]
       ▼
     PaymentProcedureTaxService
       │  1. procedure = PaymentProcedureRepository::findByIdAndNotary()  → 404 / 422
       │  2. taxes     = PaymentTaxRepository::findByIdsAndNotary()       → 404 / 422 por item
       │  3. statuses  = PaymentProcedureTaxRepository::findStatuses()    → 409 por item
       │  4. todo válido → escribir (todo o nada)
       ▼
     PaymentProcedureTaxRepository ──► payment_procedure_taxes  (MyISAM, UNIQUE id_procedure+id_tax)
       │   SELECT con JOIN payment_procedures (id_notary) + payment_taxes (id_notary)
       │   UPDATE multi-tabla con JOIN payment_procedures (id_notary)
       ▼
     PaymentProcedureTax (read model) ──► ProcedureTaxResponse { procedureId, taxId, taxName, defaultValue }
```

### Módulos Afectados

- [ ] `src/Module/PaymentProcedureTax/` - **Nuevo** módulo (Entity, DTO×5, Exception×2 + enum, Repository, Service, Controller)
- [ ] `src/Module/PaymentTax/Repository/PaymentTaxRepository.php` - **Agregar** `findByIdsAndNotary()`. Solo se agrega el método; no se modifica nada existente
- [ ] `src/Core/Http/Response.php` - **Agregar** `conflict()` y `unprocessable()`
- [ ] `src/bootstrap.php` - Registrar Repository y Service nuevos
- [ ] `src/Config/routes.php` - 4 rutas protegidas
- [ ] `.db/pending/` - Migración con `UNIQUE (id_procedure, id_tax)`
- [ ] `docs/openapi.yaml` - Paths `/payment-procedures/{id}/taxes` y `/payment-procedures/{id}/taxes/{taxId}`
- [ ] `.claude/CLAUDE.md` - Agregar 409 y 422 a la lista de HTTP Status Codes
- [ ] `tests/Module/PaymentProcedureTax/` - Tests unitarios

**No se modifican:** `PaymentProcedureController/Service/Repository`, `PaymentTaxController/Service`.

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 20 min

#### 0.1 Migración de índice único
- **Archivo:** `.db/pending/20261110_170000_add_unique_procedure_tax_to_payment_procedure_taxes.sql`
- **Tipo:** Crear
- **Descripción:** `ALTER TABLE payment_procedure_taxes ADD UNIQUE INDEX uq_payment_procedure_taxes_procedure_tax (id_procedure, id_tax);`. Incluye en comentarios el **query de pre-verificación de duplicados**, que es obligatorio correr en producción antes de aplicar (ver `developer_notes.md`). Este índice reemplaza al índice no único que propone el PO: su prefijo `id_procedure` ya cubre el GET por procedimiento.
- **Dependencias:** Ninguna

### Fase 1: Core
Estimación: 10 min

#### 1.1 Helpers HTTP 409 y 422
- **Archivo:** `src/Core/Http/Response.php`
- **Tipo:** Modificar (agregar)
- **Descripción:** `conflict(string $message = 'Conflict'): self` → 409 y `unprocessable(string $message = 'Unprocessable entity'): self` → 422. Mismo formato `{ "error": "..." }` que `notFound()`.
- **Dependencias:** Ninguna

### Fase 2: Dominio (Entity, errores, DTOs)
Estimación: 1.5 h

#### 2.1 Entity `PaymentProcedureTax`
- **Archivo:** `src/Module/PaymentProcedureTax/Entity/PaymentProcedureTax.php`
- **Tipo:** Crear
- **Descripción:** `final class` readonly: `procedureId`, `taxId`, `taxName`, `defaultValue` (float), `statusId`. `fromRow()` mapea `id_procedure`, `id_tax`, `tax_name` (viene del JOIN), `default_value`, `id_status` (NULL → 1). Constantes `STATUS_ACTIVE = 1`, `STATUS_INACTIVE = 2`.
- **Dependencias:** Ninguna

#### 2.2 Enum `ProcedureTaxError` + excepciones
- **Archivos:**
  - `src/Module/PaymentProcedureTax/Exception/ProcedureTaxError.php` (enum backed por string)
  - `src/Module/PaymentProcedureTax/Exception/ProcedureTaxException.php`
  - `src/Module/PaymentProcedureTax/Exception/InvalidProcedureTaxItemsException.php`
- **Tipo:** Crear
- **Descripción:**
  - Casos de `ProcedureTaxError`: `ProcedureNotFound`, `ProcedureInactive`, `TaxNotFound`, `TaxInactive`, `AlreadyAssigned`, `NotAssigned`. Cada caso expone `message(): string` (texto en inglés del catálogo) y `httpStatus(): int` (404/422/404/422/409/404).
  - `ProcedureTaxException(ProcedureTaxError $error)`: error de una operación completa (procedimiento, o tax en PUT/DELETE).
  - `InvalidProcedureTaxItemsException(array<int, ProcedureTaxError> $itemErrors)`: errores por índice en el POST.
  - Ambas extienden `\DomainException`, **no** `HttpException`, para que el Service no conozca HTTP.
- **Dependencias:** Ninguna

#### 2.3 Regla `DefaultValueRule`
- **Archivo:** `src/Module/PaymentProcedureTax/DTO/DefaultValueRule.php`
- **Tipo:** Crear
- **Descripción:** `validate(mixed $value): ?string` → acepta `int|float`; rechaza string, bool y array con `"Default value must be a number"`. `normalize(int|float|null $value): float` → `max(0.0, (float) ($value ?? 0))`. Con eso, `-0.0`, los negativos y `null` quedan en `0.0`.
- **Dependencias:** Ninguna

#### 2.4 DTO `AddProcedureTaxesRequest` (single + bulk)
- **Archivo:** `src/Module/PaymentProcedureTax/DTO/AddProcedureTaxesRequest.php`
- **Tipo:** Crear
- **Descripción:** `fromArray()` detecta la forma del body:
  - `taxId` y `taxes` juntos → `errors.body = "Provide either taxId or taxes, not both"`.
  - Ninguno de los dos → `errors.body = "Either taxId or taxes is required"`.
  - Single: valida `taxId` (int > 0) y `defaultValue` (opcional, `null` = 0). Errores con claves `taxId` / `defaultValue`.
  - Bulk: `taxes` debe ser lista no vacía (`array_is_list`), con **máx. 50 items**. Cada item debe ser objeto. Errores con claves `taxes.N.taxId` / `taxes.N.defaultValue` / `taxes.N`. Un `taxId` duplicado marca la **segunda** ocurrencia: `taxes.N.taxId = "Duplicate tax ID in request"`.
  - Expone `bool $isBulk` y `ProcedureTaxItem[] $items` (VO `{int taxId, float defaultValue}` ya normalizado).
- **Dependencias:** 2.3, 2.5

#### 2.5 VO `ProcedureTaxItem`
- **Archivo:** `src/Module/PaymentProcedureTax/DTO/ProcedureTaxItem.php`
- **Tipo:** Crear
- **Descripción:** `final readonly class ProcedureTaxItem(int $taxId, float $defaultValue)`.
- **Dependencias:** Ninguna

#### 2.6 DTO `UpdateProcedureTaxRequest`
- **Archivo:** `src/Module/PaymentProcedureTax/DTO/UpdateProcedureTaxRequest.php`
- **Tipo:** Crear
- **Descripción:** `defaultValue` **requerido**: si falta → `"Default value is required"`; si viene `null` o no es número → `"Default value must be a number"`. Se guarda normalizado con `DefaultValueRule::normalize()`.
- **Dependencias:** 2.3

#### 2.7 DTO `ProcedureTaxResponse`
- **Archivo:** `src/Module/PaymentProcedureTax/DTO/ProcedureTaxResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` → `{ procedureId, taxId, taxName, defaultValue }` y `fromCollection()`. No expone `status`, porque el listado solo trae activos, ni fechas.
- **Dependencias:** 2.1

### Fase 3: Repositorios
Estimación: 1 h

#### 3.1 `PaymentTaxRepository::findByIdsAndNotary()`
- **Archivo:** `src/Module/PaymentTax/Repository/PaymentTaxRepository.php`
- **Tipo:** Modificar (agregar método)
- **Descripción:** `findByIdsAndNotary(int[] $ids, int $notaryId): array<int, PaymentTax>` indexado por `id_tax`. Usa `IN (:id0, :id1, ...)` con placeholders generados, nunca concatenando valores. Lista vacía → `[]` sin ir a la BD. Evita N+1 en el bulk.
- **Dependencias:** Ninguna

#### 3.2 `PaymentProcedureTaxRepository`
- **Archivo:** `src/Module/PaymentProcedureTax/Repository/PaymentProcedureTaxRepository.php`
- **Tipo:** Crear (clase no `final`, para poder mockearla, igual que los otros repos)
- **Métodos:**
  - `findActiveByProcedure(int $procedureId, int $notaryId): PaymentProcedureTax[]` → JOIN con `payment_procedures` (`pp.id_notary`) y `payment_taxes` (`pt.id_notary`, `pt.id_status = 1`), filtrando `ppt.id_status = 1`. Orden por `CONVERT(pt.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci`, `ppt.id_tax`.
  - `findStatuses(int $procedureId, int[] $taxIds): array<int, int>` → `taxId => id_status` de las filas existentes, en cualquier status.
  - `upsertMany(int $procedureId, ProcedureTaxItem[] $items): void` → **un solo** `INSERT ... VALUES (...),(...) ON DUPLICATE KEY UPDATE default_value = VALUES(default_value), id_status = 1`.
  - `updateDefaultValue(int $procedureId, int $taxId, float $value, int $notaryId): void` → UPDATE multi-tabla con JOIN a `payment_procedures` filtrando `pp.id_notary` y `ppt.id_status = 1`.
  - `deactivate(int $procedureId, int $taxId, int $notaryId): void` → mismo patrón, `SET ppt.id_status = 2`.
- **Dependencias:** 2.1, 2.5, 0.1 (el upsert necesita el UNIQUE)

### Fase 4: Service
Estimación: 1 h

#### 4.1 `PaymentProcedureTaxService`
- **Archivo:** `src/Module/PaymentProcedureTax/Service/PaymentProcedureTaxService.php`
- **Tipo:** Crear
- **Constructor:** `PaymentProcedureTaxRepository`, `PaymentProcedureRepository`, `PaymentTaxRepository`.
- **Métodos:**
  - `getByProcedure(int $procedureId, int $notaryId): PaymentProcedureTax[]` → procedimiento inexistente → `ProcedureNotFound`. Un procedimiento inactivo **sí** se puede leer.
  - `add(int $procedureId, ProcedureTaxItem[] $items, int $notaryId): void` → procedimiento (404/422) → taxes por item (`TaxNotFound`, `TaxInactive`) → assignment activo (`AlreadyAssigned`). Si hay **cualquier** error de item → `InvalidProcedureTaxItemsException` **sin escribir nada**. Si todo es válido → `upsertMany()`.
  - `updateDefaultValue(int $procedureId, int $taxId, float $value, int $notaryId): void` → procedimiento (404/422) → tax en catálogo (404/422) → assignment activo (`NotAssigned`) → update.
  - `remove(int $procedureId, int $taxId, int $notaryId): void` → procedimiento (404, **sin** chequear status) → assignment activo (`NotAssigned`) → deactivate. No se valida el status del tax, para poder limpiar impuestos ya desactivados en el catálogo.
- **Dependencias:** 2.x, 3.x

### Fase 5: Controller, rutas y wiring
Estimación: 45 min

#### 5.1 `PaymentProcedureTaxController`
- **Archivo:** `src/Module/PaymentProcedureTax/Controller/PaymentProcedureTaxController.php`
- **Tipo:** Crear
- **Descripción:** `index`, `store`, `update` y `destroy`. Un `try/catch` por acción con un mapper privado `errorResponse(ProcedureTaxError $e): Response` que hace `match` → `notFound` / `unprocessable` / `conflict`. Para `InvalidProcedureTaxItemsException`: si `!$dto->isBulk` → `errorResponse($itemErrors[0])`; si es bulk → `badRequest('Validation failed', ['taxes.N.taxId' => msg, ...])`. Respuestas exitosas: `index` → `Response::json(array)`, `store` → `Response::created()`, `update`/`destroy` → `Response::noContent()`.
- **Dependencias:** 4.1, 1.1

#### 5.2 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `PaymentProcedureTaxRepository` y `PaymentProcedureTaxService` (este último con los 3 repositorios del `ServiceLocator`) **después** de PaymentTax y PaymentProcedure.
- **Dependencias:** 3.2, 4.1

#### 5.3 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:**
  ```php
  $router->get('/api/v3/payment-procedures/{id}/taxes', [PaymentProcedureTaxController::class, 'index'])->withMiddleware('auth');
  $router->post('/api/v3/payment-procedures/{id}/taxes', [PaymentProcedureTaxController::class, 'store'])->withMiddleware('auth');
  $router->put('/api/v3/payment-procedures/{id}/taxes/{taxId}', [PaymentProcedureTaxController::class, 'update'])->withMiddleware('auth');
  $router->delete('/api/v3/payment-procedures/{id}/taxes/{taxId}', [PaymentProcedureTaxController::class, 'destroy'])->withMiddleware('auth');
  ```
  El regex del Router está anclado (`^...$`) y `{id}` captura `[^/]+`, así que no choca con `/payment-procedures/{id}`.
- **Dependencias:** 5.1

### Fase 6: Tests y documentación
Estimación: 2 h

#### 6.1 Tests unitarios
- **Archivos:** `tests/Module/PaymentProcedureTax/{Entity,DTO,Service}/...Test.php`
- **Tipo:** Crear
- **Descripción:** Casos mínimos en `developer_notes.md`. El Service se prueba con mocks de los 3 repositorios y verifica explícitamente que `upsertMany()` **nunca** se llame cuando hay un error.
- **Dependencias:** Fases 2–4

#### 6.2 OpenAPI + CLAUDE.md
- **Archivos:** `docs/openapi.yaml`, `.claude/CLAUDE.md`
- **Tipo:** Modificar
- **Descripción:** Tag `PaymentProcedureTaxes`, 2 paths, schemas `ProcedureTax`, `AddProcedureTaxRequest` (`oneOf` single/bulk) y `UpdateProcedureTaxRequest`, y respuestas 200/201/204/400/401/404/409/422. En CLAUDE.md, agregar `409 - Conflict` y `422 - Unprocessable Entity` a "HTTP Status Codes".
- **Dependencias:** 5.3

## Orden de Implementación

1. **Migración (0.1)**: primero corre el pre-check de duplicados y después aplica el índice. El `upsertMany` depende de él.
2. **Response helpers (1.1)**: cambio trivial y aislado.
3. **Entity, enum de errores y VO (2.1, 2.2, 2.5)**: base de todo lo demás.
4. **DefaultValueRule + DTOs (2.3, 2.4, 2.6, 2.7)**: el DTO de POST es la pieza con más casos; conviene testearlo a fondo antes de seguir.
5. **Repositorios (3.1, 3.2)**: probar el upsert y el UPDATE multi-tabla contra la BD local.
6. **Service (4.1)** + tests con mocks.
7. **bootstrap (5.2) → Controller (5.1) → rutas (5.3)**: probar con curl/Postman.
8. **OpenAPI y CLAUDE.md (6.2)**.

## Catálogo de Errores (para frontend)

| HTTP | Campo en body             | Mensaje                                               | Causa |
|------|---------------------------|-------------------------------------------------------|-------|
| 400  | `error`                   | `"Invalid ID format"`                                 | `{id}` no es entero |
| 400  | `error`                   | `"Invalid tax ID format"`                             | `{taxId}` no es entero |
| 400  | `error`                   | `"Validation failed"`                                 | Body inválido o errores del bulk; ver `errors` |
| 400  | `errors.body`             | `"Either taxId or taxes is required"`                 | POST sin ninguna de las dos claves |
| 400  | `errors.body`             | `"Provide either taxId or taxes, not both"`           | POST con ambas |
| 400  | `errors.taxId`            | `"Tax ID must be a positive integer"`                 | Tipo incorrecto o ≤ 0 |
| 400  | `errors.defaultValue`     | `"Default value is required"`                         | PUT sin `defaultValue` |
| 400  | `errors.defaultValue`     | `"Default value must be a number"`                    | String, bool, array (o `null` en PUT) |
| 400  | `errors.taxes`            | `"Taxes must be a non-empty array"`                   | `taxes` vacío o no es lista |
| 400  | `errors.taxes`            | `"Taxes must not exceed 50 items"`                    | Más de 50 items |
| 400  | `errors.taxes.N`          | `"Each tax must be an object"`                        | Item no es objeto |
| 400  | `errors.taxes.N.taxId`    | `"Tax ID must be a positive integer"`                 | Igual que single |
| 400  | `errors.taxes.N.taxId`    | `"Duplicate tax ID in request"`                       | Mismo `taxId` repetido en el array |
| 400  | `errors.taxes.N.taxId`    | `"Tax not found"` / `"Tax is inactive"` / `"Tax is already assigned to this procedure"` | Errores de negocio por item (**solo bulk**) |
| 401  | `error`                   | (mensaje del AuthMiddleware)                          | Token ausente, inválido o expirado |
| 404  | `error`                   | `"Procedure not found"`                               | No existe o es de otra notaría (todas las operaciones) |
| 404  | `error`                   | `"Tax not found"`                                     | POST single o PUT: tax no existe o es de otra notaría |
| 404  | `error`                   | `"Tax is not assigned to this procedure"`             | PUT/DELETE: sin asignación activa |
| 409  | `error`                   | `"Tax is already assigned to this procedure"`         | POST single: asignación ya activa |
| 422  | `error`                   | `"Procedure is inactive"`                             | POST/PUT sobre procedimiento con status=2 |
| 422  | `error`                   | `"Tax is inactive"`                                   | POST single/PUT: tax con status=2 en el catálogo |

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Producción ya tiene pares duplicados → el `ADD UNIQUE` falla | Baja (local: 0 de 11) | Alto | Correr el pre-check de la migración en prod; si hay duplicados, consolidarlos antes (ver `developer_notes.md`) |
| La API v1 inserta un par repetido después del UNIQUE → error en v1 | Baja (confirmado: v1 no inserta duplicados) | Medio | Validar el flujo de v1 en QA después de aplicar la migración |
| MyISAM sin transacciones: un fallo a mitad del bulk deja inserciones parciales | Baja | Medio | Validación completa antes de escribir, y una **sola sentencia** multi-row (MyISAM bloquea la tabla por sentencia) |
| Carrera entre dos POST del mismo par (doble clic) | Media | Bajo | El UNIQUE + `ON DUPLICATE KEY UPDATE` evita duplicados; en el peor caso, el segundo request responde 201 en vez de 409 |
| Tipos JSON inesperados (`"taxId": "12"`, `"defaultValue": "500"`) → `TypeError` con `strict_types` → 500 | Media | Medio | El DTO verifica `is_int`/`is_int\|is_float` antes de asignar → 400 |
| Bulk enorme (miles de items) | Baja | Medio | Límite de 50 items en el DTO |
| `defaultValue` negativo convertido en 0 sin avisar al usuario | Media | Bajo | Requerimiento explícito del PO; documentado en OpenAPI |
| Un tax desactivado en el catálogo desaparece del GET pero sigue asignado | Media | Bajo | ADR-006; si se reactiva en el catálogo, reaparece con su `defaultValue` previo. Se puede quitar con DELETE |
| Precisión de `double` en montos (ej. 0.1 + 0.2) | Baja | Bajo | Aquí solo se almacena un valor, no se hacen cálculos. Revisar `DECIMAL` al diseñar Transactions |

## Decisiones de Arquitectura

### ADR-001: Índice `UNIQUE (id_procedure, id_tax)` en lugar del índice compuesto del PO
- **Contexto:** La tabla no tiene ningún índice ni PK. El PO propone `INDEX (id_procedure, id_tax, id_status)`. La unicidad del par es la identidad natural del registro, y sin restricción en BD un doble clic o dos pestañas pueden crear duplicados que rompan el GET y los UPDATE.
- **Decisión (confirmada con el equipo):** `UNIQUE INDEX uq_payment_procedure_taxes_procedure_tax (id_procedure, id_tax)`. La v1 no inserta duplicados. El prefijo `id_procedure` cubre el GET; `id_status` no aporta selectividad (máx. ~5 filas por procedimiento).
- **Consecuencias:** Habilita `ON DUPLICATE KEY UPDATE` (ADR-002). Obliga a verificar duplicados en producción antes de migrar.

### ADR-002: Altas y rehabilitaciones con un solo `INSERT ... ON DUPLICATE KEY UPDATE`
- **Contexto:** Un POST puede mezclar taxes nuevos e inactivos (status=2) que hay que rehabilitar. MyISAM no tiene transacciones, así que "INSERT de unos + UPDATE de otros" deja una ventana de escritura parcial.
- **Decisión:** Validar todo primero (incluido que ningún item esté ya activo → 409/400) y después ejecutar **una** sentencia multi-row con `ON DUPLICATE KEY UPDATE default_value = VALUES(default_value), id_status = 1`.
- **Consecuencias:** El "todo o nada" queda garantizado a nivel sentencia. `created_at` conserva la fecha original al rehabilitar y `modified_at` se actualiza solo. `VALUES()` sigue soportado en MariaDB 11.4.

### ADR-003: Adoptar 409 Conflict y 422 Unprocessable Entity
- **Contexto:** Hasta ahora el proyecto responde 400 a todos los errores de negocio. El PO distingue "ya asignado" (409) de "recurso inactivo" (422), y el frontend necesita diferenciar esos casos para mostrar mensajes útiles.
- **Decisión (confirmada con el equipo):** Agregar `Response::conflict()` y `Response::unprocessable()`, y documentar ambos códigos en CLAUDE.md. Los catálogos 07/08/09 **no** se migran (por ejemplo, el nombre duplicado sigue respondiendo 400).
- **Consecuencias:** Inconsistencia histórica conocida entre módulos viejos y nuevos. Los features nuevos deben preferir 409/422 cuando aplique.

### ADR-004: Errores de dominio vía enum + excepciones de módulo
- **Contexto:** Los Services anteriores devuelven `true|string|null`. Este módulo tiene 6 errores con 3 códigos HTTP distintos, más errores por item en el bulk; un retorno union sería ilegible.
- **Decisión:** Enum `ProcedureTaxError`, que incluye `message()` y `httpStatus()`, más dos excepciones (`ProcedureTaxException` y `InvalidProcedureTaxItemsException`) que extienden `\DomainException`. El Controller las traduce a `Response`; el Service no conoce HTTP.
- **Consecuencias:** Tests del Service con `expectException` y aserciones sobre el enum. Es un patrón reutilizable para Transactions. `httpStatus()` en el enum es una concesión pragmática (el mapeo vive junto al error); si molesta, puede moverse a un `match` en el Controller sin afectar al Service.

### ADR-005: POST 201 y PUT/DELETE 204 sin body; GET devuelve array plano
- **Contexto:** El PO pide 201 y 200 con el registro, y `{ "data": [...] }` en el GET. Los features 08/09 (ADR-005/ADR-004) usan 201 vacío, 204 y array plano.
- **Decisión (confirmada con el equipo):** Mantener el patrón del proyecto. A diferencia de Clients, aquí el frontend ya conoce `procedureId + taxId`, así que no hay pérdida funcional; tras escribir, vuelve a pedir el GET. El GET devuelve `[ {...}, ... ]` sin envoltorio `data`, igual que `/payment-procedures`.
- **Consecuencias:** Se ajustan los ejemplos y los escenarios 2, 3, 4 y 5 del PO. El Service no necesita releer con JOIN después de escribir.

### ADR-006: El GET excluye taxes desactivados en el catálogo
- **Contexto:** Una asignación puede estar activa (`ppt.id_status = 1`) mientras el impuesto está desactivado en `payment_taxes`. Esta configuración es la plantilla para crear transacciones, que no deberían ofrecer impuestos inactivos. Además, el criterio de rechazo del PO "se muestran impuestos con status=2" es ambiguo sobre a qué status se refiere.
- **Decisión:** El GET filtra `ppt.id_status = 1 AND pt.id_status = 1`. PUT sobre esa asignación → 422 `Tax is inactive`. DELETE **sí** se permite (limpieza).
- **Consecuencias:** Si el tax se reactiva en el catálogo, la asignación reaparece con su valor previo. Hoy no hay casos en la BD local (0 asignaciones activas con tax inactivo).

### ADR-007: Bulk permitido siempre; errores por índice → 400
- **Contexto:** El PO describe el bulk como "creación inicial", pero también exige todo o nada y 400 si algún item es inválido.
- **Decisión (confirmada con el equipo):** El bulk funciona aunque el procedimiento ya tenga impuestos. Los errores de procedimiento siguen siendo 404/422. Los errores de items (formato, duplicados, not found, inactivo, ya asignado) responden **400** con `errors["taxes.N.taxId"]`, para reportarlos todos juntos. El single conserva 404/409/422.
- **Consecuencias:** El mismo error de negocio tiene distinto código según la forma del body (por ejemplo, "Tax not found" es 404 en single y 400 en bulk). Se documenta explícitamente en OpenAPI.

### ADR-008: Precedencia de validaciones
- **Contexto:** Un request puede fallar por varias razones a la vez.
- **Decisión:** Orden: 401 (middleware) → 400 de formato de path → 400 de body (DTO) → 404/422 del procedimiento → errores del tax/asignación. Es el mismo orden que `PaymentProcedureController::update` (path → DTO → service).
- **Consecuencias:** Un body inválido sobre un procedimiento ajeno responde 400, no 404. No filtra información entre notarías: el 400 no revela si el procedimiento existe.
