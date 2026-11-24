# Payment Clients CRUD

## Historia de Usuario

**Como** usuario de una notaría  
**Quiero** gestionar el catálogo de clientes de pagos  
**Para** asociarlos a transacciones y mantener un registro de contacto actualizado

## Contexto

El módulo de pagos requiere un catálogo de clientes (`payments_clients`) que se asocian a las transacciones. Este CRUD permite listar, consultar, crear y modificar clientes de pago dentro del contexto multi-tenant de cada notaría.

A diferencia de otros catálogos (actos, tipos de escritura, procedimientos), el nombre del cliente **no requiere unicidad**: dos clientes distintos pueden llamarse igual.

## Alcance

### Incluido
- Listar clientes de la notaría (con filtros por status y nombre)
- Obtener cliente por ID
- Crear nuevo cliente
- Actualizar cliente existente
- Desactivar cliente (soft delete vía status=2)

### Excluido
- Eliminación física de registros
- Paginación (se implementará después si es necesario)
- Búsqueda por email o teléfono (solo por nombre)
- Validación de unicidad del nombre

## Usuarios Afectados

- Usuarios de la notaría que gestionan pagos
- Cualquier rol con acceso al módulo de pagos
