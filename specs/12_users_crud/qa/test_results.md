# Resultados de Pruebas: Users CRUD

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests unitarios ejecutados:** 551 (1.004 aserciones; 121 nuevos del módulo User) — 551 pasados, 0 fallidos
- **Requests de API ejecutadas:** 198 registradas en `test_evidence/` + 12 en pruebas de concurrencia — 0 defectos del feature
- **Bugs encontrados en el feature:** 0
- **Observaciones (no bloqueantes):** 5 (ver "Observaciones"), una de ellas es un defecto **preexistente del Core** que conviene abrir como ticket aparte

## Pruebas Unitarias
- **Resultado:** PASS (`OK, but there were issues!` — la única deprecación de PHPUnit ya existe en `develop`)
- **Cobertura:** no medible (el contenedor no tiene Xdebug/PCOV). Revisión manual: los casos mínimos de `technical_acceptance_criteria.md` están cubiertos (AccessCode 6, User 6, UserFieldRules 11, CreateUserRequest 6, UpdateUserRequest 9, UserResponse 5, UserError 2, UserService 18 casos).
- **Comando:** `docker-compose exec -T api ./vendor/bin/phpunit`

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Listar usuarios de la notaría, sin password, ordenados por apellido paterno | PASS | Notaría 1 → 7 usuarios: Alonso, FLORES, GONZALEZ, Pérez, RAMIREZ, Trejo, VALERO (orden CI/acentos). Claves exactas: `access, firstName, id, lastAccess, maternalLastName, paternalLastName, status, username`; 0 coincidencias de `password/contrasenia/notaryId` |
| 2 | `?status=1` solo activos | PASS | 6 usuarios, todos `status: 1`. `?status=2` → solo usuario 7 (`id_estatus = 0`) |
| 3 | Detalle de usuario sin password | PASS | `GET /users/2` → 200 con las 8 claves |
| 4 | Usuario de otra notaría → 404 | PASS | n1→usuario 4 (n3): 404; n3→usuario 2 (n1): 404; también en PUT |
| 5 | Crear: `id_notaria` del token, bcrypt, `contrasenia = NULL`, 201 con usuario | PASS | BD: `id_notaria=1`, `contrasenia IS NULL`, `password_hash` `$2y$10$` (60 chars), `ultimo_acceso NULL`, `id_estatus=1`. Login con el usuario nuevo → 200; contraseña incorrecta → 401. `notaryId: 3` en el body se ignora |
| 6 | Username duplicado (cualquier notaría) → 409 | PASS | Exacto, `Maria.Lopez` vs `maria.lopez`, `DEVROB` vs legacy `devrob`, cross-notaría en ambos sentidos → 409 `Username already exists` |
| 7 | Modificar usuario → 200 | PASS | PUT parcial conserva el resto de campos; devuelve el usuario actualizado |
| 8 | Desactivar a otro usuario | PASS | `status: 2` → 200, BD `id_estatus=2`; el usuario ya no puede hacer login ni refresh (401); reactivar con `status: 1` → 200 |
| 9 | Auto-desactivación → 403 `Cannot deactivate your own account` | PASS | Usuario 2 y usuario 4: 403; con `status:2` + `firstName` no se escribe nada (fila idéntica, incl. hashes y `ultimo_acceso`); `status: 1` a sí mismo → 200 |
| 10 | `access` inválido → 400 | PASS | `["XYZ"]`, `"ESC"`, `[1]`, `{"a":"ESC"}`, `null`, `["esc"]`, `["ESC","XYZ"]`, `[null]`, `[["ESC"]]`, `true` → 400 `Access codes must be one of: ESC, PAG` / `Access must be an array` (POST y PUT) |
| 11 | `access: []` → 400 | PASS | `At least one access code is required` (POST y PUT) |
| NF | Password nunca en respuestas ni logs | PASS | Ningún body (incluidos errores 400/403/404/409) contiene password/hash; password de 7 chars no se refleja en el 400; logs de Apache/PHP sin contraseñas ni hashes; sin warnings PHP durante las pruebas |
| NF | Multi-tenancy en todas las queries | PASS | Ver sección de multi-tenancy |
| NF | Respuestas < 200 ms | PASS con observación | Ver "Rendimiento" (POST excede por bcrypt cost 10 exigido + sobrecarga del entorno Docker/Windows) |

### Validaciones del PO

| Campo | Casos probados | Estado |
|-------|----------------|--------|
| `username` | `ab`, 21 chars, `FANNY RECEPCION`, `maíra`, `a-b-c`, ` abc`, `abc `, `abc\n`, `a@b.com`, número, `null`, array, ausente → 400; 3 y 20 chars válidos → 201 | PASS |
| `password` | 7 chars, 73 bytes, NUL, número, `null`, ausente → 400; 72 bytes y 8 espacios → 201 y login exitoso; 71 chars contra el hash de 72 → 401 | PASS |
| `firstName` / `paternalLastName` | ausente, `""`, espacios, número, 151 chars, emoji → 400; 150 acentuados → 201; trim aplicado; `O'Brien"); DROP TABLE…` se guarda literal (tabla intacta) | PASS |
| `maternalLastName` | opcional (default `''`), `null` → `''`, 151 chars y número → 400 | PASS |
| `access` | ver criterios 10 y 11; duplicados eliminados y orden canónico `["PAG","ESC","PAG"]` → `["ESC","PAG"]` | PASS |
| `status` | `0`, `3`, `"1"`, `true`, `1.0`, `null`, `[]`, `-1` → 400; default 1; `2` en POST → 201 con `status: 2` | PASS |

### Casos Edge del PO

| Caso | Estado | Evidencia |
|------|--------|-----------|
| `id_estatus = 0` tratado como inactivo | PASS | Usuario 7 → `status: 2` en GET/lista y en `?status=2`; tras `PUT {"firstName"}` la BD conserva `0`; solo cambia a `2`/`1` cuando se envía `status` |
| Username case-sensitive en login | PASS | Tras renombrar a `Maria.Lopez`: login `Maria.Lopez` → 200, `maria.lopez` → 401 |
| `contrasenia` queda NULL en usuarios nuevos | PASS | Verificado en BD para todos los usuarios creados |

### Criterios de Rechazo del PO
| Criterio de rechazo | Estado |
|---------------------|--------|
| Exposición de password/password_hash | No ocurre |
| Acceso cross-notaría | No ocurre (lectura y escritura) |
| Auto-desactivación permitida | No ocurre |
| Aceptar códigos de `access` inválidos | No ocurre |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Controller: códigos y mensajes (`Invalid status value`, `Invalid ID format`, `User not found`, `Validation failed`+`errors`) | PASS | `id=abc/1.5/2abc` → 400; `0`/`-1`/`99999` → 404; `status=0/3/abc/''/1 OR 1=1/[]` → 400 |
| 2 | `InvalidUsername` en PUT → 400 `Validation failed` con `errors.username` (mismo formato que el DTO) | PASS | `PUT {"username":"a b"}` |
| 3 | Actor y notaría salen del token; nunca del body | PASS | `notaryId`/`id`/`lastAccess` en body ignorados en POST y PUT |
| 4 | Las 4 rutas con `auth`; sin token → 401 | PASS | Sin token, token basura y refresh-token como access → 401 en GET/POST/PUT |
| 5 | Orden de validación PUT: id → body → 404 → 403 → 400 username → 409 | PASS | `/users/abc` + body `{}` → 400 `Invalid ID format`; `/users/4` (otra notaría) `{}` → 400, `{status:2}` → 404 (no 403); self + `status:2` + username inválido → 403; username inválido + ocupado → 400; inexistente + username ocupado → 404 |
| 5b | 403 solo si `id === actor` **y** `status === 2` | PASS | `status:1` a sí mismo y `access` propio → 200; `"2"` string → 400 |
| 6 | Username igual al actual: sin validar formato ni unicidad | PASS | Usuario 6 `FANNY RECEPCION`: PUT con su username + otro campo → 200; solo username → 200 |
| 7 | Username distinto: formato y luego unicidad excluyendo el propio id | PASS | `fanny recepcion` → 400; `Fanny.Recepcion` → 200; cambio solo de mayúsculas en el propio username → 200; `devrob`/`DEVROB`/`devrob15`/`MIN.USER` → 409 |
| 8 | Campos no enviados conservan valor; `statusId` crudo preservado | PASS | Ver caso edge `id_estatus = 0` |
| 9 | `update` no modifica `contrasenia`, `password_hash`, `ultimo_acceso`, `id_notaria` | PASS | Comparación de fila completa (MD5 de columnas de contraseña) antes/después de múltiples PUT |
| 10 | `password` en PUT → 400 `Password cannot be changed through this endpoint` | PASS | Solo `password` → 400 (`errors.body` + `errors.password`); `password`+`firstName` → 400 sin escribir; la contraseña original sigue funcionando |
| 11 | `UserResponse`: exactamente 8 claves; `lastAccess` `Y-m-d\TH:i:s` o `null` | PASS | `"2026-11-05T09:49:17"`, `null` en usuarios nuevos |
| 12 | `status()` normaliza `0`/`NULL` a 2; `access` en orden canónico sin duplicados | PASS | Ver criterios anteriores |
| 13 | Prepared statements; sin `query()`/`exec()` en el módulo | PASS | `grep` sin coincidencias; probes SQLi (`status`, `id`, `username`, `firstName`) sin efecto |
| 14 | `usernameExists` global y case-insensitive | PASS | Cross-notaría y variantes de mayúsculas → 409 |
| 15 | Error 1062 → `DuplicateUsernameException` → 409 (carrera) | PASS | 6 POST paralelos mismo username → 1×201, 5×409, 0×500, 1 fila. 2 PUT paralelos al mismo username → 1×200, 1×409 |
| 16 | Listado: orden CI/acentos, filtro `status=2` = `NULL`/`<>1` | PASS | Ver criterio PO 1 y 2 |
| 17 | Código: `strict_types` en todos los archivos, clases `final` salvo `UserRepository`, sin dependencias User↔Auth, Service sin HTTP, `php -l` limpio | PASS | `grep`/`php -l` sin hallazgos |
| 18 | Índice `(id_notaria, id_estatus)` | PASS | `EXPLAIN`: rango con el índice para `status=2`; con 30 filas el optimizador elige scan completo para `status=1` (esperado en tablas pequeñas) |
| 19 | Sin N+1; sin paginación | PASS | 1 query por lectura; POST/PUT releen con 1 query adicional (ADR-002) |
| 20 | Migración con pre-checks, UP y DOWN; en `.db/` | PASS | Archivo en `.db/applied/` (aplicada en local según flujo de `.db/README.md`). **DOWN y UP ejecutados** en la BD local: índices eliminados y recreados correctamente; pre-check A (duplicados exactos) devuelve 0 filas |
| 21 | `docs/openapi.yaml` | PASS | Tag `Users`, rutas `/users` y `/users/{id}`, schemas `UserResponse/CreateUserRequest/UpdateUserRequest`; descripciones coinciden con el comportamiento (incluido `DELETE` → 405, comprobado) |

## Pruebas de API

Logs completos (request, status, body) en `test_evidence/01…06_*.log`; scripts reproducibles en `test_evidence/scripts/`.

### Endpoint: GET /api/v3/users

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Happy path | token n1 | 200, 7 usuarios ordenados | 200, 7 ordenados | PASS |
| Filtro activos | `?status=1` | 6 activos | 6 | PASS |
| Filtro inactivos | `?status=2` | usuario 7 (`0` → 2) | [7] | PASS |
| Status inválido | `0`, `3`, `abc`, vacío, `1 OR 1=1`, `[]` | 400 | 400 | PASS |
| Otra notaría | token n3 / n2 | solo sus usuarios | [5,4,6] / [3] | PASS |
| Sin auth | sin token / basura / refresh token | 401 | 401 | PASS |

### Endpoint: GET /api/v3/users/{id}

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Happy path | `/users/2` | 200, 8 claves | 200 | PASS |
| Legacy status 0 | `/users/7` | `status: 2` | 2 | PASS |
| Otra notaría | n1→4, n3→2 | 404 | 404 | PASS |
| Inexistente / `0` / `-1` | | 404 | 404 | PASS |
| Formato | `abc`, `1.5`, `2abc`, `99999999999999999999`, `1 OR 1=1` | 400 | 400 | PASS |

### Endpoint: POST /api/v3/users

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Happy path | body completo, access desordenado con duplicados, nombres con espacios | 201, trim, `["ESC","PAG"]` | 201 | PASS |
| Mínimo | solo requeridos | 201, `maternalLastName: ""`, `status: 1` | 201 | PASS |
| Login del nuevo usuario | credenciales creadas | 200 | 200 | PASS |
| Duplicados | exacto / case / legacy / cross-notaría | 409 | 409 | PASS |
| Validaciones | ~45 combinaciones de username, password, nombres, access, status | 400 con `errors.<campo>` | 400 | PASS |
| Body vacío / `{}` / JSON inválido / `[]` | | 400 con todos los requeridos | 400 | PASS |
| Sin auth | | 401 | 401 | PASS |

### Endpoint: PUT /api/v3/users/{id}

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Parcial | solo `firstName` | 200, resto intacto | 200 | PASS |
| Limpiar materno | `null` / `""` | `''` | `''` | PASS |
| Idempotente | mismos valores | 200 | 200 | PASS |
| Desactivar / reactivar otro | `status: 2` / `1` | 200 | 200 | PASS |
| Auto-desactivación | `status: 2` a sí mismo | 403, sin escritura | 403, fila intacta | PASS |
| Username | nuevo válido, mismo, solo mayúsculas, ocupado, inválido | 200 / 200 / 200 / 409 / 400 | igual | PASS |
| Legacy | usuario 6 y usuario 7 | 200 y `id_estatus` crudo preservado | igual | PASS |
| `password` | solo / con otros campos | 400 sin escribir | 400 | PASS |
| Body | `{}`, campo desconocido, `access: []`, `status` `0/3/"2"`, nombres vacíos/151 | 400 | 400 | PASS |
| IDs | `abc`, `0`, `-5`, `99999` | 400 / 404 | igual | PASS |
| Sin auth | | 401 | 401 | PASS |

### Otros métodos
`DELETE`, `PATCH` y `POST` sobre `/users/{id}` → 405 (como documenta OpenAPI).

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Acceso a datos propios (n1, n2, n3) | PASS | Cada token ve únicamente usuarios de su notaría |
| GET cross-notaría | PASS | 404 en ambos sentidos |
| PUT cross-notaría (incluso `status:2` y datos válidos) | PASS | 404; BD sin cambios (`nombre`, `id_estatus` verificados) |
| POST con `notaryId` ajeno en el body | PASS | Ignorado; se usa la notaría del token |
| Unicidad de username entre notarías | PASS | Es global por diseño (el login no recibe notaría): 409 |
| Auto-desactivación en otra notaría (usuario 4 con token n3) | PASS | 403 |

## Rendimiento (medido dentro del contenedor, 40 repeticiones)

| Endpoint | Media | Mediana | p95 |
|----------|-------|---------|-----|
| `GET /health` (línea base del entorno) | 72 ms | 48 ms | 86 ms |
| `GET /acts` (otro módulo) | 133 ms | 104 ms | 224 ms |
| `GET /payment-clients` (otro módulo) | 118 ms | 109 ms | 163 ms |
| `GET /users` | 147 ms | 130 ms | 214 ms |
| `GET /users/{id}` | 109 ms | 85 ms | 180 ms |
| `PUT /users/{id}` | 134 ms | — | 222 ms |
| `POST /users` (n=10) | 248 ms | — | 347 ms |

Los endpoints de Users están en línea con los de otros módulos; el entorno local (Docker Desktop sobre Windows con bind mount) aporta ~50–70 ms incluso en `/health`. `POST` incluye bcrypt cost 10 (≈84 ms medido por separado), exigido por el PO.

## Observaciones (no bloqueantes)

1. **Defecto preexistente del Core — 500 con body JSON escalar.** `POST/PUT` con un body JSON que no es objeto ni arreglo (`"x"`, `5`, `true`) devuelve **500** con el mensaje del `TypeError` de `Request::__construct()` (con `APP_DEBUG=true` expone la ruta del archivo). Es independiente de este feature: ocurre idéntico en `POST /acts` y `POST /payment-clients`, y el código (`Core/Http/Request::fromGlobals`) data del commit inicial. Se registra aquí y **no bloquea** este PR; se recomienda un ticket aparte para que `fromGlobals` solo acepte `array` (de lo contrario `[]`) y responda 400. Evidencia: `test_evidence/06_scalar_body_preexisting_core_issue.log`.
2. **`access: {"0":"ESC"}` se acepta** como `["ESC"]`: `json_decode(..., true)` convierte el objeto con clave `"0"` en lista, por lo que es indistinguible de un arreglo. Inocuo (el resultado es válido y canónico); no se considera bug.
3. **Carrera entre variantes de mayúsculas** (`case.race` / `CASE.RACE` / `Case.Race` / `case.RACE` en paralelo): las 4 filas se crearon, porque el `UNIQUE` es binario y la verificación `LOWER()` ocurre antes del INSERT. Es el riesgo explícitamente aceptado en ADR-003 (probabilidad muy baja). Las carreras de username **exacto** sí se resuelven con 409.
4. **Latencia de `POST` > 200 ms en local** por bcrypt cost 10 (requerido) + sobrecarga del entorno. En línea con el criterio NF solo si producción es más rápida que el Docker local; conviene revisarlo en el servidor real.
5. **Mensajes 401 del `AuthMiddleware` en español** (`Token no proporcionado`, `Token inválido o expirado`), contrario a la convención "errores en inglés". Preexistente y aceptado en QA anteriores (specs 02 y 03); no es parte de este feature.

También se confirmó el comportamiento documentado en el plan: un usuario desactivado **no puede hacer login ni refresh** (401), pero un access token ya emitido sigue vigente hasta expirar (≤ `JWT_EXPIRATION`); la revocación inmediata está fuera de alcance.

## Conclusión
El feature cumple los 11 escenarios del PO, todas las validaciones, los criterios de rechazo y los criterios técnicos del arquitecto. No se encontraron bugs en el módulo `User`: contraseñas nunca expuestas, aislamiento multi-tenant correcto, regla de auto-desactivación y manejo de usuarios legacy (`FANNY RECEPCION`, `id_estatus = 0`) funcionando como se diseñó, carreras resueltas con 409 y migración reversible. Sin regresiones (551 tests + smoke de los demás módulos). Las observaciones son preexistentes o riesgos ya aceptados.

**Recomendación: APROBAR y mergear PR #11 a `develop`.**

### Recordatorio para el deploy
- Antes de aplicar la migración en producción ejecutar el **PRE-CHECK A** (duplicados exactos de `usuario`); si devuelve filas, detenerse y escalar al PO. PRE-CHECK B (duplicados por mayúsculas) es informativo.

## Fecha de Validación
2026-11-11
