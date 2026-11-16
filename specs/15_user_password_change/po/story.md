# Cambio de Contraseña de Usuario

## Historia de Usuario

**Como** usuario autenticado de una notaría
**Quiero** cambiar mi propia contraseña, o establecer una nueva contraseña a otro usuario de mi notaría
**Para** poder recuperar el acceso o rotar credenciales sin intervención directa en la base de datos

## Contexto
`PUT/PATCH /api/v3/users/{id}` rechaza intencionalmente el campo `password` (`UpdateUserRequest`), y hoy la contraseña solo se escribe al crear el usuario. No existe forma de cambiarla desde la API.

Decisiones de producto:
- No existen roles de administrador (solo códigos de acceso `ESC` y `PAG`). Cualquier usuario autenticado de la notaría puede establecer la contraseña de cualquier usuario de **su misma notaría**, igual que hoy puede gestionar usuarios (spec 12).
- **No se solicita la contraseña actual.** Solo se recibe la contraseña nueva, tanto en el cambio propio como en el reset por otro usuario.
- Los JWT ya emitidos **no se invalidan**.
- No se registra bitácora.

Estado de contraseñas (legacy): el login valida contra `password_hash` (bcrypt) si existe, y solo si no existe cae a `contrasenia` (MD5). Al cambiar la contraseña se escribe el nuevo bcrypt en `password_hash` y **`contrasenia` no se modifica**: la v1 convive con la v3 unos días y sigue leyendo ese campo. Como el login de la v3 usa `password_hash` cuando existe, la contraseña anterior deja de funcionar en la v3.

## Alcance
### Incluido
- `PUT /api/v3/me/password`: el usuario del token cambia su propia contraseña
- `PUT /api/v3/users/{id}/password`: un usuario autenticado establece la contraseña de otro usuario de su notaría (también puede usarse con su propio id)
- Body: solo la contraseña nueva
- Validación con las reglas existentes (`UserFieldRules::password()`: mínimo 8 caracteres, máximo 72 bytes, sin NUL)
- Respuesta `204 No Content`
- Aislamiento multi-tenant por `notary_id`

### Excluido
- Solicitar o validar contraseña actual
- Roles o permisos nuevos (por ejemplo `ADM`)
- Invalidación de sesiones/JWT vigentes
- Bitácora o auditoría del cambio
- Recuperación de contraseña por correo ("olvidé mi contraseña")
- Límite de intentos o rate limiting
- Cambios en el login o en `PUT/PATCH /users/{id}` (siguen rechazando `password`)

## Riesgos aceptados
- Sin roles, cualquier usuario con acceso a la notaría puede resetear la contraseña de cualquier compañero de la misma notaría.
- Sin contraseña actual, quien tenga un token robado puede cambiar la contraseña y bloquear al dueño de la cuenta.
- Sin invalidación de JWT, un token anterior sigue siendo válido hasta su expiración natural tras el cambio.
- Sin bitácora, no hay rastro de quién cambió la contraseña de quién.
- Mientras la v1 conviva, `contrasenia` (MD5) conserva la contraseña anterior: la v1 sigue aceptando la contraseña vieja y rechazando la nueva, y la v3 hace lo contrario. Limpiar o sincronizar `contrasenia` queda pendiente para cuando se retire la v1.

## Usuarios Afectados
- Todos los usuarios autenticados de cualquier notaría (cualquier código de acceso)
- Frontend: pantallas de perfil ("cambiar mi contraseña") y administración de usuarios ("restablecer contraseña")
