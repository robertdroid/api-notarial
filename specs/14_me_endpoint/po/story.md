# Endpoint Me (Usuario + Notaría)

## Historia de Usuario

**Como** usuario autenticado de una notaría
**Quiero** consultar en una sola llamada mi información y la de mi notaría
**Para** que el frontend cargue el contexto de sesión (nombre, permisos, encabezado de la notaría) sin combinar varios endpoints

## Contexto
Hoy la información está separada: `GET /users/{id}` devuelve solo el usuario y `GET /notary` solo la notaría. El login devuelve tokens y el JWT solo lleva `userId` y `notaryId`. El frontend necesita ambos datos al iniciar sesión y no existe un endpoint único.

El endpoint toma el id del usuario y el de la notaría **exclusivamente del token**. No recibe parámetros en la ruta ni en el body, por lo que un usuario nunca puede consultar datos de otro usuario u otra notaría.

## Alcance
### Incluido
- `GET /api/v3/me` con los datos del usuario y de su notaría
- Campo `fullName` calculado en backend
- Datos sin información sensible (sin password, hash ni nombre de archivo del logo)
- Validación de estado: usuario o notaría inactivos no obtienen datos

### Excluido
- Edición del perfil o cambio de contraseña (otra feature)
- Descarga del logo (ya existe `GET /api/v3/notary/logo`)
- Cambios en login, JWT o en los endpoints existentes `/users` y `/notary`

## Usuarios Afectados
- Todos los usuarios autenticados de cualquier notaría (cualquier código de acceso)
- Frontend: lo consume al iniciar sesión
