# Listado de Procedimientos con sus Impuestos

## Historia de Usuario

**Como** usuario de la notaría (módulo de pagos)
**Quiero** obtener en una sola llamada todos los procedimientos activos con sus impuestos configurados y los valores default de cada uno
**Para** armar pantallas de cobro/configuración sin hacer una llamada por procedimiento a `GET /payment-procedures/{id}/taxes`

## Contexto
Hoy los impuestos de un procedimiento solo se consultan procedimiento por procedimiento (`GET /api/v3/payment-procedures/{id}/taxes`, spec 10). Para pantallas que necesitan el catálogo completo (cobro, configuración) esto implica N+1 llamadas. Se requiere un endpoint agregado de solo lectura que devuelva todos los procedimientos activos de la notaría, cada uno con su arreglo de impuestos activos y su `defaultValue`.

Los procedimientos sin impuestos configurados se incluyen con `taxes: []`, para que el cliente tenga el catálogo completo de procedimientos.

## Alcance
### Incluido
- `GET /api/v3/payment-procedures/taxes` (autenticado, filtrado por `notary_id` del token)
- Solo procedimientos con `id_status = 1`
- Procedimientos sin impuestos con `taxes: []`
- Por procedimiento, solo impuestos con relación activa (`payment_procedure_taxes.id_status = 1`) y catálogo activo (`payment_taxes.id_status = 1`)
- Respuesta completa, sin paginación

### Excluido
- Paginación y filtros (query params)
- Procedimientos o impuestos inactivos
- Escritura (alta, edición, baja de impuestos); sigue en spec 10
- Cambios a los endpoints existentes

## Usuarios Afectados
- Usuarios de la notaría con acceso al módulo Payments (cualquier usuario autenticado, igual que los endpoints existentes)
