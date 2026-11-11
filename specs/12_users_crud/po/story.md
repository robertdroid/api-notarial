# Users CRUD

## Historia de Usuario

**Como** empleado de una notaría  
**Quiero** gestionar los usuarios de mi notaría (listar, ver, crear, modificar, desactivar)  
**Para** controlar quién tiene acceso al sistema y a qué módulos

## Contexto

El sistema necesita que cada notaría administre sus propios usuarios. Los usuarios son empleados que acceden al sistema para rastrear escrituras y/o gestionar pagos. Cada usuario tiene un conjunto de permisos (`access`) que determina qué módulos puede usar: `ESC` (Escrituras) y `PAG` (Pagos).

Actualmente no existe un rol de administrador separado; cualquier usuario autenticado de la notaría puede gestionar usuarios de su misma notaría.

## Alcance

### Incluido
- Listar usuarios de la notaría (con filtros por estatus)
- Ver detalle de un usuario
- Crear usuario con contraseña (bcrypt)
- Modificar datos del usuario (nombre, apellidos, username, accesos, estatus)
- Desactivar usuario (cambiar estatus a inactivo)

### Excluido
- Cambio de contraseña (endpoint dedicado, implementación futura)
- Roles de administrador o permisos granulares
- Usuarios cross-notaría (superadmin)
- Recuperación de contraseña

## Usuarios Afectados
- Empleados de la notaría que administran personal
- Nuevos empleados que serán dados de alta en el sistema
