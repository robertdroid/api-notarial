# Payment Taxes CRUD

## Historia de Usuario

**Como** usuario de una notaría  
**Quiero** gestionar el catálogo de impuestos/conceptos de pago  
**Para** poder asociarlos a procedimientos de pago y mantener actualizada la lista de conceptos que se cobran

## Contexto

El módulo de pagos permite gestionar los procedimientos de cobro de la notaría. Cada procedimiento puede incluir varios impuestos o conceptos (ISR, derechos, constancias, etc.). Este feature implementa el CRUD del catálogo de impuestos (`payment_taxes`), que es prerrequisito para la gestión de procedimientos.

Los impuestos son específicos de cada notaría (multi-tenant) y se identifican por nombre. La API v3 reemplaza el endpoint legacy `payments_tax.php` con una implementación RESTful consistente con el resto de los catálogos.

## Alcance

### Incluido
- Listar impuestos de la notaría con filtros opcionales (status, nombre)
- Obtener un impuesto por ID
- Crear nuevo impuesto
- Actualizar impuesto existente (incluyendo soft delete vía status=2)
- Validación de token JWT en todos los endpoints
- Validación de nombre único por notaría
- Aislamiento multi-tenant (solo datos de la notaría del usuario)

### Excluido
- Endpoint DELETE (la baja es vía PUT con status=2)
- Gestión de la relación impuesto-procedimiento (`payment_procedure_taxes`)
- Validación de uso antes de desactivar (se permite desactivar aunque esté en uso)
- CRUD de otros catálogos del módulo de pagos (procedures, clients, transactions)

## Usuarios Afectados
- Usuarios de notaría con acceso al módulo de pagos
- Administradores que configuran los catálogos base
