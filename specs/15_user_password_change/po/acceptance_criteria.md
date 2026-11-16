# Criterios de Aceptación

## Funcionales

### Escenario 1: Cambio de contraseña propia
**Dado** un usuario autenticado
**Cuando** envía `PUT /api/v3/me/password` con `{"password": "NuevaClave123"}`
**Entonces** responde `204` sin body, `password_hash` queda con un bcrypt de la nueva contraseña y `contrasenia` no se modifica

### Escenario 2: Login con la contraseña nueva
**Dado** un usuario que cambió su contraseña
**Cuando** hace login con la contraseña nueva
**Entonces** el login es exitoso

### Escenario 3: La contraseña anterior deja de funcionar
**Dado** un usuario legacy (solo `contrasenia` MD5, sin `password_hash`) que cambió su contraseña
**Cuando** hace login en la v3 con la contraseña anterior
**Entonces** el login falla con `401` (el login prefiere `password_hash`; la v1 sigue validando contra `contrasenia`)

### Escenario 4: Reset de la contraseña de otro usuario de la notaría
**Dado** un usuario autenticado y otro usuario de la misma notaría con id `X`
**Cuando** envía `PUT /api/v3/users/X/password` con `{"password": "NuevaClave123"}`
**Entonces** responde `204` y el usuario `X` solo puede entrar con la contraseña nueva

### Escenario 5: Reset de la contraseña propia por `/users/{id}/password`
**Dado** un usuario autenticado con id `Y`
**Cuando** envía `PUT /api/v3/users/Y/password` con una contraseña válida
**Entonces** responde `204` y se comporta igual que el Escenario 1

### Escenario 6: Usuario de otra notaría
**Dado** un usuario autenticado de la notaría A y un usuario con id `Z` de la notaría B
**Cuando** envía `PUT /api/v3/users/Z/password`
**Entonces** responde `404` y la contraseña de `Z` no cambia

### Escenario 7: Usuario inexistente
**Dado** un usuario autenticado
**Cuando** envía `PUT /api/v3/users/999999/password`
**Entonces** responde `404` con el error `user_not_found`

### Escenario 8: Sin autenticación
**Dado** una petición sin token o con token inválido
**Cuando** llama a cualquiera de los dos endpoints
**Entonces** responde `401`

### Escenario 9: Los endpoints de edición siguen rechazando `password`
**Dado** un usuario autenticado
**Cuando** envía `password` en `PUT/PATCH /api/v3/users/{id}`
**Entonces** responde `400` con "Password cannot be changed through this endpoint" (sin cambios)

### Escenario 10: Token anterior sigue vigente
**Dado** un JWT emitido antes del cambio de contraseña
**Cuando** se usa después del cambio
**Entonces** sigue siendo aceptado hasta su expiración natural

## No Funcionales
- Hash con bcrypt, mismo costo que `UserService` en la creación de usuarios (`BCRYPT_COST`)
- La contraseña nunca aparece en respuestas, mensajes de error ni logs
- Queries filtradas siempre por `notary_id` y `id_usuario`
- Campos de la API en inglés y `camelCase`
- Contraseña, hash y MD5 nunca se leen ni se devuelven; el repositorio solo escribe

## Validaciones
- `password` es obligatorio y debe ser string
- Mínimo 8 caracteres (`mb_strlen`)
- Máximo 72 bytes (`strlen`, límite de bcrypt)
- No puede contener bytes NUL
- No se hace `trim`: los espacios son caracteres válidos
- Body vacío, JSON inválido o sin `password` → `400`
- `{id}` de ruta no numérico o menor que 1 → `404`
- Campos adicionales en el body se ignoran

## Casos Edge
- Contraseña de exactamente 8 caracteres y de exactamente 72 bytes: válidas
- Contraseña de 8 caracteres multibyte que supera 72 bytes: `400`
- Usuario inactivo (`id_estatus = 2`) como destino del reset: **se permite** (supuesto a confirmar en Arch; permite reactivar el acceso antes de activar al usuario)
- Cambiar a la misma contraseña que ya tiene: permitido, `204`
- Dos cambios consecutivos: gana el último

## Criterios de Rechazo
- Cualquier respuesta que incluya la contraseña, el hash o el MD5
- Cambiar la contraseña de un usuario de otra notaría
- Modificar `contrasenia` (debe quedar intacta mientras la v1 conviva)
- Modificar otros campos del usuario (`ultimo_acceso`, `user_access`, `id_estatus`, `id_notaria`, etc.)
- Pedir o validar la contraseña actual
