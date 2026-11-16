# Contexto Técnico

## Entidades Involucradas
- Tabla `usuarios_notaria`: `id_usuario`, `id_notaria`, `password_hash` (bcrypt), `contrasenia` (MD5 legacy)
- Entidad `User` (módulo User): sin propiedades de contraseña, no cambia (ADR-006)

## Endpoints Sugeridos
- `PUT /api/v3/me/password` — usuario y notaría tomados del token
- `PUT /api/v3/users/{id}/password` — `{id}` de la ruta, notaría tomada del token

Request (ambos): `{"password": "string"}`
Response (ambos): `204 No Content`

| Código | Caso |
|---|---|
| 204 | Contraseña actualizada |
| 400 | Validación (`password` ausente, tipo, longitud, NUL) |
| 401 | Sin token o token inválido |
| 404 | Usuario inexistente o de otra notaría (`user_not_found`) |

## Dependencias
- `User` module: `UserService` (ya hashea con bcrypt en create), `UserRepository`, `UserFieldRules::password()`, `UserError::UserNotFound`
- `Auth` module: el middleware JWT que provee `userId` y `notaryId`; `AuthService::verifyPassword` (solo para validar que el login respeta el nuevo estado, sin modificarlo)
- Endpoint `/me` (spec 14) como referencia de cómo tomar identidad del token

## Impacto en Sistema Existente
- Nuevo DTO de request (por ejemplo `ChangePasswordRequest`) reutilizando `UserFieldRules::password()`
- Nuevo método en `UserService` y nuevo método de escritura en `UserRepository`
- Nuevas rutas y acciones de controlador
- Sin cambios en `UpdateUserRequest` (sigue rechazando `password`), en login ni en JWT
- OpenAPI (`docs/openapi`) debe documentar ambos endpoints

## Consideraciones de Datos
- Sin migraciones: las columnas `password_hash` y `contrasenia` ya existen
- El `UPDATE` debe ser: `SET password_hash = :hash WHERE id_usuario = :id AND id_notaria = :notaryId`
- `contrasenia` (MD5) no se toca: la v1 convive con la v3 unos días. El login de la v3 prefiere `password_hash`, así que la contraseña anterior deja de servir en la v3, pero la v1 la sigue aceptando hasta que se retire
- Verificar existencia del usuario en la notaría por separado, sin depender de `rowCount()`

## Notas para Implementación
- No leer nunca `password_hash` ni `contrasenia` en el módulo User; el repositorio solo escribe
- Excepciones por `UserError` existente, mapeadas a HTTP como el resto del módulo
- `/me/password` puede reutilizar el mismo método de servicio, pasando el `userId` del token
- Para QA: probar con un usuario legacy (solo MD5) y con uno ya migrado (con `password_hash`)
- Decisiones de seguridad aceptadas por el PO (sin contraseña actual, sin invalidar JWT, sin bitácora, sin roles) están en `story.md`, sección "Riesgos aceptados"
