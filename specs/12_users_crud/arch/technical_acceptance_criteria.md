# Criterios de Aceptación Técnicos: Users CRUD

## Basado en: PO Acceptance Criteria
`specs/12_users_crud/po/acceptance_criteria.md` (escenarios 1–11), con los ajustes de `implementation_plan.md` (ADR-002 a ADR-007).

## Criterios por Capa

### Controller Layer (`UserController`)
- [ ] `GET /api/v3/users` → 200 con array de `User`; `?status=1|2` filtra; otro valor → 400 `Invalid status value`
- [ ] `GET /api/v3/users/{id}` → 200 con `User`; otra notaría o inexistente → 404 `User not found`
- [ ] `POST /api/v3/users` → 201 con `User` (body, no vacío)
- [ ] `PUT /api/v3/users/{id}` → 200 con `User`
- [ ] `{id}` no entero → 400 `Invalid ID format`; `{id} ≤ 0` → 404 `User not found`
- [ ] DTO inválido → 400 `{"error": "Validation failed", "errors": {...}}`
- [ ] `UserError::InvalidUsername` → 400 `Validation failed` con `errors.username` (mismo formato que el DTO)
- [ ] `CannotDeactivateSelf` → 403; `UsernameTaken` → 409; `UserNotFound` → 404
- [ ] El actor del PUT sale de `$request->getUserId()` y la notaría de `$request->getNotaryId()`; nunca del body
- [ ] Las 4 rutas registradas con `->withMiddleware('auth')`; sin token → 401

### Service Layer (`UserService`)
- [ ] `getById` lanza `UserNotFound` si el Repository devuelve `null`
- [ ] `create`: verifica `usernameExists` antes de insertar; hashea con `PASSWORD_BCRYPT` cost 10; relee y devuelve la Entity
- [ ] `create` y `update` traducen `DuplicateUsernameException` a `UsernameTaken`
- [ ] `update`: orden 404 → 403 → 400 username → 409 → escritura
- [ ] 403 solo cuando `id === actorUserId` **y** `dto.status === 2`; `status: 1` a sí mismo es válido
- [ ] Username igual al actual (comparación exacta) → no se valida formato ni unicidad
- [ ] Username distinto → formato (`InvalidUsername`) y luego unicidad excluyendo el propio id
- [ ] Campos no enviados conservan el valor existente; `statusId` crudo (p. ej. `0`) se preserva si no se envía `status`
- [ ] El Service no conoce HTTP (sin `Response`, sin códigos de estado)

### Repository Layer (`UserRepository`)
- [ ] Prepared statements en todas las queries
- [ ] `findAllByNotary` y `findByIdAndNotary` filtran por `id_notaria`
- [ ] `update` usa `WHERE id_usuario = :id AND id_notaria = :notaryId`
- [ ] `usernameExists` es **global** (sin filtro de notaría) y case-insensitive (`LOWER()`)
- [ ] El `SELECT` no incluye `contrasenia` ni `password_hash`
- [ ] Filtro `status=2` → `(id_estatus IS NULL OR id_estatus <> 1)`
- [ ] Orden: apellido paterno, apellido materno, nombre (collation `utf8mb4_unicode_ci`), luego `id_usuario`
- [ ] `create` inserta `contrasenia = NULL`, `ultimo_acceso = NULL`, `id_notaria` del token
- [ ] `update` no modifica `contrasenia`, `password_hash`, `ultimo_acceso` ni `id_notaria`
- [ ] Error PDO 1062 (`errorInfo[1]`) → `DuplicateUsernameException`; cualquier otro error se relanza

### Entity/DTO
- [ ] `User` final, readonly, sin propiedades de contraseña; `status()` devuelve 1 o 2
- [ ] `AccessCode` enum con `ESC` y `PAG`; `toCsv` produce orden canónico sin duplicados
- [ ] `CreateUserRequest`: requeridos `username`, `password`, `firstName`, `paternalLastName`, `access`; `status` default 1; `maternalLastName` default `''`
- [ ] `UpdateUserRequest`: parcial; body sin campos editables → `errors.body`; `password` presente → `errors.password`
- [ ] `UserResponse` devuelve exactamente: `id`, `username`, `firstName`, `paternalLastName`, `maternalLastName`, `access`, `status`, `lastAccess`
- [ ] `lastAccess` en formato `Y-m-d\TH:i:s` o `null`

## Validaciones (DTO / `UserFieldRules`)

| Campo | Regla | Notas |
|-------|-------|-------|
| `username` | string, `^[A-Za-z0-9._]{3,20}$` | Sin trim. PUT: formato solo si cambia (Service) |
| `password` | string, ≥ 8 caracteres, ≤ 72 bytes, sin `\0` | Sin trim. Solo POST |
| `firstName`, `paternalLastName` | string, no vacío tras trim, ≤ 150 caracteres, sin caracteres de 4 bytes | Se guardan con trim |
| `maternalLastName` | `null`, `''` o string con las reglas anteriores | `null` → `''` |
| `access` | lista no vacía de strings en `{ESC, PAG}` | Duplicados se eliminan; orden canónico |
| `status` | int estricto `1` o `2` | `"1"` (string) es inválido, igual que en Clients |

## Tests Requeridos

### Unit tests (mínimos)

**`AccessCodeTest`**
- [ ] `toCsv(['PAG', 'ESC', 'PAG'])` → `'ESC,PAG'`
- [ ] `fromCsv('ESC, PAG')` → `['ESC', 'PAG']`; `fromCsv('')` y `fromCsv(null)` → `[]`

**`UserTest`**
- [ ] `fromRow` mapea todas las columnas; `apellido_materno` NULL → `''`; `ultimo_acceso` NULL → `null`
- [ ] `status()` → 1 para `1`; 2 para `2`, `0` y `NULL`

**`UserFieldRulesTest`**
- [ ] username: válido `maria.lopez`, `a_b`; inválido `ab`, 21 caracteres, `FANNY RECEPCION`, `maría`, no-string
- [ ] password: 7 caracteres inválido, 8 válido, 73 bytes inválido, con `\0` inválido, `"        "` (8 espacios) válido
- [ ] nombres: vacío, solo espacios, 151 caracteres, emoji → inválidos; 150 caracteres con acentos → válido
- [ ] access: `[]`, `["XYZ"]`, `"ESC"`, `[1]`, `{"a":"ESC"}` → inválidos; `["ESC"]`, `["PAG","ESC"]` → válidos
- [ ] status: `1`, `2` válidos; `0`, `3`, `"1"`, `true` inválidos

**`CreateUserRequestTest`**
- [ ] Body válido completo y mínimo (defaults `status = 1`, `maternalLastName = ''`)
- [ ] Cada campo requerido ausente → error en su clave
- [ ] Ningún mensaje de error contiene el valor del password

**`UpdateUserRequestTest`**
- [ ] `{}` → `errors.body`
- [ ] `{"password": "x"}` → `errors.password` (y también `errors.body`, pues no hay campos editables)
- [ ] `{"username": "FANNY RECEPCION"}` → válido en el DTO
- [ ] `{"maternalLastName": null}` → `''`

**`UserResponseTest`**
- [ ] Conjunto exacto de claves (sin `password`, `passwordHash`, `notaryId`)
- [ ] `statusId = 0` → `status: 2`

**`UserServiceTest`** (Repository mockeado)
- [ ] `create` con username libre → `create` llamado con hash que pasa `password_verify` y empieza con `$2y$10$`
- [ ] `create` con username ocupado → `UsernameTaken`, `create` nunca llamado
- [ ] `create` con `DuplicateUsernameException` → `UsernameTaken`
- [ ] `update` inexistente → `UserNotFound`
- [ ] `update` a sí mismo con `status: 2` → `CannotDeactivateSelf`; con `status: 1` → OK
- [ ] `update` de otro usuario con `status: 2` → OK
- [ ] `update` con username igual al legacy inválido → OK, sin llamar `usernameExists`
- [ ] `update` con username nuevo inválido → `InvalidUsername`
- [ ] `update` con username nuevo ocupado → `UsernameTaken`; `usernameExists` recibe `excludeId = $id`
- [ ] `update` sin `status` sobre usuario con `statusId = 0` → `update` recibe `statusId = 0`

### Integration / manual (contra BD local, curl/Postman)
- [ ] Listado ordenado por apellido paterno; usuario 7 (`id_estatus = 0`) aparece con `status: 2` y en `?status=2`
- [ ] GET de un usuario de otra notaría → 404
- [ ] POST con `Maria.Lopez` cuando existe `maria.lopez` → 409
- [ ] POST exitoso: en BD `contrasenia IS NULL`, `password_hash` bcrypt, `id_notaria` del token; se puede hacer login con el usuario nuevo
- [ ] PUT al usuario 6 enviando `username: "FANNY RECEPCION"` + otro campo → 200
- [ ] Ninguna respuesta (incluidos errores) contiene `password` ni `password_hash`

## Criterios de Código
- [ ] PSR-12, `declare(strict_types=1)` en todos los archivos
- [ ] Typed properties y return types; clases `final` salvo `UserRepository`
- [ ] Sin dependencias de `Module/User` hacia `Module/Auth` ni al revés
- [ ] Principios SOLID; Service sin conocimiento de HTTP

## Performance
- [ ] Índice `(id_notaria, id_estatus)` para el listado
- [ ] Sin N+1 (una query por operación de lectura)
- [ ] Sin paginación (decenas de usuarios por notaría; fuera de alcance)

## Seguridad
- [ ] JWT requerido en las 4 rutas
- [ ] Multi-tenant: lectura y escritura filtradas por `notary_id` del token; `notaryId` en body se ignora
- [ ] Contraseñas: bcrypt cost 10, nunca leídas, devueltas ni registradas en logs
- [ ] Prepared statements en todas las queries
- [ ] No se puede auto-desactivar (403)

## Base de Datos
- [ ] Migración en `.db/pending/` con pre-checks, UP y DOWN
- [ ] Pre-check de duplicados en 0 filas antes de aplicar en producción
