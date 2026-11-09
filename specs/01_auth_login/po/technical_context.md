# Contexto Técnico

## Entidades Involucradas

### Tabla: `usuarios`
Campos relevantes:
- `id` - ID del usuario (PK)
- `usuario` - Nombre de usuario (para login)
- `contrasenia` - Contraseña en MD5 (legacy)
- `password_hash` - **NUEVO** Contraseña con password_hash (bcrypt)
- `user_access` - Features/permisos separados por coma
- `notaria_id` - FK a la notaría
- `status` - Estado (1 = activo)

### Tabla: `notaria`
Campos relevantes:
- `id` - ID de la notaría (PK)
- `status` - Estado (1 = activo)

## Migración de Base de Datos

Se requiere agregar un nuevo campo a la tabla `usuarios`:

```sql
ALTER TABLE usuarios 
ADD COLUMN password_hash VARCHAR(255) NULL 
AFTER contrasenia;
```

## Endpoints

### POST /api/v3/auth/login

**Request:**
```json
{
  "usuario": "string",
  "contrasenia": "string"
}
```

**Response Success (200):**
```json
{
  "accessToken": "eyJ...",
  "refreshToken": "eyJ...",
  "expiresIn": 7200
}
```

**Response Error (401):**
```json
{
  "error": "Credenciales inválidas"
}
```

### POST /api/v3/auth/refresh

**Request:**
```json
{
  "refreshToken": "eyJ..."
}
```

**Response Success (200):**
```json
{
  "accessToken": "eyJ...",
  "refreshToken": "eyJ...",
  "expiresIn": 7200
}
```

**Response Error (401):**
```json
{
  "error": "Token inválido o expirado"
}
```

## Dependencias

### Paquetes PHP
- `firebase/php-jwt` - Para generación y validación de JWT

### Módulos del Sistema
- Ninguno (este es el módulo base de autenticación)

## Lógica de Autenticación

### Flujo de Validación de Contraseña

```
1. Buscar usuario por campo `usuario`
2. Si no existe → 401
3. Verificar que usuario.status = 1
4. Si inactivo → 401
5. Verificar que notaria.status = 1
6. Si inactiva → 401
7. Validar contraseña:
   a. Si password_hash NO está vacío:
      - Usar password_verify($input, $password_hash)
   b. Si password_hash está vacío:
      - Comparar md5($input) === $contrasenia
      - Si exitoso: migrar contraseña a password_hash
8. Si contraseña inválida → 401
9. Generar tokens y retornar
```

### Migración de Contraseña (transparente)

Cuando un usuario con contraseña MD5 hace login exitoso:
```php
$newHash = password_hash($inputPassword, PASSWORD_DEFAULT);
// UPDATE usuarios SET password_hash = $newHash WHERE id = $userId
```

## Configuración de JWT

### Variables de Entorno Sugeridas
```
JWT_SECRET=<clave-secreta-larga-y-segura>
JWT_ACCESS_EXPIRY=7200      # 2 horas en segundos
JWT_REFRESH_EXPIRY=86400    # 24 horas en segundos
JWT_ISSUER=security@devrob
JWT_AUDIENCE=apps@devrob
```

## Impacto en Sistema Existente

- **Base de datos**: Nueva columna `password_hash` en `usuarios`
- **Código legacy**: Ninguno, los sistemas v1/v2 seguirán funcionando con MD5
- **Nuevos usuarios**: Deberán crearse con `password_hash` (futuro feature de User module)

## Consideraciones de Seguridad

1. **JWT Secret**: Debe ser una cadena larga y aleatoria, almacenada en variables de entorno
2. **No loguear contraseñas**: Ni en input ni después de hash
3. **Algoritmo**: Usar HS256 como mínimo para firma JWT
4. **Password Hash**: Usar `PASSWORD_DEFAULT` que actualmente es bcrypt

## Estructura de Archivos Sugerida

```
src/
└── Module/
    └── Auth/
        ├── Controller/
        │   └── AuthController.php
        ├── Service/
        │   ├── AuthService.php
        │   └── JwtService.php
        ├── Repository/
        │   └── UserAuthRepository.php
        ├── DTO/
        │   ├── LoginRequest.php
        │   ├── LoginResponse.php
        │   ├── RefreshRequest.php
        │   └── TokenPayload.php
        └── Exception/
            └── AuthenticationException.php
```

## Notas para Implementación

1. **Parseo de Features**: El campo `user_access` contiene valores separados por coma. Ejemplo: `"deeds,acts,payments"` → `["deeds", "acts", "payments"]`

2. **Trim de espacios**: Al parsear features, hacer trim de cada valor

3. **Features vacíos**: Si `user_access` es NULL o vacío, el claim `features` debe ser un array vacío `[]`

4. **Refresh Token**: Incluir un claim `type: "refresh"` para diferenciarlo del accessToken

5. **Middleware de Auth**: Este feature sienta las bases para el middleware que validará tokens en endpoints protegidos (siguiente feature)
