# CRUD de Tipos de Escritura

## Historia de Usuario

**Como** empleado de una notaría  
**Quiero** gestionar el catálogo de tipos de escritura de mi notaría  
**Para** poder clasificar las escrituras según su tipo y configurar comportamientos específicos por tipo

## Contexto

Los tipos de escritura son un catálogo fundamental que permite clasificar las escrituras que maneja una notaría. Cada tipo define:
- El nombre y descripción del tipo
- El número máximo de inscripciones permitidas
- Si se activan o no los fraccionamientos para ese tipo

Este catálogo es propio de cada notaría, permitiendo personalizar la clasificación según su operación. Los tipos de escritura se usan tanto en escrituras existentes como en nuevas.

El módulo debe permitir:
- Consultar todos los tipos de escritura de la notaría
- Buscar tipos por nombre o filtrar por estatus
- Consultar el detalle de un tipo específico
- Crear nuevos tipos de escritura
- Modificar tipos existentes
- Desactivar tipos (soft delete mediante cambio de estatus)

## Alcance

### Incluido
- Endpoint GET para listar todos los tipos con filtros opcionales
- Endpoint GET para obtener un tipo por ID
- Endpoint POST para crear un nuevo tipo
- Endpoint PUT para modificar un tipo existente
- Validación de token JWT en todos los endpoints
- Filtrado automático por notaría del usuario autenticado
- Validación de nombre único por notaría
- Registro de usuario que modifica (auditoría)
- Campos configurables: maxInscriptions y enableSubdivisions

### Excluido
- Paginación (no requerida para catálogos pequeños)
- Endpoint DELETE (se usa soft delete vía PUT)
- Historial de cambios detallado
- Importación/exportación masiva
- Validación de uso antes de desactivar (se permite desactivar aunque esté en uso)

## Usuarios Afectados
- Empleados de notaría con acceso al sistema
- Administradores de notaría que configuran catálogos
