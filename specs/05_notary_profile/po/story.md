# Notary Profile Management

## Historia de Usuario

**Como** usuario autenticado de una notaría  
**Quiero** consultar y actualizar la información de mi notaría  
**Para** mantener los datos de la organización actualizados y personalizados con su logo

## Contexto

Cada notaría necesita gestionar su información básica: nombre, titular, dirección, teléfono, RFC y logo. Esta información se utiliza en documentos, reportes y la interfaz del sistema. El usuario no necesita indicar qué notaría consultar o modificar, ya que el sistema lo determina automáticamente desde el token JWT.

## Alcance

### Incluido
- Endpoint GET para consultar información de la notaría actual
- Endpoint POST (multipart) para actualizar información de la notaría
- Endpoint GET para obtener la imagen del logo
- Subida de logo en formato JPG o PNG (máximo 512 KB)
- Validación de tipo MIME real del archivo de logo
- Almacenamiento del logo en `public/assets/{notary_id}/logo/`
- Reemplazo automático del logo anterior al subir uno nuevo

### Excluido
- Eliminación del logo (endpoint dedicado)
- Cambio de estado de la notaría (activar/desactivar)
- Creación de nuevas notarías
- Gestión de permisos específicos para edición

## Usuarios Afectados
- Todos los usuarios autenticados de la notaría (cualquier rol)
