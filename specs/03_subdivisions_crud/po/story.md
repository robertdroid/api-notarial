# CRUD de Fraccionamientos

## Historia de Usuario

**Como** empleado de una notaría  
**Quiero** gestionar el catálogo de fraccionamientos de mi notaría  
**Para** poder asociar las escrituras a ubicaciones/desarrollos específicos

## Contexto

Los fraccionamientos son un catálogo que representa ubicaciones o desarrollos inmobiliarios donde se encuentran los inmuebles de las escrituras. Cada escritura puede asociarse opcionalmente a un fraccionamiento. Este catálogo es propio de cada notaría, permitiendo que cada una defina los fraccionamientos que maneja según su operación y zona geográfica.

El módulo debe permitir:
- Consultar todos los fraccionamientos de la notaría
- Buscar fraccionamientos por nombre o filtrar por estatus
- Consultar el detalle de un fraccionamiento específico
- Crear nuevos fraccionamientos
- Modificar fraccionamientos existentes
- Desactivar fraccionamientos (soft delete mediante cambio de estatus)

## Alcance

### Incluido
- Endpoint GET para listar todos los fraccionamientos con filtros opcionales
- Endpoint GET para obtener un fraccionamiento por ID
- Endpoint POST para crear un nuevo fraccionamiento
- Endpoint PUT para modificar un fraccionamiento existente
- Validación de token JWT en todos los endpoints
- Filtrado automático por notaría del usuario autenticado
- Validación de nombre único por notaría
- Registro de usuario que modifica (auditoría)

### Excluido
- Paginación (no requerida para catálogos pequeños)
- Endpoint DELETE (se usa soft delete vía PUT)
- Historial de cambios detallado
- Importación/exportación masiva
- Campos adicionales como dirección, código postal, zona (futuras mejoras)

## Usuarios Afectados
- Empleados de notaría con acceso al sistema
- Administradores de notaría que configuran catálogos
