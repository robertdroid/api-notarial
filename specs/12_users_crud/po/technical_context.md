# Contexto Técnico

## Entidades Involucradas

### Tabla: `usuarios_notaria`
| Columna | Tipo | API Field | Notas |
|---------|------|-----------|-------|
| `id_usuario` | int PK AUTO_INCREMENT | `id` | |
| `id_notaria` | int | - | Del token, no se expone |
| `nombre` | varchar(150) | `firstName` | |
| `apellido_paterno` | varchar(150) | `paternalLastName` | |
| `apellido_materno` | varchar(150) | `maternalLastName` | |
| `usuario` | varchar(20) | `username` | Único global |
| `contrasenia` | mediumtext | - | Legacy MD5, NULL en nuevos |
| `password_hash` | varchar(255) | - | bcrypt |
| `ultimo_acceso` | datetime | `lastAccess` | Solo lectura |
| `id_estatus` | int | `status` | 1=activo, 2=inactivo |
| `user_access` | varchar(50) | `access` | CSV → array |

### Mapeo access
- BD: `"ESC,PAG"` (string CSV)
- API: `["ESC", "PAG"]` (array de strings)

## Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v3/users` | Listar usuarios de la notaría |
| GET | `/api/v3/users/{id}` | Detalle de usuario |
| POST | `/api/v3/users` | Crear usuario |
| PUT | `/api/v3/users/{id}` | Modificar usuario |

### Request/Response Examples

#### POST /api/v3/users
```json
{
  "username": "maria.lopez",
  "password": "SecurePass123",
  "firstName": "María",
  "paternalLastName": "López",
  "maternalLastName": "García",
  "access": ["ESC", "PAG"]
}
```

Response 201:
```json
{
  "id": 15,
  "username": "maria.lopez",
  "firstName": "María",
  "paternalLastName": "López",
  "maternalLastName": "García",
  "access": ["ESC", "PAG"],
  "status": 1,
  "lastAccess": null
}
```

#### PUT /api/v3/users/{id}
```json
{
  "firstName": "María Elena",
  "status": 2
}
```

## Dependencias
- `AuthMiddleware` para autenticación
- Módulo `Auth` ya tiene `UserAuthRepository` (reutilizar queries similares)

## Impacto en Sistema Existente
- Crear módulo `User` en `src/Module/User/`
- Agregar rutas en `src/Config/routes.php`
- El `UserAuthRepository` existente NO se modifica; el nuevo `UserRepository` es independiente

## Migración Necesaria
Crear índice UNIQUE en `usuario` para garantizar unicidad:
```sql
-- Verificar duplicados primero
SELECT usuario, COUNT(*) c FROM usuarios_notaria GROUP BY usuario HAVING c > 1;

-- Si no hay duplicados:
CREATE UNIQUE INDEX idx_usuarios_notaria_usuario_unique ON usuarios_notaria(usuario);
```

## Consideraciones de Datos
- Usuarios existentes con `id_estatus = 0` se tratan como inactivos
- El campo `contrasenia` (MD5) se ignora en usuarios nuevos; solo se usa `password_hash`
- `ultimo_acceso` se actualiza en el módulo Auth al hacer login, no en este módulo

## Estructura del Módulo
```
src/Module/User/
├── Controller/
│   └── UserController.php
├── Service/
│   └── UserService.php
├── Repository/
│   └── UserRepository.php
├── Entity/
│   └── User.php
└── DTO/
    ├── CreateUserRequest.php
    ├── UpdateUserRequest.php
    └── UserResponse.php
```

## Notas para Implementación
- Seguir patrón de módulos existentes (ActController, SubdivisionController)
- bcrypt con cost 10: `password_hash($password, PASSWORD_BCRYPT, ['cost' => 10])`
- Validar códigos de access contra constantes o enum
- En listado, mapear `id_estatus = 0` a `status = 2` en la respuesta
- Actualizar `docs/openapi.yaml` con los nuevos endpoints
