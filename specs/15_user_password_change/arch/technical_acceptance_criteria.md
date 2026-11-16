# Criterios de Aceptación Técnicos

## Controller Layer
- [ ] `PUT /api/v3/users/{id}/password` → `UserController::changePassword`, middleware `auth`
- [ ] `PUT /api/v3/me/password` → `UserController::changeOwnPassword`, middleware `auth`
- [ ] `changeOwnPassword` usa solo `$request->getUserId()`; ignora ruta, query y body para decidir el usuario
- [ ] `changePassword`: `{id}` no entero o `<= 0` → `404` (no usa `parseId()`, ADR-003)
- [ ] Ambas pasan `$request->getNotaryId()` al servicio; nunca un valor del request
- [ ] Orden: id inválido (404) → validación de body (400) → servicio (404)
- [ ] `ChangePasswordRequest` inválido → `Response::badRequest('Validation failed', errors)`
- [ ] Éxito → `Response::noContent()` (`204`, sin body)
- [ ] `UserException` se traduce con el `errorResponse()` existente; sin ramas nuevas
- [ ] Ninguna respuesta incluye la contraseña, el hash ni el MD5

## Service Layer
- [ ] `changePassword(int $id, int $notaryId, string $password): void`
- [ ] Llama a `getById($id, $notaryId)` antes de escribir; lanza `UserException(UserNotFound)` si no existe
- [ ] Hash con `PASSWORD_BCRYPT` y `BCRYPT_COST` (10) mediante `hashPassword()` privado, reutilizado por `create()`
- [ ] No revisa `id_estatus` del destino (inactivo permitido)
- [ ] No pide ni valida contraseña actual
- [ ] No invalida tokens ni escribe bitácora
- [ ] Cambiar a la misma contraseña es válido

## Repository Layer
- [ ] `updatePassword(int $id, int $notaryId, string $passwordHash): void`
- [ ] SQL exacto: `UPDATE usuarios_notaria SET password_hash = :hash WHERE id_usuario = :id AND id_notaria = :notaryId`
- [ ] Prepared statement con parámetros nombrados
- [ ] No modifica `contrasenia`, `ultimo_acceso`, `user_access`, `id_estatus` ni `id_notaria` (ADR-002)
- [ ] No usa `rowCount()` (ADR-004)
- [ ] No agrega ningún `SELECT` de columnas de contraseña; `COLUMNS` queda igual (ADR-006 de la spec 12)
- [ ] Docblock de la clase actualizado: el hash entra por `create()` y `updatePassword()`

## Entity/DTO
- [ ] `ChangePasswordRequest`: `final`, constructor privado, `public readonly string $password`
- [ ] Ausente → `errors.password = "Password is required"`
- [ ] Presente → errores de `UserFieldRules::password()` (string, sin NUL, mínimo 8 caracteres, máximo 72 bytes)
- [ ] Sin `trim`: los espacios son parte de la contraseña
- [ ] Los mensajes de error nunca incluyen el valor de la contraseña
- [ ] Campos extra en el body se ignoran
- [ ] La entidad `User` no cambia (sigue sin propiedades de contraseña)
- [ ] `UpdateUserRequest` no cambia (sigue rechazando `password`)

## Tests
- [ ] `ChangePasswordRequestTest`:
  - [ ] válido (8 caracteres exactos) y 72 bytes exactos
  - [ ] 7 caracteres → error
  - [ ] 73 bytes → error; 8 caracteres multibyte que superan 72 bytes → error
  - [ ] ausente, `null`, entero, arreglo → error
  - [ ] con byte NUL → error
  - [ ] espacios al inicio y al final se conservan sin `trim`
  - [ ] el mensaje de error no contiene la contraseña
- [ ] `UserServiceTest::changePassword`:
  - [ ] usuario existente: `updatePassword` se llama una vez con `id` y `notaryId` correctos
  - [ ] el hash recibido empieza con `$2y$10$` y `password_verify($plain, $hash)` es `true`
  - [ ] usuario inexistente o de otra notaría: lanza `UserNotFound` y `updatePassword` nunca se llama
  - [ ] usuario inactivo: se permite
  - [ ] `create()` sigue pasando sus tests tras extraer `hashPassword()`
- [ ] Verificación manual con curl y base de datos (checklist en `developer_notes.md`)

## Código
- [ ] PSR-12, `declare(strict_types=1)`
- [ ] Propiedades y retornos tipados
- [ ] Controller solo delega; sin lógica de negocio
- [ ] Sin código duplicado entre las dos acciones (`applyPasswordChange()` compartido)

## Performance
- [ ] Dos queries por la PK (lectura y `UPDATE`), sin N+1
- [ ] Un solo `password_hash()` por petición

## Seguridad
- [ ] JWT requerido en ambas rutas (`401` sin token)
- [ ] Multi-tenant: lectura y escritura filtradas por `notary_id`; usuario de otra notaría → `404`
- [ ] Contraseña, hash y MD5 nunca en respuestas, mensajes de error ni logs
- [ ] Sin límite de intentos ni bitácora (fuera de alcance, riesgos aceptados en `po/story.md`)
- [ ] `PUT/PATCH /users/{id}` con `password` sigue respondiendo `400`
- [ ] Verificado en BD tras un cambio: `password_hash` cambió, `contrasenia` idéntica y el resto de columnas sin cambios
