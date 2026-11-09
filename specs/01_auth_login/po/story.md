# API de Autenticación (Login)

## Historia de Usuario

**Como** usuario de una notaría (empleado)
**Quiero** iniciar sesión con mi usuario y contraseña
**Para** obtener acceso al sistema y sus funcionalidades según mis permisos

## Contexto

El sistema Notarial requiere un mecanismo de autenticación seguro basado en JWT (JSON Web Tokens). Los usuarios son empleados de notarías que necesitan acceder al sistema para gestionar escrituras, actos, participantes y demás funcionalidades.

Actualmente las contraseñas están almacenadas con MD5, lo cual es inseguro. Este feature incluye una migración transparente a `password_hash` (bcrypt) manteniendo compatibilidad con contraseñas existentes.

## Alcance

### Incluido
- Endpoint de login (`POST /api/v3/auth/login`)
- Generación de accessToken JWT (2 horas de duración)
- Generación de refreshToken JWT (24 horas de duración)
- Endpoint de refresh token (`POST /api/v3/auth/refresh`)
- Migración automática y transparente de contraseñas MD5 a password_hash
- Nuevo campo en base de datos para contraseña hasheada
- Validación de usuario y notaría activos

### Excluido
- Logout (invalidación de tokens) - futuro feature
- Límite de intentos de login - futuro feature
- Recuperación de contraseña - futuro feature
- Registro de nuevos usuarios - otro módulo

## Usuarios Afectados

- **Todos los usuarios del sistema**: Empleados de todas las notarías que usan el sistema
- **Administradores**: Para gestión de accesos

## Valor de Negocio

1. **Seguridad mejorada**: Migración de MD5 a bcrypt sin fricción para el usuario
2. **Acceso controlado**: Tokens con información de permisos (features)
3. **Multi-tenencia**: Cada token identifica la notaría del usuario
4. **Sesiones extendidas**: Refresh token permite renovar acceso sin re-login
