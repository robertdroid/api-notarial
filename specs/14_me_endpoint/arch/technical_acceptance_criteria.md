# Criterios de Aceptación Técnicos: Endpoint Me

## Controller Layer (`MeController`)
- [ ] `GET /api/v3/me` registrado con middleware `auth`
- [ ] Usa solo `$request->getUserId()` y `$request->getNotaryId()`; **no** lee `getRouteParam`, `getQueryParam` ni `getBody`
- [ ] `200` con `MeResponse::fromProfile()`
- [ ] `MeException` mapeada: `UserNotFound` → `404`, `TenantMismatch`/`UserInactive`/`NotaryInactive` → `403`
- [ ] `401` lo resuelve `AuthMiddleware` (sin token, expirado, malformado o refresh token)
- [ ] `POST/PUT/PATCH/DELETE /me` → `405`
- [ ] `/me?id=99` y `/me?userId=99` devuelven los datos del token

## Service Layer (`MeService::getProfile`)
- [ ] Orden: usuario por id → comparar `notaryId` → estado usuario → notaría por id → estado notaría
- [ ] Usuario inexistente → `MeError::UserNotFound`
- [ ] `user->notaryId !== $notaryId` → `MeError::TenantMismatch` **sin leer la notaría**
- [ ] `user->status() !== User::STATUS_ACTIVE` (incluye `0` y `NULL`) → `MeError::UserInactive`
- [ ] Notaría inexistente o `status !== 1` → `MeError::NotaryInactive`
- [ ] Devuelve `MeProfile(user, notary)`; sin lógica HTTP ni formateo
- [ ] Sin transacción (solo lectura)

## Repository Layer
- [ ] `UserRepository::findById(int): ?User` usa `COLUMNS` existentes (sin `contrasenia` ni `password_hash`), placeholder nombrado, `?User`
- [ ] `NotaryRepository::findById(int): ?Notary` sin filtro de estatus; `findByIdActive` no cambia
- [ ] Una consulta por PK cada uno (total 2 queries, sin N+1)
- [ ] PHPDoc de `findById` (user) advierte que no filtra tenant y que el llamador debe verificarlo

## Entity/DTO
- [ ] `User::fullName(): string`: `trim` por parte, omite vacías, un solo espacio entre partes (`"María López"` sin materno)
- [ ] `MeProfile`: VO inmutable `User` + `Notary`
- [ ] `MeResponse::fromProfile()` devuelve exactamente:
  - `user`: `id, username, firstName, paternalLastName, maternalLastName, fullName, access, status, lastAccess`
  - `notary`: `id, name, notaryName, city, address, phone, rfc, hasLogo, status`
- [ ] `user.access` es `[]` si no hay permisos (nunca `null`)
- [ ] `user.lastAccess` es `null` si no hay registro, formato `Y-m-d\TH:i:s`
- [ ] `user.status` normalizado (1/2); `notary.hasLogo` booleano
- [ ] **Ausentes** en el JSON: `password`, `passwordHash`, `contrasenia`, `logo`, `user.notaryId`
- [ ] Entities no se exponen; solo arrays de los Response

## Tests
- [ ] `MeServiceTest`:
  - [ ] éxito → devuelve `MeProfile`
  - [ ] usuario no existe → `UserNotFound`
  - [ ] notaryId distinto → `TenantMismatch` y `NotaryRepository::findById` **never** invocado
  - [ ] usuario `status` 2, 0 → `UserInactive` (y notaría no consultada)
  - [ ] notaría inexistente → `NotaryInactive`
  - [ ] notaría `status` 2 → `NotaryInactive`
- [ ] `MeResponseTest`: forma exacta del JSON, claves prohibidas ausentes, `hasLogo` true/false, `lastAccess` null, `access` `[]`
- [ ] `UserTest`: `fullName` con y sin materno, con espacios extra, con parte vacía intermedia
- [ ] Verificación manual con `curl`: 200, 401, 405, `?id=` ignorado

## Código
- [ ] PSR-12, `declare(strict_types=1)`, `final` en clases nuevas
- [ ] Typed properties, `readonly` en VOs
- [ ] Controller sin lógica de negocio; Service sin conocer `Request`/`Response`
- [ ] Enum de errores con `message()` + `httpStatus()` como `UserError`

## Performance
- [ ] 2 queries por PK (`id_usuario`, `id_notaria`), sin joins ni N+1
- [ ] Sin paginación (recurso único)
- [ ] < 200 ms en condiciones normales

## Seguridad
- [ ] JWT requerido (`auth`)
- [ ] `userId` y `notaryId` solo del token; imposible consultar otro usuario o notaría
- [ ] Verificación explícita de `user->notaryId === token notaryId` (ADR-002)
- [ ] Estado validado en BD en cada llamada, no solo en el JWT
- [ ] Respuesta sin datos sensibles (ver Entity/DTO)
- [ ] Ningún dato se devuelve en las respuestas `403`/`404`

## Documentación
- [ ] `docs/openapi.yaml`: path `/me`, schemas `MeResponse`/`MeUser`, respuestas 200/401/403/404/405
