# Plan de Implementación: Users CRUD

## Resumen

Nuevo módulo `User` con 4 endpoints (`GET` colección, `GET` por id, `POST`, `PUT`) sobre la tabla `usuarios_notaria`. Es un CRUD de una sola tabla y sigue el patrón de `PaymentClient` (DTOs con `FieldRules`, Entity `fromRow`, Repository concreto) con el manejo de errores de dominio por enum de `PaymentTransaction`.

Diferencias clave respecto a los catálogos 08–10:

1. **Respuestas con body** (ADR-002): `POST` → 201 con el usuario, `PUT` → 200 con el usuario. Misma excepción que tomó el spec 11.
2. **Username único global y case-insensitive** (ADR-003): índice `UNIQUE (usuario)` + verificación en la app con `LOWER()`. El login sigue siendo exact-match.
3. **Usernames legacy** (ADR-004): el formato del username solo se valida en `PUT` cuando el valor **cambia**.
4. **Regla de auto-desactivación** (403): el usuario autenticado no puede ponerse `status: 2`.
5. **Nunca se leen columnas de contraseña** en este módulo: el `SELECT` no incluye `contrasenia` ni `password_hash` (ADR-006).

### Hallazgos en la BD local (2026-11-11)

| Dato | Valor |
|------|-------|
| Usuarios / notarías | 12 / 4 |
| Motor y collation | MyISAM, `utf8mb3_bin` |
| Índices | Solo `PRIMARY (id_usuario)` |
| `usuario` duplicado (exacto / case-insensitive) | 0 / 0 |
| Usernames que no cumplen `^[A-Za-z0-9._]{3,20}$` | 1: id 6 `FANNY RECEPCION` (notaría 3, activo) |
| `id_estatus` | 11 con `1`, 1 con `0` (id 7 `Fati`, notaría 1), 0 `NULL` |
| `user_access` | 11 `ESC,PAG`, 1 `PAG` (sin códigos desconocidos) |
| Sin `password_hash` (solo MD5 legacy) | 10 de 12 |
| `ultimo_acceso` NULL / fecha cero | 0 / 0 |
| `apellido_materno` | Columna `NOT NULL DEFAULT ''`, nunca NULL |
| Nombres con espacios al inicio/fin | 1 (solo se normaliza al escribir) |

## Arquitectura Propuesta

### Diagrama de Componentes

```
HTTP  GET  /api/v3/users[?status=1|2]
      GET  /api/v3/users/{id}
      POST /api/v3/users
      PUT  /api/v3/users/{id}
    │
    ▼
Router ──► AuthMiddleware (JWT → notaryId, userId)  ── 401
               │
               ▼
          UserController
            │  status query param  → 400 "Invalid status value"
            │  parseId('id')        → 400 "Invalid ID format" | 404 "User not found"
            │  DTO::fromArray()     → 400 "Validation failed" + errors
            │  catch UserException  → match(error) → 400 | 403 | 404 | 409
            ▼
          UserService
            │  create(): username libre (CI) → hash bcrypt → insert → relee
            │  update(): existe → no auto-desactivación → username cambió?
            │            (formato + libre) → update → relee
            ▼
          UserRepository ──► usuarios_notaria   (MyISAM, UNIQUE usuario)
            │  1062 → DuplicateUsernameException
            ▼
          User (Entity, sin contraseñas)
            ▼
          UserResponse
```

### Módulos Afectados

- [ ] `src/Module/User/` - **Nuevo** módulo (Entity×2, DTO×4, Exception×3, Repository, Service, Controller)
- [ ] `src/bootstrap.php` - Registrar `UserRepository` y `UserService`
- [ ] `src/Config/routes.php` - 4 rutas protegidas; borrar el bloque `// Users` del grupo comentado
- [ ] `.db/pending/` - 1 migración (`UNIQUE (usuario)` + índice por notaría)
- [ ] `docs/openapi.yaml` - Tag `Users`, paths `/users` y `/users/{id}`
- [ ] `tests/Module/User/` - Tests unitarios

**No se modifican:** `Module/Auth/*` (`UserAuthRepository`, `AuthService`, `JwtService`, `AuthUser`), `AuthMiddleware`.

## Reglas de Negocio Consolidadas

### Mapeo de `status`

| `id_estatus` en BD | `status` en API | Filtro `?status=` que lo incluye |
|--------------------|-----------------|----------------------------------|
| `1` | `1` | `1` |
| `2`, `0`, `NULL`, otro | `2` | `2` |

- La Entity conserva el valor crudo (`statusId`) y expone `status(): int` normalizado.
- En `PUT` sin `status`, se reescribe el valor crudo existente (un `0` sigue siendo `0`). Solo cambia si el request envía `status`.

### Mapeo de `access`

- BD → API: `explode(',')` + `trim` + descartar vacíos. Se devuelven tal cual (no se filtran códigos desconocidos; hoy no existen).
- API → BD: se validan contra `AccessCode`, se eliminan duplicados y se guardan en **orden canónico** (`ESC,PAG`), sin importar el orden del request.

### Precedencia de validaciones

**GET colección:** 401 → 400 `status` → 200.

**GET por id:** 401 → 400/404 formato de `{id}` → 404 (no existe o es de otra notaría) → 200.

**POST:** 401 → 400 body (DTO) → 409 username ocupado (case-insensitive) → escritura (1062 → 409) → 201.

**PUT:** 401 → 400/404 formato de `{id}` → 400 body (DTO) → 404 usuario → 403 auto-desactivación → (solo si `username` cambia) 400 formato de username → 409 username ocupado → escritura (1062 → 409) → 200.

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 30 min

#### 0.1 Migración de índices
- **Archivo:** `.db/pending/20261111_120000_add_unique_username_to_usuarios_notaria.sql`
- **Tipo:** Crear
- **Descripción:**
  - `UNIQUE INDEX uq_usuarios_notaria_usuario (usuario)` (nombre alineado con `uq_payments_transactions_notary_uuid`, en lugar del `idx_usuarios_notaria_usuario_unique` del PO).
  - `INDEX idx_usuarios_notaria_notary_status (id_notaria, id_estatus)` para el listado (mismo patrón que las migraciones `add_notary_status_index_*`).
  - Pre-checks en comentarios: duplicados exactos **y** case-insensitive (SQL completo en `developer_notes.md`).
  - No se cambia motor, collation ni datos. El usuario 7 (`id_estatus = 0`) y el 6 (`FANNY RECEPCION`) se quedan como están.
- **Dependencias:** Ninguna

### Fase 1: Dominio (Entity, enum, errores)
Estimación: 1 h

#### 1.1 Enum `AccessCode`
- **Archivo:** `src/Module/User/Entity/AccessCode.php`
- **Tipo:** Crear
- **Descripción:** `enum AccessCode: string { case Deeds = 'ESC'; case Payments = 'PAG'; }`. Métodos estáticos:
  - `values(): string[]` → `['ESC', 'PAG']` (en el orden de los cases; define el orden canónico).
  - `toCsv(array $codes): string` → recibe códigos ya validados, devuelve `implode(',')` en orden canónico sin duplicados.
  - `fromCsv(?string $csv): string[]` → `explode` + `trim` + sin vacíos (no valida).
- **Dependencias:** Ninguna

#### 1.2 Entity `User`
- **Archivo:** `src/Module/User/Entity/User.php`
- **Tipo:** Crear
- **Descripción:** `final`, propiedades `readonly`: `id`, `notaryId`, `username`, `firstName`, `paternalLastName`, `maternalLastName`, `access` (`string[]`), `statusId` (int crudo), `lastAccess` (`?DateTimeImmutable`). Constantes `STATUS_ACTIVE = 1`, `STATUS_INACTIVE = 2`. Métodos:
  - `fromRow(array $row)`: mapea `id_usuario`, `id_notaria`, `usuario`, `nombre`, `apellido_paterno`, `apellido_materno` (NULL → `''`), `user_access` (`AccessCode::fromCsv`), `id_estatus` (NULL → 0), `ultimo_acceso` (NULL → null).
  - `status(): int` → `1` si `statusId === 1`, si no `2`.
  - **No** tiene propiedades de contraseña.
- **Dependencias:** 1.1

#### 1.3 Enum `UserError` + `UserException` + `DuplicateUsernameException`
- **Archivos:** `src/Module/User/Exception/{UserError,UserException,DuplicateUsernameException}.php`
- **Tipo:** Crear
- **Descripción:** Mismo patrón que `TransactionError` / `TransactionException` (spec 11).

  | Caso | Mensaje | HTTP |
  |------|---------|------|
  | `UserNotFound` | `User not found` | 404 |
  | `UsernameTaken` | `Username already exists` | 409 |
  | `CannotDeactivateSelf` | `Cannot deactivate your own account` | 403 |
  | `InvalidUsername` | `Username must be 3 to 20 characters (letters, digits, . or _)` | 400 |

  `UserException(UserError $error)` extiende `\DomainException`. `DuplicateUsernameException` extiende `\RuntimeException`; la lanza solo el Repository al chocar con el UNIQUE (1062) y la consume el Service.
- **Dependencias:** Ninguna

### Fase 2: DTOs
Estimación: 2 h

#### 2.1 `UserFieldRules`
- **Archivo:** `src/Module/User/DTO/UserFieldRules.php`
- **Tipo:** Crear
- **Descripción:** Métodos estáticos que devuelven `?string` (mensaje de error o `null`), estilo `ClientFieldRules`:
  - `username(mixed $v)`: string, `^[A-Za-z0-9._]{3,20}$`. **Sin** `trim` ni cambio de mayúsculas. Mismo mensaje que `UserError::InvalidUsername`.
  - `password(mixed $v)`: string, `mb_strlen ≥ 8`, `strlen ≤ 72` (límite de bcrypt), sin byte nulo (`password_hash` lanza `ValueError`). **Sin** `trim`.
  - `requiredName(mixed $v, string $label)`: string, no vacío tras `trim`, `mb_strlen ≤ 150`, sin caracteres de 4 bytes (columna utf8mb3).
  - `optionalName(mixed $v, string $label)`: igual pero acepta `null` y `''`.
  - `access(mixed $v)`: lista (`array_is_list`) de strings, al menos 1, todos en `AccessCode::values()`.
  - `status(mixed $v)`: `is_int` y en `[1, 2]`.
  - `normalizeOptional(mixed $v): string` → `trim` o `''`.
- **Dependencias:** 1.1

#### 2.2 `CreateUserRequest`
- **Archivo:** `src/Module/User/DTO/CreateUserRequest.php`
- **Tipo:** Crear
- **Descripción:** `fromArray()/isValid()/getErrors()`. Requeridos: `username`, `password`, `firstName`, `paternalLastName`, `access`. Opcionales: `maternalLastName` (default `''`), `status` (default 1). Nombres con `trim`. `access` se guarda en el DTO como `string[]` validado; la conversión a CSV canónico (`AccessCode::toCsv()`) ocurre solo en el Repository. Campos ausentes → `<Field> is required`.
- **Regla de seguridad:** el valor de `password` nunca se incluye en mensajes de error.
- **Dependencias:** 2.1

#### 2.3 `UpdateUserRequest`
- **Archivo:** `src/Module/User/DTO/UpdateUserRequest.php`
- **Tipo:** Crear
- **Descripción:** Actualización parcial: propiedad `null` = "no enviado, conservar". Campos: `username`, `firstName`, `paternalLastName`, `maternalLastName`, `access`, `status`.
  - Body sin ninguno de esos campos → `errors.body = "At least one field (username, firstName, paternalLastName, maternalLastName, access, status) is required"`.
  - `username`: el DTO solo valida que sea string (`Username must be a string`). El formato lo valida el Service **solo si cambia** (ADR-004).
  - `password` presente → `errors.password = "Password cannot be changed through this endpoint"` (ADR-005).
  - `maternalLastName: null` o `""` → `''` (borra el valor).
  - `firstName`/`paternalLastName`/`access`/`status` presentes se validan igual que en POST.
- **Dependencias:** 2.1

#### 2.4 `UserResponse`
- **Archivo:** `src/Module/User/DTO/UserResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity(User): array` y `fromCollection(User[]): array`. Exactamente: `id`, `username`, `firstName`, `paternalLastName`, `maternalLastName`, `access` (array), `status` (`$user->status()`, normalizado), `lastAccess` (`Y-m-d\TH:i:s` o `null`, igual que `SubdivisionResponse`). Nada de `notaryId` ni contraseñas.
- **Dependencias:** 1.2

### Fase 3: Repository
Estimación: 1 h

#### 3.1 `UserRepository`
- **Archivo:** `src/Module/User/Repository/UserRepository.php`
- **Tipo:** Crear (clase no `final`, para mocks)
- **Descripción:** Constante `COLUMNS` **sin** `contrasenia` ni `password_hash`. Métodos:
  - `findAllByNotary(int $notaryId, ?int $status): User[]` → `status = 1` → `id_estatus = 1`; `status = 2` → `(id_estatus IS NULL OR id_estatus <> 1)`. Orden: `apellido_paterno`, `apellido_materno`, `nombre` (los tres con `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci`, como `PaymentClientRepository`), luego `id_usuario`.
  - `findByIdAndNotary(int $id, int $notaryId): ?User`
  - `usernameExists(string $username, ?int $excludeId = null): bool` → **global** (sin filtro de notaría), `LOWER(usuario) = LOWER(:username)`.
  - `create(int $notaryId, CreateUserRequest $dto, string $passwordHash): int` → INSERT con `contrasenia = NULL`, `ultimo_acceso = NULL`. 1062 → `DuplicateUsernameException`.
  - `update(User $user): void` → `UPDATE ... SET usuario, nombre, apellido_paterno, apellido_materno, user_access, id_estatus WHERE id_usuario = :id AND id_notaria = :notaryId`. No toca contraseñas ni `ultimo_acceso`. 1062 → `DuplicateUsernameException`.
- **Dependencias:** 1.x, 2.2, 0.1

### Fase 4: Service
Estimación: 1.5 h

#### 4.1 `UserService`
- **Archivo:** `src/Module/User/Service/UserService.php`
- **Tipo:** Crear
- **Constructor:** `UserRepository`.
- **Métodos:**
  - `getAll(int $notaryId, ?int $status): User[]`
  - `getById(int $id, int $notaryId): User` → `UserNotFound`.
  - `create(CreateUserRequest $dto, int $notaryId): User`
    1. `usernameExists($dto->username)` → `UsernameTaken`.
    2. `password_hash($dto->password, PASSWORD_BCRYPT, ['cost' => 10])`.
    3. `create(...)`; si captura `DuplicateUsernameException` → `UsernameTaken`.
    4. Relee con `findByIdAndNotary` y devuelve.
  - `update(int $id, UpdateUserRequest $dto, int $notaryId, int $actorUserId): User`
    1. `findByIdAndNotary` → `UserNotFound`.
    2. `$id === $actorUserId && $dto->status === 2` → `CannotDeactivateSelf`.
    3. Si `$dto->username !== null && $dto->username !== $existing->username` (comparación exacta): `UserFieldRules::username()` → `InvalidUsername`; `usernameExists($dto->username, excludeId: $id)` → `UsernameTaken`.
    4. Construye `User` con `dto ?? existing` por campo (`statusId` crudo si no se envía `status`) y llama `update`; `DuplicateUsernameException` → `UsernameTaken`.
    5. Relee y devuelve.
- **Dependencias:** 1.x, 2.x, 3.1

### Fase 5: Controller, rutas y wiring
Estimación: 45 min

#### 5.1 `UserController`
- **Archivo:** `src/Module/User/Controller/UserController.php`
- **Tipo:** Crear
- **Descripción:** `index`, `show`, `store`, `update`. `parseId` igual que `PaymentClientController` (`'User not found'`). `index` valida `status` en `['1', '2']` → si no, 400 `Invalid status value`. `store` → `Response::created(UserResponse::fromEntity(...))`. `update` pasa `$request->getUserId()` como actor y responde `Response::json(...)` (200). Mapper privado `errorResponse(UserError)`:
  - `InvalidUsername` → `badRequest('Validation failed', ['username' => $error->message()])`
  - `CannotDeactivateSelf` → `forbidden`
  - `UserNotFound` → `notFound`
  - `UsernameTaken` → `conflict`
- **Dependencias:** 4.1

#### 5.2 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `UserRepository` (`Connection::getInstance()`) y `UserService`.
- **Dependencias:** 3.1, 4.1

#### 5.3 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:**
  ```php
  // Users routes (protected)
  $router->get('/api/v3/users', [UserController::class, 'index'])->withMiddleware('auth');
  $router->get('/api/v3/users/{id}', [UserController::class, 'show'])->withMiddleware('auth');
  $router->post('/api/v3/users', [UserController::class, 'store'])->withMiddleware('auth');
  $router->put('/api/v3/users/{id}', [UserController::class, 'update'])->withMiddleware('auth');
  ```
  Eliminar el bloque `// Users` del grupo comentado al final del archivo.
- **Dependencias:** 5.1

### Fase 6: Tests y documentación
Estimación: 2.5 h

#### 6.1 Tests unitarios
- **Archivos:** `tests/Module/User/{Entity,DTO,Exception,Service}/...Test.php`
- **Tipo:** Crear
- **Descripción:** Casos mínimos en `technical_acceptance_criteria.md`. El hash se verifica con `password_verify()` sobre el argumento capturado del mock del Repository.
- **Dependencias:** Fases 1–4

#### 6.2 OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Tag `Users`, paths `/users` (GET, POST) y `/users/{id}` (GET, PUT), schemas `User`, `CreateUserRequest`, `UpdateUserRequest`. Documentar: `status` normalizado (0 → 2), unicidad global case-insensitive, `access` en orden canónico, PUT parcial, `password` rechazado en PUT, 403 por auto-desactivación.
- **Dependencias:** 5.3

## Orden de Implementación

1. **Migración en local (0.1)**: el 1062 debe existir para probar la carrera.
2. **`AccessCode`, Entity y errores (1.1–1.3)** + tests: piezas aisladas.
3. **`UserFieldRules` y DTOs (2.1–2.4)** + tests: concentran casi todas las validaciones.
4. **Repository (3.1)**: probar contra la BD local (orden, filtro `status=2` con el usuario 7, `LOWER()`, 1062).
5. **Service (4.1)** + tests con mocks: reglas 403/409/400 de username.
6. **bootstrap (5.2) → Controller (5.1) → rutas (5.3)**: probar con curl/Postman.
7. **OpenAPI (6.2)**.

**Estimación total: ~9 h.**

## Catálogo de Errores (para frontend)

| HTTP | Campo en body | Mensaje | Causa |
|------|---------------|---------|-------|
| 400 | `error` | `Invalid status value` | `GET /users?status=` distinto de `1` o `2` |
| 400 | `error` | `Invalid ID format` | `{id}` no es entero |
| 400 | `error` | `Validation failed` | Body inválido; ver `errors` |
| 400 | `errors.body` | `At least one field (...) is required` | PUT sin campos editables |
| 400 | `errors.username` | `Username is required` / `Username must be a string` / `Username must be 3 to 20 characters (letters, digits, . or _)` | POST; PUT solo si cambia |
| 400 | `errors.password` | `Password is required` / `Password must be a string` / `Password must be at least 8 characters` / `Password must not exceed 72 bytes` / `Password contains invalid characters` | POST |
| 400 | `errors.password` | `Password cannot be changed through this endpoint` | PUT con `password` |
| 400 | `errors.firstName` / `paternalLastName` | `<Field> is required` / `<Field> must be a string` / `<Field> must not exceed 150 characters` / `<Field> contains unsupported characters` | |
| 400 | `errors.maternalLastName` | `Maternal last name must be a string` / `... must not exceed 150 characters` / `... contains unsupported characters` | |
| 400 | `errors.access` | `Access is required` / `Access must be an array` / `At least one access code is required` / `Access codes must be one of: ESC, PAG` | Escenarios 10 y 11 |
| 400 | `errors.status` | `Status must be 1 or 2` | |
| 401 | `error` | (mensaje del AuthMiddleware) | Token ausente, inválido o expirado |
| 403 | `error` | `Cannot deactivate your own account` | PUT a sí mismo con `status: 2` |
| 404 | `error` | `User not found` | No existe, `{id} ≤ 0` o es de otra notaría |
| 409 | `error` | `Username already exists` | Username ocupado en cualquier notaría (case-insensitive) |

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Producción tiene usernames duplicados (exactos) → falla el `CREATE UNIQUE INDEX` | Baja (local: 0; el login por username ya asume unicidad) | Alto | Pre-check obligatorio. Si hay duplicados, **detenerse y avisar**: requieren decisión de negocio (renombrar a uno) |
| Producción tiene duplicados case-insensitive (`maria`/`Maria`) | Baja (local: 0) | Bajo | Pre-check informativo. No bloquea el índice; la regla CI solo aplica a escrituras nuevas. Reportar al PO |
| La v1 crea usuarios con username repetido y ahora falla por el UNIQUE | Baja | Medio | Es el comportamiento deseado (un username duplicado rompe el login). Confirmar antes del deploy si la v1 tiene alta de usuarios |
| Carrera entre `maria` y `Maria` creados al mismo tiempo (el UNIQUE es binario) | Muy baja | Bajo | Aceptado (ADR-003). La carrera exacta sí la cubre el 1062 |
| Un usuario desactivado conserva su access token hasta que expira (≤ 2 h) | Media | Medio | El refresh ya falla (`findActiveById` exige `id_estatus = 1`). Revocación inmediata queda fuera de alcance; documentado en FAQ |
| Cambiar `access` de un usuario no se refleja en su token hasta el siguiente refresh/login | Media | Bajo | Mismo mecanismo; documentado |
| Se filtra el password en logs o respuestas de error | Baja | Alto | DTO nunca usa el valor en mensajes; el `SELECT` del Repository no lee columnas de contraseña; test que lo verifica |
| Contraseñas > 72 bytes se truncarían silenciosamente en bcrypt | Baja | Medio | Validación `strlen ≤ 72` en el DTO |
| Usernames legacy inválidos (`FANNY RECEPCION`) bloquean ediciones | Media | Medio | ADR-004: el formato solo se valida si el username cambia |
| Usuarios con `id_estatus = 0` se reactivan/alteran sin querer al editar otro campo | Baja | Bajo | El PUT reescribe el `statusId` crudo cuando no se envía `status` |

## Decisiones de Arquitectura

### ADR-001: Repository propio, independiente de `UserAuthRepository`
- **Contexto:** `UserAuthRepository` lee contraseñas y hace JOIN con `notarias` para el login. El CRUD necesita otras queries (por notaría, listado, escritura) y nunca debe tocar contraseñas.
- **Decisión:** `UserRepository` nuevo en `Module/User`. El módulo `Auth` no se modifica.
- **Consecuencias:** Dos repositorios sobre la misma tabla con responsabilidades separadas (autenticación vs. administración). Si en el futuro se agrega cambio de contraseña, irá en `Auth` o en un método dedicado de `UserRepository`, no en el PUT.

### ADR-002: Respuestas con body (excepción al ADR-005 de los specs 08/09/10)
- **Contexto:** El PO pide 201 con el usuario y 200 en PUT. El servidor genera el `id` y normaliza `access` y `status`.
- **Decisión (confirmada con el equipo):** POST → 201 con `User`; PUT → 200 con `User`; GET → 200. Mismo schema en todos.
- **Consecuencias:** El Service relee después de escribir (1 query extra). Los catálogos 07–10 conservan su patrón sin body.

### ADR-003: Unicidad global case-insensitive: índice binario + verificación en la app
- **Contexto:** La columna es `utf8mb3_bin`; un `UNIQUE (usuario)` permite `maria` y `Maria`, lo que confunde a los usuarios. Cambiar la collation volvería case-insensitive el login de la v1, y una columna generada con `LOWER()` es invasiva mientras la v1 escribe la tabla.
- **Decisión (confirmada con el equipo):** `UNIQUE (usuario)` para duplicados exactos (y carreras), más `usernameExists()` con `LOWER(usuario) = LOWER(:username)` en POST y en PUT cuando el username cambia (excluyendo al propio usuario). El login sigue siendo exact-match. La búsqueda es global, no por notaría, porque el login no recibe notaría.
- **Consecuencias:** `usernameExists` no usa el índice (full scan); aceptable con decenas de usuarios. Una carrera entre variantes de mayúsculas no la detecta el índice (riesgo aceptado). Un usuario puede cambiar las mayúsculas de su propio username.

### ADR-004: El formato del username solo se valida cuando cambia
- **Contexto:** Existe al menos un username legacy (`FANNY RECEPCION`) que no cumple la regla nueva. Si el frontend reenvía el objeto completo del GET, el PUT fallaría al editar cualquier otro campo.
- **Decisión (confirmada con el equipo):** En PUT el DTO solo verifica que `username` sea string; el Service valida formato y unicidad únicamente si difiere (comparación exacta) del actual. En POST se valida siempre.
- **Consecuencias:** La validación de username en PUT vive en el Service (`UserError::InvalidUsername`), pero el Controller la responde en el mismo formato `Validation failed` + `errors.username`, así que para el frontend es indistinguible. No se migran datos; el login legacy sigue funcionando.

### ADR-005: `password` rechazado explícitamente en PUT
- **Contexto:** El cambio de contraseña está fuera de alcance. Ignorar el campo en silencio haría creer al frontend que la contraseña cambió.
- **Decisión:** `PUT` con `password` → 400 `errors.password = "Password cannot be changed through this endpoint"`.
- **Consecuencias:** Cuando exista el endpoint de cambio de contraseña, este error orienta al frontend. Los demás campos desconocidos se siguen ignorando, como en el resto del API.

### ADR-006: El módulo nunca lee columnas de contraseña
- **Contexto:** Criterio de rechazo del PO: exponer password/password_hash.
- **Decisión:** `COLUMNS` del Repository excluye `contrasenia` y `password_hash`; la Entity `User` no tiene esas propiedades; el hash solo existe como argumento de `create()`.
- **Consecuencias:** Imposible serializar una contraseña por accidente desde este módulo. Un test del Response verifica el conjunto exacto de claves.

### ADR-007: `status` normalizado en la API, valor crudo preservado en BD
- **Contexto:** Hay `id_estatus = 0` en datos legacy; el PO lo define como inactivo. Reescribirlo podría afectar a la v1.
- **Decisión:** Sin migración de datos. La Entity guarda el valor crudo y expone `status()` (1 o 2). El filtro `status=2` incluye todo lo distinto de 1. El PUT solo cambia `id_estatus` si el request trae `status`.
- **Consecuencias:** La API nunca devuelve `0`. Un usuario con `0` queda en `2` solo cuando alguien lo edita enviando `status`.

### ADR-008: Sin transacciones ni cambio de motor
- **Contexto:** Spec 11 migró tablas a InnoDB por escribir dos tablas atómicamente. Aquí cada operación es un solo `INSERT`/`UPDATE` de una fila.
- **Decisión:** La tabla queda en MyISAM; no se usa `TransactionManager`.
- **Consecuencias:** Ningún riesgo de bloqueo por `ALTER ENGINE` sobre una tabla que usa el login de la v1. Solo se agregan índices.
