# Criterios de Aceptación Técnicos: Payment Taxes CRUD

## Basado en: PO Acceptance Criteria
Referencia: `specs/07_payment_taxes_crud/po/acceptance_criteria.md` (Escenarios 1–23, Validaciones, Casos Edge, Criterios de Rechazo)

## Criterios por Capa

### Controller Layer (`PaymentTaxController`)

- [ ] `GET /api/v3/payment-taxes` → 200 con array (vacío si no hay registros). Esc. 1–4
- [ ] `GET /api/v3/payment-taxes?status=X`: con X ∉ {`1`,`2`} → 400 `Invalid status value`
- [ ] `GET /api/v3/payment-taxes?name=` (vacío) se ignora como filtro
- [ ] `GET /api/v3/payment-taxes/{id}` → 200 con objeto. Esc. 5
- [ ] `{id}` no entero (`abc`, `1.5`, `1e3`) → 400 `Invalid ID format`. Usar `filter_var(FILTER_VALIDATE_INT)`, **no** `is_numeric`
- [ ] `{id}` ≤ 0 → 404 `Tax not found` (Caso Edge)
- [ ] `{id}` inexistente o de otra notaría → 404 `Tax not found`, con el mismo mensaje en ambos casos. Esc. 6, 7, 18
- [ ] `POST /api/v3/payment-taxes` válido → **201 sin body**. Esc. 8, 9
- [ ] `PUT /api/v3/payment-taxes/{id}` válido → **204 sin body**. Esc. 15–17, 20
- [ ] Validación del DTO fallida → 400 `{ "error": "Validation failed", "errors": { campo: mensaje } }` (formato de `Response::badRequest`)
- [ ] Duplicado → 400 `Tax name already exists`. Esc. 10, 19
- [ ] **No** existe ruta DELETE (`DELETE /api/v3/payment-taxes/{id}` → 405 del router)
- [ ] Las 4 rutas registradas con `->withMiddleware('auth')`
- [ ] `notaryId` siempre sale de `$request->getNotaryId()`; nunca del body ni del query

### Service Layer (`PaymentTaxService`)

- [ ] `create()`: aplica `status` default 1 (vía DTO), verifica unicidad y persiste. Devuelve `true` o `'Tax name already exists'`
- [ ] `update()`: devuelve `null` si `findByIdAndNotary` no encuentra el registro
- [ ] `update()`: merge parcial (`taxName ?? existing->name`, `status ?? existing->statusId`)
- [ ] `update()`: valida unicidad **solo** si viene `taxName`, excluyendo el propio `id`
- [ ] `update()` con el mismo nombre (aunque cambie mayúsculas o acentos, p. ej. "ISR" → "isr") → éxito. Esc. 20
- [ ] Desactivar un impuesto en uso por un procedimiento está permitido (sin validación de `payment_procedure_taxes`)
- [ ] Sin acceso directo a PDO ni SQL en el Service

### Repository Layer (`PaymentTaxRepository`)

- [ ] **Todas** las queries (SELECT, UPDATE) incluyen `id_notary = :notaryId`
- [ ] Búsqueda: `CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :name ESCAPE '!'`, con `!`, `%` y `_` escapados con `!` en el término
- [ ] Unicidad: `CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci = :name`, con `AND id_tax <> :excludeId` opcional
- [ ] `?name=subdivision` devuelve "Pago de Subdivisión" y "Derechos Subdivisión" (datos semilla de notaría 1)
- [ ] Lista ordenada por `tax_name ASC`
- [ ] `create()` inserta solo `id_notary`, `tax_name` e `id_status` (`created_at` y `modified_at` los pone la BD)
- [ ] `update()` actualiza solo `tax_name` e `id_status` con `WHERE id_tax = :id AND id_notary = :notaryId`
- [ ] Prepared statements con parámetros nombrados; nada de concatenar input en SQL

### Entity / DTO

- [ ] `Entity/PaymentTax`: `final`, propiedades `readonly` tipadas (`int $id`, `int $notaryId`, `string $name`, `int $statusId`), `fromRow()` tolera `tax_name`/`id_status` NULL
- [ ] `TaxNameRule::validate(mixed)`. Reglas en orden:
  1. no es string → `Tax name must be a string`
  2. vacío tras `trim` → `Tax name is required`
  3. `mb_strlen(trim) > 500` → `Tax name must not exceed 500 characters`
  4. UTF-8 inválido o carácter fuera del BMP (`/[\x{10000}-\x{10FFFF}]/u`) → `Tax name contains unsupported characters`
- [ ] `CreatePaymentTaxRequest`: `taxName` ausente → `Tax name is required`. `status` ausente → 1. `status` presente y ≠ `int` 1|2 → `Status must be 1 or 2`. Esc. 11–14
- [ ] `UpdatePaymentTaxRequest`: `taxName`/`status` nullables. Presente + inválido → error (incluye `"taxName": null`). Ninguno presente → error `body`
- [ ] Ambos DTOs guardan `taxName` ya recortado (trim)
- [ ] `PaymentTaxResponse::fromEntity()` devuelve **exactamente** `taxId`, `taxName` y `status` (sin `notaryId`, `createdAt` ni `modifiedAt`)

### Base de Datos

- [ ] Migración `.db/applied/YYYYMMDD_HHMMSS_add_notary_status_index_to_payment_taxes.sql` con UP y DOWN comentado, según `.db/README.md`
- [ ] `SHOW INDEX FROM payment_taxes` muestra `idx_payment_taxes_notary_status (id_notary, id_status)`
- [ ] `EXPLAIN` del listado usa ese índice (`key = idx_payment_taxes_notary_status`)

## Tests Requeridos

### Unit tests (PHPUnit, `tests/Module/PaymentTax/`)
- [ ] `Entity/PaymentTaxTest`: `fromRow()` normal, con `tax_name` NULL y con `id_status` NULL
- [ ] `DTO/CreatePaymentTaxRequestTest`: válido, default status, ausente, vacío, solo espacios, 500 chars OK, 501 chars error, multibyte (`"Subdivisión"` × n) contado con `mb_strlen`, emoji, `taxName` numérico, `status` 3, `status` `"1"` (string), trim aplicado
- [ ] `DTO/UpdatePaymentTaxRequestTest`: solo status, solo taxName, ambos, body vacío, `taxName: null`, `taxName: ""`
- [ ] `DTO/PaymentTaxResponseTest`: claves exactas, sin `notaryId`; `fromCollection`
- [ ] `Service/PaymentTaxServiceTest` (repository mockeado):
  - create OK / duplicado
  - update inexistente → `null`
  - update solo status → **no** llama a `existsByNameAndNotary`, conserva el nombre
  - update con nombre duplicado → string de error
  - update con el mismo nombre → `existsByNameAndNotary` recibe `excludeId = id` → `true`

### Pruebas manuales / integración (QA)
- [ ] Escenarios 1–23 vía curl/Postman contra Docker
- [ ] Multi-tenant: token de notaría 1 no puede ver ni editar `id_tax = 9` (IVA, notaría 3) → 404
- [ ] `?name=subdivision` encuentra registros con "Subdivisión"
- [ ] `?name=%` no devuelve todo (comodín escapado)
- [ ] POST `{"taxName": "Subdivision"}` con "Derechos Subdivisión" existente → 201: **no** es duplicado (la unicidad compara el nombre completo, no parcial)
- [ ] POST `{"taxName": "derechos subdivision"}` con "Derechos Subdivisión" existente → 400 duplicado (insensible a acentos y mayúsculas)
- [ ] POST `{"taxName": "Gastos"}` cuando existe "Gastos" → 400 duplicado. POST `{"taxName": "  gastos  "}` → 400 duplicado
- [ ] POST con emoji → 400 (no 500)

## Criterios de Código
- [ ] `declare(strict_types=1);` en todos los archivos
- [ ] PSR-12; namespace `App\Module\PaymentTax\...`
- [ ] Typed properties y return types en todos los métodos
- [ ] Clases `final` para Entity y DTOs
- [ ] Sin dependencias a otros módulos (`PaymentTax` es autocontenido)
- [ ] Sin `var_dump`, `print_r` ni código comentado

## Performance
- [ ] Índice `(id_notary, id_status)` aplicado
- [ ] Una sola query por request en lectura; dos en create (exists + insert); hasta tres en update (find + exists + update)
- [ ] Sin paginación: catálogo pequeño (~10 registros por notaría), igual que deed-types

## Seguridad
- [ ] JWT requerido en las 4 rutas (401 sin token, con token inválido o expirado)
- [ ] Aislamiento por `id_notary` en SELECT y UPDATE
- [ ] 404 idéntico para "no existe" y "es de otra notaría" (no se filtra su existencia)
- [ ] `notaryId` no se acepta desde el cliente ni se expone en la respuesta
- [ ] Prepared statements en todas las queries
- [ ] Input de `LIKE` escapado

## Documentación
- [ ] `docs/openapi.yaml` actualizado (tag, paths, schemas y respuestas de error)

## Fuera de alcance (no bloquea aprobación)
- Mensajes exactos de 401 en inglés ("Unauthorized"/"Token expired"): `AuthMiddleware` actual responde en español. QA valida **status 401** (ver ADR-005)
