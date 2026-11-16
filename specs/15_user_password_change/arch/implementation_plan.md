# Plan de Implementación: Cambio de Contraseña de Usuario

## Resumen
Dos endpoints `PUT` que establecen una contraseña nueva (sin contraseña actual) y responden `204`. Ambos viven en el módulo `User` y comparten un único método de servicio: `UserService::changePassword(id, notaryId, password)`. El repositorio solo **escribe** `password_hash`; no lee ninguna columna de contraseña (ADR-006 de la spec 12 sigue vigente) y no toca `contrasenia` (la v1 convive unos días). Sin migraciones, sin errores nuevos, sin transacciones.

## Arquitectura Propuesta
```
PUT /api/v3/me/password          PUT /api/v3/users/{id}/password
  id = token.userId                id = ruta (inválido → 404)
        \                              /
         UserController::applyPasswordChange(id, Request)
           ChangePasswordRequest::fromArray(body)   → 400 si inválido
           UserService::changePassword(id, notaryId, password)
             getById(id, notaryId)                  → UserNotFound → 404
             hashPassword(password)                 bcrypt, cost 10
             UserRepository::updatePassword(id, notaryId, hash)
               UPDATE usuarios_notaria SET password_hash = :hash
               WHERE id_usuario = :id AND id_notaria = :notaryId
           Response::noContent()                    → 204
```

## Módulos Afectados
- [x] `Module/User/` — DTO, Service, Repository, Controller
- [x] `Config/routes.php` — 2 rutas
- [ ] `Module/Me/` — sin cambios (es de solo lectura)
- [ ] `Module/Auth/` — sin cambios (el login ya prefiere `password_hash`)
- [x] `docs/openapi.yaml`, `README.md` — documentación

## Tareas de Implementación

### Fase 1: DTO y Repository
Estimación: 1 h

#### 1.1 `ChangePasswordRequest`
- **Archivo(s):** `src/Module/User/DTO/ChangePasswordRequest.php`
- **Tipo:** Crear
- **Descripción:** `final`, constructor privado con `public readonly string $password`, `fromArray()`, `isValid()`, `getErrors()`. Patrón de `CreateUserRequest`: ausente → `Password is required`; presente → `UserFieldRules::password()`. Sin `trim`. Campos extra se ignoran.
- **Dependencias:** `UserFieldRules::password()` (existente)

#### 1.2 `UserRepository::updatePassword()`
- **Archivo(s):** `src/Module/User/Repository/UserRepository.php`
- **Tipo:** Modificar
- **Descripción:** Nuevo método de escritura. Sin `rowCount()` (ver ADR-004). Actualizar el docblock de la clase: ahora el hash entra por `create()` y `updatePassword()`; sigue sin leer contraseñas.
- **Dependencias:** ninguna

### Fase 2: Service
Estimación: 0.5 h

#### 2.1 `UserService::changePassword()`
- **Archivo(s):** `src/Module/User/Service/UserService.php`
- **Tipo:** Modificar
- **Descripción:** Verifica existencia con `getById()`, hashea y llama al repositorio. Extraer `hashPassword()` privado y que `create()` lo reutilice, para que el costo bcrypt viva en un solo lugar.
- **Dependencias:** 1.2

### Fase 3: Controller y rutas
Estimación: 0.5 h

#### 3.1 Acciones del controlador
- **Archivo(s):** `src/Module/User/Controller/UserController.php`
- **Tipo:** Modificar
- **Descripción:** `changePassword()` (id de ruta; no entero o `<= 0` → `404`) y `changeOwnPassword()` (id del token). Ambas delegan en `applyPasswordChange()` privado. Orden: id inválido (404) → body (400) → servicio (404).
- **Dependencias:** 1.1, 2.1

#### 3.2 Rutas
- **Archivo(s):** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Dos rutas `PUT` con middleware `auth`, junto a los bloques Users y Me.
- **Dependencias:** 3.1

### Fase 4: Tests y documentación
Estimación: 2 h

#### 4.1 Tests unitarios
- **Archivo(s):** `tests/Module/User/DTO/ChangePasswordRequestTest.php` (crear), `tests/Module/User/Service/UserServiceTest.php` (modificar)
- **Tipo:** Crear / Modificar
- **Descripción:** Ver `technical_acceptance_criteria.md`.
- **Dependencias:** 1.1, 2.1

#### 4.2 Documentación
- **Archivo(s):** `docs/openapi.yaml`, `README.md`
- **Tipo:** Modificar
- **Descripción:** Dos paths nuevos (`204`, `400`, `401`, `404`; el schema del body es `{password}`), filas en la tabla de endpoints y entrada de changelog.
- **Dependencias:** 3.2

## Orden de Implementación
1. 1.1 DTO y 1.2 Repository (independientes)
2. 2.1 Service
3. 3.1 Controller y 3.2 rutas
4. 4.1 Tests
5. 4.2 Documentación
6. Verificación manual con `curl` (ver `developer_notes.md`)

## Riesgos y Mitigaciones
| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| La v1 sigue aceptando la contraseña anterior (`contrasenia` intacta) y rechaza la nueva | Alta (certeza mientras convivan) | Medio | Aceptado por el PO. Pendiente al retirar la v1: limpiar `contrasenia` en bloque |
| `/users/{id}/password` capturada por la ruta `/users/{id}` | Baja | Alto | El router ya distingue `/payment-procedures/{id}` de `/payment-procedures/{id}/taxes`; verificar con curl |
| Un `password` mayor a 72 bytes se trunca en silencio en bcrypt | Baja | Alto | `UserFieldRules::password()` ya lo rechaza (`strlen`); test de borde con 72 y 73 bytes |
| El body o la contraseña aparecen en logs | Baja | Alto | No hay logging de requests en `src/Core`; mantener mensajes de error sin el valor |
| JSON escalar en el body (`"abc"`) provoca `500` por `Request::getBody(): array` | Media | Bajo | Problema previo de todos los endpoints, fuera de alcance; QA lo verifica y lo reporta aparte |
| Token de un usuario eliminado o de otra notaría en `/me/password` | Baja | Bajo | `getById(id, notaryId)` devuelve `404` |

## Decisiones de Arquitectura

### ADR-001: Ambos endpoints en el módulo `User`, con un solo método de servicio
- **Contexto:** El PO pide `/me/password` y `/users/{id}/password`. El módulo `Me` es de solo lectura (spec 14) y la spec 12 (ADR-001) ya anticipó un método dedicado de `UserRepository`.
- **Decisión:** Dos acciones en `UserController` que difieren solo en el origen del id y delegan en `UserService::changePassword()`. El módulo `Auth` no se modifica.
- **Consecuencias:** Una sola ruta de código para ambos casos. `Me` no gana lógica de escritura. El cambio propio y el reset se comportan igual por diseño.

### ADR-002: `contrasenia` (MD5) no se modifica
- **Contexto:** El PO decidió (revisando su decisión inicial de limpiarla) que la v1 convive con la v3 unos días, y la v1 lee `contrasenia`.
- **Decisión:** `updatePassword()` solo escribe `password_hash`. El login de la v3 ya prefiere `password_hash` cuando existe, así que la contraseña anterior deja de funcionar en la v3 sin tocar `AuthService`.
- **Consecuencias:** Divergencia temporal entre v1 y v3: la v1 acepta la contraseña vieja y rechaza la nueva. Al retirar la v1 hay que limpiar `contrasenia` en bloque (tarea pendiente fuera de esta spec). Es el mismo comportamiento que ya tiene `AuthService::migratePassword()`, que escribe `password_hash` sin limpiar el MD5.

### ADR-003: Id inválido en `/users/{id}/password` responde `404`
- **Contexto:** `UserController::parseId()` responde `400` ("Invalid ID format") para ids no numéricos y `404` para `<= 0`. El PO pide `404` en ambos casos.
- **Decisión (confirmada con el PO):** `changePassword()` valida el id en línea (`filter_var` + `<= 0` → `Response::notFound`) y no usa `parseId()`. `show` y `update` no cambian.
- **Consecuencias:** Inconsistencia menor y conocida entre este endpoint y `show`/`update` para ids no numéricos. Si se quiere unificar, es un cambio aparte que alteraría el contrato de los endpoints existentes.

### ADR-004: Existencia por `findByIdAndNotary`, no por `rowCount()`
- **Contexto:** MySQL devuelve 0 filas afectadas cuando el `UPDATE` no cambia valores, y `update()` ya evita `rowCount()` por eso (comentario en el repositorio).
- **Decisión:** `changePassword()` llama a `getById(id, notaryId)` antes de escribir, igual que `update()`. Si no existe en la notaría → `UserNotFound` → `404`.
- **Consecuencias:** Una query extra por la PK. Filtro por `notary_id` aplicado dos veces (lectura y escritura). El `UPDATE` también filtra por `id_notaria`, de modo que un id de otra notaría nunca se escribe aunque falle la verificación previa.

### ADR-005: Sin errores nuevos ni transacción
- **Contexto:** Los únicos fallos de dominio son usuario inexistente (ya existe `UserError::UserNotFound`) y validación (se resuelve en el DTO). La tabla es MyISAM (ADR-008 de la spec 12).
- **Decisión:** No se agrega nada a `UserError` ni a `errorResponse()`. Una sola sentencia `UPDATE`, sin `TransactionManager`.
- **Consecuencias:** `UserErrorTest` no cambia. Cualquier excepción de PDO se propaga al manejador global (`500`).
