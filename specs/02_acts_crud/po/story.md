# CRUD de Actos Jurídicos

## Historia de Usuario

**Como** empleado de una notaría  
**Quiero** gestionar el catálogo de actos jurídicos de mi notaría  
**Para** poder clasificar las escrituras según el tipo de acto que amparan

## Contexto

Los actos jurídicos son un catálogo fundamental del sistema notarial. Cada escritura se asocia a uno o más actos (compraventa, donación, hipoteca, etc.). Este catálogo es propio de cada notaría, permitiendo que cada una defina los actos que maneja según su operación.

El módulo debe permitir:
- Consultar todos los actos de la notaría
- Buscar actos por nombre o filtrar por estatus
- Consultar el detalle de un acto específico
- Crear nuevos actos
- Modificar actos existentes
- Desactivar actos (soft delete mediante cambio de estatus)

## Alcance

### Incluido
- Endpoint GET para listar todos los actos con filtros opcionales
- Endpoint GET para obtener un acto por ID
- Endpoint POST para crear un nuevo acto
- Endpoint PUT para modificar un acto existente
- Validación de token JWT en todos los endpoints
- Filtrado automático por notaría del usuario autenticado
- Validación de nombre único por notaría
- Registro de usuario que modifica (auditoría)

### Excluido
- Paginación (no requerida para catálogos pequeños)
- Endpoint DELETE (se usa soft delete vía PUT)
- Historial de cambios detallado
- Importación/exportación masiva

## Usuarios Afectados
- Empleados de notaría con acceso al sistema
- Administradores de notaría que configuran catálogos
