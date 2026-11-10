# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar impuestos de un procedimiento
**Dado** un usuario autenticado de la notaría  
**Cuando** solicita los impuestos configurados para un procedimiento (`GET /payment-procedures/{id}/taxes`)  
**Entonces** recibe la lista de impuestos activos (status=1 en `payment_procedure_taxes`) con su nombre y valor default

### Escenario 2: Agregar un impuesto a un procedimiento
**Dado** un usuario autenticado de la notaría  
**Y** un procedimiento activo de su notaría  
**Y** un impuesto activo de su notaría que no está asignado al procedimiento  
**Cuando** envía `POST /payment-procedures/{id}/taxes` con `{taxId, defaultValue?}`  
**Entonces** se crea el registro en `payment_procedure_taxes` con status=1  
**Y** si `defaultValue` es omitido, negativo o cero, se guarda como 0  
**Y** responde 201 Created con el registro creado

### Escenario 3: Agregar varios impuestos en bulk (creación inicial)
**Dado** un usuario autenticado de la notaría  
**Y** un procedimiento activo sin impuestos configurados  
**Cuando** envía `POST /payment-procedures/{id}/taxes` con `{taxes: [{taxId, defaultValue?}, ...]}`  
**Entonces** se crean todos los registros en `payment_procedure_taxes`  
**Y** responde 201 Created con la lista de registros creados

### Escenario 4: Rehabilitar un impuesto deshabilitado
**Dado** un impuesto que estuvo asignado al procedimiento pero fue deshabilitado (status=2)  
**Cuando** se envía `POST /payment-procedures/{id}/taxes` con ese `taxId`  
**Entonces** se actualiza el registro existente: status=1 y el nuevo `defaultValue`  
**Y** responde 201 Created (como si fuera nuevo)

### Escenario 5: Modificar el valor default
**Dado** un impuesto activo asignado al procedimiento  
**Cuando** envía `PUT /payment-procedures/{id}/taxes/{taxId}` con `{defaultValue}`  
**Entonces** se actualiza el `default_value` en la base de datos  
**Y** si `defaultValue` es negativo o cero, se guarda como 0  
**Y** responde 200 OK con el registro actualizado

### Escenario 6: Deshabilitar un impuesto del procedimiento
**Dado** un impuesto activo asignado al procedimiento  
**Cuando** envía `DELETE /payment-procedures/{id}/taxes/{taxId}`  
**Entonces** se actualiza `id_status=2` (soft delete)  
**Y** responde 204 No Content

### Escenario 7: Procedimiento no existe o es de otra notaría
**Dado** un `id` de procedimiento que no existe o pertenece a otra notaría  
**Cuando** se hace cualquier operación sobre `/payment-procedures/{id}/taxes`  
**Entonces** responde 404 Not Found

### Escenario 8: Impuesto no existe o es de otra notaría
**Dado** un `taxId` que no existe o pertenece a otra notaría  
**Cuando** se intenta agregar o modificar  
**Entonces** responde 404 Not Found para el impuesto

### Escenario 9: Intentar agregar impuesto ya activo
**Dado** un impuesto que ya está activo en el procedimiento  
**Cuando** se envía POST para agregarlo  
**Entonces** responde 409 Conflict con mensaje descriptivo

### Escenario 10: Intentar agregar impuesto inactivo
**Dado** un impuesto con status=2 en `payment_taxes`  
**Cuando** se intenta agregar al procedimiento  
**Entonces** responde 422 Unprocessable Entity indicando que el impuesto está inactivo

### Escenario 11: Intentar configurar procedimiento inactivo
**Dado** un procedimiento con status=2 en `payment_procedures`  
**Cuando** se intenta agregar un impuesto  
**Entonces** responde 422 Unprocessable Entity indicando que el procedimiento está inactivo

### Escenario 12: Modificar o eliminar impuesto no asignado
**Dado** un impuesto que no está asignado al procedimiento (o está con status=2)  
**Cuando** se intenta PUT o DELETE  
**Entonces** responde 404 Not Found

## No Funcionales

- **Rendimiento**: Las consultas deben usar los índices existentes en las tablas relacionadas
- **Consistencia**: Aunque MyISAM no soporta transacciones, el bulk debe validar todo antes de insertar
- **Multi-tenencia**: Todas las operaciones deben filtrar por `id_notary` del token JWT

## Validaciones

| Campo        | Regla                                                    |
|--------------|----------------------------------------------------------|
| taxId        | Requerido, debe existir y estar activo en la notaría     |
| defaultValue | Opcional, double, si ≤0 se guarda como 0                 |
| taxes[]      | Array de {taxId, defaultValue?}, solo en bulk            |

## Casos Edge

1. **Bulk con duplicados**: Si el array `taxes` contiene el mismo `taxId` dos veces, rechazar con 400
2. **Bulk parcial inválido**: Si algún impuesto del array no es válido, rechazar todo el bulk con 400 (no insertar ninguno)
3. **Procedimiento sin impuestos**: `GET` devuelve array vacío `[]`, no 404
4. **defaultValue muy grande**: Aceptar cualquier double válido (la lógica de negocio no limita montos)

## Criterios de Rechazo

- Se permite agregar impuestos a procedimientos inactivos
- Se muestran impuestos con status=2 en el listado
- Se permite cross-tenant (impuesto de notaría A en procedimiento de notaría B)
- Se elimina físicamente en lugar de soft delete
- El bulk inserta parcialmente cuando hay errores
