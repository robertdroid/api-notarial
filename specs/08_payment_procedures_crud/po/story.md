# Payment Procedures CRUD

## Historia de Usuario

**Como** usuario de una notaría  
**Quiero** gestionar el catálogo de procedimientos de pago  
**Para** mantener organizada la lista de trámites y servicios que ofrece la notaría y que pueden generar cobros

## Contexto

El módulo de pagos permite gestionar los procedimientos de cobro de la notaría. Un procedimiento representa un trámite o servicio (ej: "Escritura de compraventa", "Certificación de documentos", "Ratificación de firmas") que la notaría ofrece y que puede tener impuestos o conceptos asociados.

Este feature implementa el CRUD del catálogo de procedimientos (`payment_procedures`). Los procedimientos son específicos de cada notaría (multi-tenant) y se identifican por nombre. La API v3 proporciona una implementación RESTful consistente con el resto de los catálogos del sistema.

## Alcance

### Incluido
- Listar procedimientos de la notaría con filtros opcionales (status, nombre)
- Obtener un procedimiento por ID
- Crear nuevo procedimiento
- Actualizar procedimiento existente (incluyendo soft delete vía status=2)
- Validación de token JWT en todos los endpoints
- Validación de nombre único por notaría (case-insensitive, con trim)
- Aislamiento multi-tenant (solo datos de la notaría del usuario)
- Migración para agregar índice `(id_notary, id_status)` a la tabla

### Excluido
- Endpoint DELETE (la baja es vía PUT con status=2)
- Gestión de la relación procedimiento-impuesto (`payment_procedure_taxes`) - se manejará en feature separado
- Validación de uso en transacciones antes de desactivar (fuera del scope)
- Campos monetarios (costo, honorarios) - no existen en la tabla actual

## Usuarios Afectados
- Usuarios de notaría con acceso al módulo de pagos
- Administradores que configuran los catálogos base
