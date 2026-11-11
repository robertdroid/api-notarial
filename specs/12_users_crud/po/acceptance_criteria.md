# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar usuarios de la notaría
**Dado** un usuario autenticado de la notaría N  
**Cuando** hace `GET /api/v3/users`  
**Entonces** recibe lista de usuarios de la notaría N, excluyendo el campo password, ordenados por apellido paterno

### Escenario 2: Listar solo usuarios activos
**Dado** un usuario autenticado  
**Cuando** hace `GET /api/v3/users?status=1`  
**Entonces** recibe solo usuarios con `id_estatus = 1`

### Escenario 3: Ver detalle de usuario
**Dado** un usuario autenticado de la notaría N  
**Cuando** hace `GET /api/v3/users/{id}` de un usuario de la notaría N  
**Entonces** recibe los datos del usuario sin campo password

### Escenario 4: Usuario de otra notaría
**Dado** un usuario autenticado de la notaría N  
**Cuando** intenta `GET /api/v3/users/{id}` de un usuario de la notaría M  
**Entonces** recibe 404 Not Found

### Escenario 5: Crear usuario
**Dado** un usuario autenticado de la notaría N  
**Cuando** hace `POST /api/v3/users` con datos válidos  
**Entonces** se crea el usuario con `id_notaria = N`, `password_hash` en bcrypt, `contrasenia = NULL`, y recibe 201 con el usuario creado

### Escenario 6: Username duplicado
**Dado** un usuario autenticado  
**Cuando** hace `POST /api/v3/users` con un `username` que ya existe (en cualquier notaría)  
**Entonces** recibe 409 Conflict con mensaje descriptivo

### Escenario 7: Modificar usuario
**Dado** un usuario autenticado de la notaría N  
**Cuando** hace `PUT /api/v3/users/{id}` con datos válidos  
**Entonces** se actualizan los campos enviados y recibe 200

### Escenario 8: Desactivar usuario (no a sí mismo)
**Dado** un usuario autenticado con id=5  
**Cuando** hace `PUT /api/v3/users/10` con `status: 2`  
**Entonces** el usuario 10 queda desactivado

### Escenario 9: Intentar desactivarse a sí mismo
**Dado** un usuario autenticado con id=5  
**Cuando** hace `PUT /api/v3/users/5` con `status: 2`  
**Entonces** recibe 403 Forbidden con mensaje "Cannot deactivate your own account"

### Escenario 10: Access inválido
**Dado** un usuario autenticado  
**Cuando** envía `access: ["XYZ"]` (código no permitido)  
**Entonces** recibe 400 Bad Request

### Escenario 11: Access vacío
**Dado** un usuario autenticado  
**Cuando** envía `access: []` (sin permisos)  
**Entonces** recibe 400 Bad Request indicando que se requiere al menos un permiso

## No Funcionales
- Respuestas en < 200ms para operaciones CRUD
- Password nunca se expone en respuestas ni logs
- Multi-tenancy: todas las queries filtran por `notary_id` del token

## Validaciones

### username
- Requerido en POST
- 3-20 caracteres
- Solo alfanuméricos, `.` y `_`
- Único globalmente (no solo en la notaría)

### password
- Requerido en POST
- Mínimo 8 caracteres
- Se hashea con bcrypt (cost 10)

### firstName (nombre)
- Requerido
- Máximo 150 caracteres

### paternalLastName (apellido_paterno)
- Requerido
- Máximo 150 caracteres

### maternalLastName (apellido_materno)
- Opcional
- Máximo 150 caracteres

### access
- Requerido
- Array de strings
- Valores permitidos: `ESC`, `PAG`
- Mínimo un valor

### status
- Opcional en PUT
- Valores: 1 (activo), 2 (inactivo)
- Default: 1 en creación

## Casos Edge
- Usuario con `id_estatus = 0` en BD se trata como inactivo (equivalente a 2) al listar
- Username es case-sensitive (collation `utf8mb3_bin`)
- `contrasenia` (MD5 legacy) queda NULL en usuarios nuevos

## Criterios de Rechazo
- Exposición del campo password/password_hash en cualquier respuesta
- Permitir acceso cross-notaría
- Permitir que un usuario se desactive a sí mismo
- Aceptar códigos de access no válidos
