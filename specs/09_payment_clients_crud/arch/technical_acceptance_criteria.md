# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
`specs/09_payment_clients_crud/po/acceptance_criteria.md`

### Ajustes respecto al PO (ver ADRs en `implementation_plan.md`)

| Escenario PO | Comportamiento PO | Comportamiento técnico | ADR |
|--------------|-------------------|------------------------|-----|
| 3 (filtro nombre) | `LOWER() LIKE` | `CONVERT ... COLLATE utf8mb4_unicode_ci` (también insensible a acentos) | 001 |
| 6, 7, 10 (POST) | 201 con body | 201 **sin body** | 004 |
| 11, 12 (PUT) | 200 | 204 **sin body** | 004 |
| Edge: `?status=3` | ignorar o 400 | 400 `Invalid status value` | 006 |

## Criterios por Capa

### Controller Layer
- [ ] `GET /api/v3/payment-clients` → 200 con array (vacío si no hay registros)
- [ ] `GET /api/v3/payment-clients?status=1|2` filtra; cualquier otro valor → 400 `Invalid status value`
- [ ] `GET /api/v3/payment-clients?name=xyz` filtra por coincidencia parcial; `name` se recorta; `name` vacío = sin filtro
- [ ] `GET /api/v3/payment-clients/{id}` → 200 / 404 `Client not found`
- [ ] `POST /api/v3/payment-clients` → 201 sin body / 400
- [ ] `PUT /api/v3/payment-clients/{id}` → 204 sin body / 400 / 404
- [ ] ID no numérico (`abc`, `1.5`) → 400 `Invalid ID format`; ID `<= 0` → 404
- [ ] La validación del body (400) ocurre antes de buscar el registro: un PUT inválido a un ID inexistente → 400
- [ ] `DELETE` no está registrado → 405 del router
- [ ] Todas las rutas con `->withMiddleware('auth')` → 401 sin token / token inválido / expirado

### Service Layer
- [ ] `PaymentClientService` sin acceso directo a PDO ni a `Request`
- [ ] `create()` asigna `notaryId` del token, nunca del body (un `notaryId` en el body se ignora)
- [ ] `update()` devuelve `false` cuando el ID no pertenece a la notaría (sin distinguir "no existe" de "otra notaría")
- [ ] PUT parcial: los campos ausentes conservan su valor actual
- [ ] PUT con `"phone": ""` / `"email": ""` / `null` limpia el valor
- [ ] Sin validación de unicidad de nombre

### Repository Layer
- [ ] **Todas** las queries (`SELECT`, `UPDATE`) incluyen `id_notary = :notaryId`
- [ ] `INSERT` incluye `id_notary` del token
- [ ] Solo prepared statements con parámetros nombrados; sin concatenar input
- [ ] Búsqueda con `CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :name ESCAPE '!'`
- [ ] Comodines `%`, `_` y `!` del término escapados
- [ ] `ORDER BY` alfabético natural + `id_client` como desempate
- [ ] `SELECT` lista columnas explícitas (no `SELECT *`): `id_client, id_notary, full_name, phone, email, id_status`

### Entity/DTO
- [ ] Entity `PaymentClient` `final`, propiedades `readonly` tipadas, `fromRow()` con `id_status NULL` → 1
- [ ] `ClientFieldRules` concentra la validación de `fullName`, `phone`, `email` y `status`
- [ ] `CreatePaymentClientRequest` y `UpdatePaymentClientRequest` con `fromArray()` / `isValid()` / `getErrors()`
- [ ] Los DTOs nunca lanzan `TypeError` ante tipos JSON inesperados (número, bool, array, objeto)
- [ ] Todos los strings se guardan con `trim()` aplicado
- [ ] `PaymentClientResponse` expone **solo** `clientId`, `fullName`, `phone`, `email`, `status` (tipos: int, string, string, string, int)

### Validaciones (por campo)

| Campo | POST | PUT | Regla |
|-------|------|-----|-------|
| `fullName` | Requerido | Opcional | string, no vacío tras trim, ≤ 250 (`mb_strlen`), sin 4 bytes |
| `phone` | Opcional (`''`) | Opcional | string o `null`, ≤ 20 tras trim, sin 4 bytes |
| `email` | Opcional (`''`) | Opcional | string o `null`, ≤ 250 tras trim, `FILTER_VALIDATE_EMAIL` si no vacío |
| `status` | Opcional (1) | Opcional | `int` estricto 1 o 2 (`"1"` → 400) |
| (body) | — | ≥ 1 campo | Al menos uno de los 4 campos presente |

## Tests Requeridos

- [ ] `ClientFieldRulesTest`: cada regla con válido, vacío, solo espacios, límite exacto (250/20), límite+1, tipo incorrecto, `null`, emoji, email inválido, email con espacios alrededor
- [ ] `CreatePaymentClientRequestTest`: solo `fullName` → phone/email `''` y status 1; todos los campos; `fullName` ausente; errores múltiples acumulados
- [ ] `UpdatePaymentClientRequestTest`: body vacío; solo un campo; `phone: ""` → `''` (no `null`); `fullName: null` → error; campo ausente → `null`
- [ ] `PaymentClientResponseTest`: claves exactas, sin `notaryId`
- [ ] `PaymentClientTest` (Entity): `fromRow()` con y sin `id_status`
- [ ] `PaymentClientServiceTest` (mocks): create pasa `notaryId`; update inexistente → `false` y no llama `update`; merge parcial conserva campos; limpieza de email
- [ ] `composer test` sin regresiones en la suite completa

## Criterios de Código
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] PSR-12
- [ ] Typed properties y return types en todo
- [ ] Clases `final` (excepto el Repository, para poder mockearlo, igual que PaymentProcedure)
- [ ] Sin dependencias a otros módulos de Payment
- [ ] Mensajes de error y campos JSON en inglés

## Performance
- [ ] Índice `idx_payments_clients_notary_status (id_notary, id_status)` creado vía migración
- [ ] `EXPLAIN` del listado usa el índice nuevo (`key = idx_payments_clients_notary_status`)
- [ ] Una sola query por request (sin N+1)
- [ ] Sin paginación (fuera de alcance)

## Seguridad
- [ ] JWT requerido en los 4 endpoints
- [ ] `notaryId` siempre del token
- [ ] GET/PUT de un cliente de otra notaría → 404 idéntico al de "no existe"
- [ ] Input recortado y validado por tipo antes de llegar a BD
- [ ] Sin SQL injection (prepared statements; `LIKE` con escape)
- [ ] Respuesta sin `notaryId`, `created_at`, `modified_at`

## Documentación
- [ ] `docs/openapi.yaml` con tag `PaymentClients`, paths, schemas y códigos 200/201/204/400/401/404
- [ ] OpenAPI documenta la semántica ausente / `""` / `null` del PUT
