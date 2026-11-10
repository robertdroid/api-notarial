# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar todos los impuestos
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/payment-taxes`  
**Entonces** recibe un array con todos los impuestos de su notaría con status 200

### Escenario 2: Listar impuestos filtrados por estatus
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/payment-taxes?status=1`  
**Entonces** recibe solo los impuestos activos (status=1) de su notaría

### Escenario 3: Listar impuestos filtrados por nombre
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/payment-taxes?name=subdivision`  
**Entonces** recibe los impuestos cuyo nombre contiene "subdivision" (búsqueda parcial, case-insensitive)

### Escenario 4: Combinar filtros
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/payment-taxes?status=1&name=pago`  
**Entonces** recibe los impuestos activos cuyo nombre contiene "pago"

### Escenario 5: Obtener impuesto por ID
**Dado** un usuario autenticado y un impuesto existente en su notaría  
**Cuando** hace GET a `/api/v3/payment-taxes/{id}`  
**Entonces** recibe el detalle del impuesto con status 200

### Escenario 6: Obtener impuesto inexistente
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/payment-taxes/{id}` con un ID que no existe  
**Entonces** recibe status 404 con mensaje "Tax not found"

### Escenario 7: Obtener impuesto de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/payment-taxes/{id}` con un ID de otra notaría  
**Entonces** recibe status 404 (no debe revelar que existe en otra notaría)

### Escenario 8: Crear impuesto exitosamente
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-taxes` con `{"taxName": "ISR"}`  
**Entonces** el impuesto se crea con status=1 por defecto y recibe status 201 sin body

### Escenario 9: Crear impuesto con status explícito
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-taxes` con `{"taxName": "IVA", "status": 2}`  
**Entonces** el impuesto se crea con status=2 y recibe status 201 sin body

### Escenario 10: Crear impuesto con nombre duplicado
**Dado** un usuario autenticado y un impuesto "ISR" ya existente  
**Cuando** hace POST a `/api/v3/payment-taxes` con `{"taxName": "isr"}` (case-insensitive)  
**Entonces** recibe status 400 con mensaje "Tax name already exists"

### Escenario 11: Crear impuesto sin nombre
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-taxes` sin campo `taxName`  
**Entonces** recibe status 400 con mensaje de validación

### Escenario 12: Crear impuesto con nombre vacío
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-taxes` con `{"taxName": ""}`  
**Entonces** recibe status 400 con mensaje de validación

### Escenario 13: Crear impuesto con nombre muy largo
**Dado** un usuario autenticado  
**Cuando** hace POST con `taxName` de más de 500 caracteres  
**Entonces** recibe status 400 con mensaje de validación

### Escenario 14: Crear impuesto con status inválido
**Dado** un usuario autenticado  
**Cuando** hace POST con `{"taxName": "Test", "status": 3}`  
**Entonces** recibe status 400 con mensaje de validación (solo 1 o 2 permitidos)

### Escenario 15: Actualizar impuesto exitosamente
**Dado** un usuario autenticado y un impuesto existente en su notaría  
**Cuando** hace PUT a `/api/v3/payment-taxes/{id}` con datos válidos  
**Entonces** el impuesto se actualiza y recibe status 204 sin body

### Escenario 16: Desactivar impuesto (soft delete)
**Dado** un usuario autenticado y un impuesto activo  
**Cuando** hace PUT a `/api/v3/payment-taxes/{id}` con `{"status": 2}`  
**Entonces** el impuesto queda inactivo (soft delete)

### Escenario 17: Reactivar impuesto
**Dado** un usuario autenticado y un impuesto inactivo  
**Cuando** hace PUT a `/api/v3/payment-taxes/{id}` con `{"status": 1}`  
**Entonces** el impuesto queda activo nuevamente

### Escenario 18: Actualizar impuesto de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace PUT a `/api/v3/payment-taxes/{id}` de otra notaría  
**Entonces** recibe status 404

### Escenario 19: Actualizar con nombre duplicado
**Dado** impuestos "ISR" e "IVA" existentes  
**Cuando** intenta cambiar "IVA" a "ISR"  
**Entonces** recibe status 400 con mensaje "Tax name already exists"

### Escenario 20: Actualizar con el mismo nombre (sin cambio)
**Dado** un impuesto "ISR" existente  
**Cuando** hace PUT con `{"taxName": "ISR"}` (mismo nombre)  
**Entonces** la actualización es exitosa con status 204

### Escenario 21: Acceso sin token
**Dado** una petición sin header Authorization  
**Cuando** accede a cualquier endpoint de payment-taxes  
**Entonces** recibe status 401 con mensaje "Unauthorized"

### Escenario 22: Token expirado
**Dado** una petición con token JWT expirado  
**Cuando** accede a cualquier endpoint de payment-taxes  
**Entonces** recibe status 401 con mensaje "Token expired"

### Escenario 23: Token inválido
**Dado** una petición con token malformado o firma inválida  
**Cuando** accede a cualquier endpoint de payment-taxes  
**Entonces** recibe status 401 con mensaje apropiado

## No Funcionales

- **Rendimiento:** Las consultas deben usar índices en `id_notary` y `id_status`
- **Seguridad:** Validación de token en cada request, aislamiento multi-tenant estricto
- **Consistencia:** Formato de respuesta idéntico al resto de catálogos (deed-types, acts, subdivisions)

## Validaciones

| Campo    | Regla                                           |
|----------|-------------------------------------------------|
| taxName  | Obligatorio, no vacío, máximo 500 caracteres    |
| status   | Opcional en POST (default 1), solo valores 1 o 2 |

## Casos Edge

- Nombre con espacios al inicio/final: trim antes de guardar y comparar
- Nombre con caracteres especiales: permitidos (ej: "ISR (10%)")
- ID con valor 0 o negativo: devolver 404
- ID no numérico en URL: devolver 400 "Invalid ID format"

## Criterios de Rechazo

- Permitir acceso a impuestos de otra notaría
- Aceptar status diferente de 1 o 2
- No validar unicidad del nombre al crear o actualizar
- Exponer el notaryId en la respuesta (se infiere del token)
- Implementar endpoint DELETE
