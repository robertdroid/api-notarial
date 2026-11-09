# Criterios de Aceptación

## Funcionales

### Escenario 1: Login exitoso con contraseña MD5 (legacy)
**Dado** un usuario activo con contraseña almacenada solo en MD5 (campo `contrasenia`)
**Y** la notaría del usuario está activa
**Cuando** envía credenciales correctas al endpoint de login
**Entonces** recibe un accessToken y refreshToken válidos
**Y** su contraseña se migra automáticamente al nuevo campo hash
**Y** el response tiene status 200

### Escenario 2: Login exitoso con contraseña hasheada (nuevo)
**Dado** un usuario activo con contraseña en el nuevo campo hash
**Y** la notaría del usuario está activa
**Cuando** envía credenciales correctas al endpoint de login
**Entonces** recibe un accessToken y refreshToken válidos
**Y** el response tiene status 200

### Escenario 3: Login fallido - credenciales incorrectas
**Dado** un usuario que existe en el sistema
**Cuando** envía una contraseña incorrecta
**Entonces** recibe un error genérico de autenticación
**Y** el response tiene status 401
**Y** el mensaje es "Credenciales inválidas" (o similar genérico)

### Escenario 4: Login fallido - usuario no existe
**Dado** un nombre de usuario que no existe en el sistema
**Cuando** intenta hacer login
**Entonces** recibe un error genérico de autenticación
**Y** el response tiene status 401
**Y** el mensaje es el mismo que credenciales incorrectas (seguridad)

### Escenario 5: Login fallido - usuario inactivo
**Dado** un usuario con status inactivo (status != 1)
**Cuando** envía credenciales correctas
**Entonces** recibe un error de autenticación
**Y** el response tiene status 401

### Escenario 6: Login fallido - notaría inactiva
**Dado** un usuario activo pero su notaría está inactiva
**Cuando** envía credenciales correctas
**Entonces** recibe un error de autenticación
**Y** el response tiene status 401

### Escenario 7: Refresh token exitoso
**Dado** un refreshToken válido y no expirado
**Cuando** se envía al endpoint de refresh
**Entonces** recibe un nuevo accessToken
**Y** recibe un nuevo refreshToken
**Y** el response tiene status 200

### Escenario 8: Refresh token expirado
**Dado** un refreshToken expirado (más de 24 horas)
**Cuando** se envía al endpoint de refresh
**Entonces** recibe un error de autenticación
**Y** el response tiene status 401
**Y** el usuario debe hacer login nuevamente

### Escenario 9: Refresh token inválido
**Dado** un token con firma inválida o malformado
**Cuando** se envía al endpoint de refresh
**Entonces** recibe un error de autenticación
**Y** el response tiene status 401

## Estructura del AccessToken

El accessToken JWT debe contener los siguientes claims:

| Claim | Valor | Descripción |
|-------|-------|-------------|
| `iss` | `security@devrob` | Issuer - emisor del token |
| `aud` | `apps@devrob` | Audience - audiencia del token |
| `iat` | timestamp | Issued At - momento de emisión |
| `exp` | iat + 2 horas | Expiration - expiración del token |
| `userId` | int | ID del usuario autenticado |
| `notaryId` | int | ID de la notaría del usuario |
| `features` | array | Lista de features/permisos del usuario |

## Estructura del RefreshToken

El refreshToken JWT debe contener:

| Claim | Valor | Descripción |
|-------|-------|-------------|
| `iss` | `security@devrob` | Issuer |
| `aud` | `apps@devrob` | Audience |
| `iat` | timestamp | Issued At |
| `exp` | iat + 24 horas | Expiración |
| `userId` | int | ID del usuario |
| `notaryId` | int | ID de la notaría |
| `type` | `refresh` | Identificador de tipo de token |

## No Funcionales

- **Rendimiento**: El login debe responder en menos de 500ms
- **Seguridad**: 
  - Contraseñas nunca se loguean ni retornan en responses
  - Mensajes de error genéricos para no revelar información
  - Tokens firmados con algoritmo HS256 mínimo
- **Compatibilidad**: Usuarios con contraseña MD5 deben poder hacer login sin cambios

## Validaciones

### Request de Login
| Campo | Tipo | Requerido | Validación |
|-------|------|-----------|------------|
| `usuario` | string | Sí | No vacío |
| `contrasenia` | string | Sí | No vacío |

### Request de Refresh
| Campo | Tipo | Requerido | Validación |
|-------|------|-----------|------------|
| `refreshToken` | string | Sí | JWT válido |

## Casos Edge

1. **Usuario con ambos campos de contraseña**: Si tiene hash, usar hash; ignorar MD5
2. **Features vacíos**: Si `user_access` está vacío, retornar array vacío en el token
3. **Features con espacios**: Limpiar espacios al parsear `user_access`
4. **Múltiples sesiones**: Permitido, cada login genera tokens independientes

## Criterios de Rechazo

- [ ] El token no contiene todos los claims especificados
- [ ] La migración de contraseña falla silenciosamente
- [ ] Se revelan mensajes específicos de error (ej: "usuario no encontrado")
- [ ] El accessToken dura más de 2 horas
- [ ] El refreshToken dura más de 24 horas
- [ ] Usuarios o notarías inactivas pueden obtener tokens
