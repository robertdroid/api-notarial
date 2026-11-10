# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
Referencia: `specs/08_payment_procedures_crud/po/acceptance_criteria.md`

## Criterios por Capa

### Controller Layer

- [ ] Endpoint `GET /api/v3/payment-procedures` implementado con middleware `auth`
- [ ] Endpoint `GET /api/v3/payment-procedures/{id}` implementado con middleware `auth`
- [ ] Endpoint `POST /api/v3/payment-procedures` implementado con middleware `auth`
- [ ] Endpoint `PUT /api/v3/payment-procedures/{id}` implementado con middleware `auth`
- [ ] Query param `status` inválido (no `1` ni `2`) → 400 `"Invalid status value"`
- [ ] Query param `name` vacío se ignora (devuelve todos)
- [ ] ID con formato inválido (no entero) → 400 `"Invalid ID format"`
- [ ] ID ≤ 0 → 404 `"Procedure not found"`
- [ ] DELETE → 405 (por el router, no necesita código)

### Service Layer

- [ ] `getAll()` delega a Repository con filtros opcionales
- [ ] `getById()` devuelve `null` si no existe o pertenece a otra notaría
- [ ] `create()` valida unicidad y devuelve `true` o mensaje de error
- [ ] `update()` devuelve `null` si no existe, `true` si éxito, o mensaje de error
- [ ] **Unicidad condicional en UPDATE**: solo valida si el nombre cambia (case-insensitive)
- [ ] Service NO tiene dependencia directa de PDO
- [ ] Mensaje de duplicado: `"Procedure name already exists"`

### Repository Layer

- [ ] Todas las queries incluyen `WHERE id_notary = :notaryId`
- [ ] `findAllByNotary()` ordena por `procedure_name ASC`
- [ ] `findAllByNotary()` escapa comodines `%`, `_`, `!` con `ESCAPE '!'`
- [ ] Búsqueda por nombre usa `CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci`
- [ ] `existsByNameAndNotary()` usa la misma expresión para comparación exacta
- [ ] `existsByNameAndNotary()` acepta `?int $excludeId` para excluir el propio registro en UPDATE
- [ ] `create()` devuelve el `lastInsertId`
- [ ] `update()` actualiza solo `procedure_name` y `id_status`; `modified_at` lo maneja la BD
- [ ] Prepared statements en todas las queries (sin concatenación de valores)

### Entity

- [ ] `PaymentProcedure` es `final class` con propiedades `readonly`
- [ ] Propiedades: `int $id`, `int $notaryId`, `string $name`, `int $statusId`
- [ ] `fromRow()` mapea: `id_procedure` → `id`, `id_notary` → `notaryId`, `procedure_name` → `name`, `id_status` → `statusId`
- [ ] `id_status` NULL → default `1`
- [ ] Constante `STATUS_ACTIVE = 1`

### DTOs

#### CreatePaymentProcedureRequest
- [ ] `procedureName` requerido
- [ ] `procedureName` debe ser string
- [ ] `procedureName` se aplica `trim()` antes de validar
- [ ] `procedureName` vacío tras trim → error
- [ ] `procedureName` > 500 caracteres → error
- [ ] `procedureName` con caracteres 4-byte (emoji) → error `"unsupported characters"`
- [ ] `status` opcional, default `1`
- [ ] `status` debe ser int y valor 1 o 2
- [ ] `isValid()` y `getErrors()` implementados

#### UpdatePaymentProcedureRequest
- [ ] Ambos campos opcionales (`?string`, `?int`)
- [ ] Si ningún campo presente → error `"At least one field (procedureName, status) is required"`
- [ ] Mismas validaciones de `procedureName` cuando está presente
- [ ] Mismas validaciones de `status` cuando está presente
- [ ] Usa `array_key_exists()` para distinguir ausente de presente-inválido

#### PaymentProcedureResponse
- [ ] `fromEntity()` devuelve `{ procedureId, procedureName, status }`
- [ ] `fromCollection()` mapea array de entidades
- [ ] **NO** incluye `notaryId`
- [ ] **NO** incluye `createdAt` ni `modifiedAt`

### ProcedureNameRule
- [ ] Método estático `validate(mixed $value): ?string`
- [ ] Devuelve mensaje de error o `null`
- [ ] Constante `MAX_LENGTH = 500`
- [ ] Detecta caracteres de 4 bytes con regex `[\x{10000}-\x{10FFFF}]`

## Tests Requeridos

### Unit Tests

- [ ] `PaymentProcedureTest` - Entity `fromRow()` con casos normales y defaults
- [ ] `CreatePaymentProcedureRequestTest` - Casos válidos e inválidos (nombre vacío, largo, emoji, status inválido)
- [ ] `UpdatePaymentProcedureRequestTest` - Casos válidos, sin campos, campos inválidos
- [ ] `PaymentProcedureResponseTest` - `fromEntity()` y `fromCollection()`
- [ ] `PaymentProcedureServiceTest` - Lógica de negocio con mocks del repository

### Casos de Test del Service (con mock)

- [ ] `getAll()` delega correctamente los filtros
- [ ] `getById()` devuelve entidad o null
- [ ] `create()` con nombre nuevo → `true`
- [ ] `create()` con nombre duplicado → `"Procedure name already exists"`
- [ ] `update()` con ID inexistente → `null`
- [ ] `update()` con nombre nuevo válido → `true`
- [ ] `update()` con nombre duplicado de otro → error
- [ ] `update()` con mismo nombre (sin cambiar) → `true` (no valida unicidad)
- [ ] `update()` solo status → `true`

## Criterios de Código

- [ ] PSR-12 coding style
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] Typed properties y return types en todos los métodos
- [ ] Sin dependencias circulares entre módulos
- [ ] Principios SOLID aplicados
- [ ] Clases `final` donde corresponde (Entity, DTOs, Service, Controller)
- [ ] Sin `var_dump`, `print_r`, `echo`, `die` en código final
- [ ] Sin dependencias a otros módulos de dominio (solo Core y Shared)

## Performance

- [ ] Índice `idx_payment_procedures_notary_status (id_notary, id_status)` creado y aplicado
- [ ] `EXPLAIN` de `findAllByNotary` usa el índice
- [ ] Sin N+1 queries (una sola query para listar)
- [ ] Sin paginación en este scope (lista completa, ~20 registros por notaría)

## Seguridad

- [ ] Autenticación JWT requerida en los 4 endpoints
- [ ] Filtro por `id_notary` del token en TODAS las queries (multi-tenant)
- [ ] GET/PUT de ID de otra notaría → 404 (no 403, para no revelar existencia)
- [ ] Input sanitizado con prepared statements
- [ ] `notaryId` en body de POST/PUT se ignora (se usa el del token)

## Multi-tenancy

| Escenario | Comportamiento Esperado |
|-----------|------------------------|
| GET lista | Solo procedimientos de la notaría del token |
| GET por ID de otra notaría | 404 idéntico a "no existe" |
| POST | Se asocia a la notaría del token |
| PUT de ID de otra notaría | 404 idéntico a "no existe" |
| Mismo nombre en diferentes notarías | Permitido (unicidad por notaría) |

## Documentación

- [ ] OpenAPI actualizado con tag `PaymentProcedures`
- [ ] Paths `/payment-procedures` y `/payment-procedures/{id}`
- [ ] Schemas `PaymentProcedure`, `CreatePaymentProcedureRequest`, `UpdatePaymentProcedureRequest`
- [ ] Respuestas 200, 201, 204, 400, 401, 404, 405 documentadas
- [ ] Parámetros de query `status` y `name` documentados

## Migración

- [ ] Archivo `.db/pending/YYYYMMDD_HHMMSS_add_notary_status_index_to_payment_procedures.sql`
- [ ] Contiene sección `-- UP` y `-- DOWN`
- [ ] Aplicado en local y movido a `.db/applied/`

## Validación Final (Checklist QA)

### Escenarios del PO a Verificar

| # | Escenario | Endpoint | Código |
|---|-----------|----------|--------|
| 1 | Listar procedimientos | GET /payment-procedures | 200 |
| 2 | Filtro status=1 | GET /payment-procedures?status=1 | 200 |
| 3 | Filtro nombre parcial | GET /payment-procedures?name=escritura | 200 |
| 4 | Obtener por ID | GET /payment-procedures/{id} | 200 |
| 5 | ID de otra notaría | GET /payment-procedures/{id} | 404 |
| 6 | Crear | POST /payment-procedures | 201 |
| 7 | Crear duplicado | POST /payment-procedures | 400 |
| 8 | Crear sin nombre | POST /payment-procedures | 400 |
| 9 | Actualizar | PUT /payment-procedures/{id} | 204 |
| 10 | Desactivar (status=2) | PUT /payment-procedures/{id} | 204 |
| 11 | PUT otra notaría | PUT /payment-procedures/{id} | 404 |
| 12 | Sin token | cualquiera | 401 |
| 13 | PUT nombre duplicado | PUT /payment-procedures/{id} | 400 |

### Edge Cases a Verificar

- [ ] Nombre con solo espacios → 400
- [ ] Nombre con 501 caracteres → 400
- [ ] Nombre con 500 caracteres → 201
- [ ] ID no numérico (`abc`, `1.5`, `1e3`) → 400
- [ ] ID negativo o cero → 404
- [ ] Filtro status=3 → 400
- [ ] Emoji en nombre → 400
- [ ] `procedureName` con trim conserva espacios internos
- [ ] DELETE → 405
- [ ] PUT mismo nombre (sin cambio) → 204 (no error de duplicado)
