# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar todos los actos
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/acts`  
**Entonces** recibe un array con todos los actos de su notaría con status 200

### Escenario 2: Listar actos filtrados por estatus
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/acts?status=1`  
**Entonces** recibe solo los actos activos (status=1) de su notaría

### Escenario 3: Listar actos filtrados por nombre
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/acts?name=compra`  
**Entonces** recibe los actos cuyo nombre contiene "compra" (búsqueda parcial, case-insensitive)

### Escenario 4: Combinar filtros
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/acts?status=1&name=venta`  
**Entonces** recibe los actos activos cuyo nombre contiene "venta"

### Escenario 5: Obtener acto por ID
**Dado** un usuario autenticado y un acto existente en su notaría  
**Cuando** hace GET a `/api/v3/acts/{id}`  
**Entonces** recibe el detalle del acto con status 200

### Escenario 6: Obtener acto inexistente
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/acts/{id}` con un ID que no existe  
**Entonces** recibe status 404 con mensaje "Act not found"

### Escenario 7: Obtener acto de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/acts/{id}` con un ID de otra notaría  
**Entonces** recibe status 404 (no debe revelar que existe en otra notaría)

### Escenario 8: Crear acto exitosamente
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/acts` con nombre y descripción válidos  
**Entonces** el acto se crea y recibe status 201 sin body

### Escenario 9: Crear acto con nombre duplicado
**Dado** un usuario autenticado y un acto "Compraventa" ya existente  
**Cuando** hace POST a `/api/v3/acts` con nombre "Compraventa"  
**Entonces** recibe status 400 con mensaje "Act name already exists"

### Escenario 10: Crear acto con datos inválidos
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/acts` sin nombre o con nombre vacío  
**Entonces** recibe status 400 con mensaje de validación

### Escenario 11: Actualizar acto exitosamente
**Dado** un usuario autenticado y un acto existente en su notaría  
**Cuando** hace PUT a `/api/v3/acts/{id}` con datos válidos  
**Entonces** el acto se actualiza y recibe status 204 sin body

### Escenario 12: Desactivar acto (soft delete)
**Dado** un usuario autenticado y un acto activo  
**Cuando** hace PUT a `/api/v3/acts/{id}` con status=2  
**Entonces** el acto queda inactivo (soft delete)

### Escenario 13: Actualizar acto de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace PUT a `/api/v3/acts/{id}` de otra notaría  
**Entonces** recibe status 404

### Escenario 14: Actualizar con nombre duplicado
**Dado** actos "Compraventa" y "Donación" existentes  
**Cuando** intenta cambiar "Donación" a "Compraventa"  
**Entonces** recibe status 400 con mensaje "Act name already exists"

### Escenario 15: Acceso sin token
**Dado** una petición sin token de autorización  
**Cuando** accede a cualquier endpoint de acts  
**Entonces** recibe status 401 con mensaje "Unauthorized"

### Escenario 16: Token expirado
**Dado** una petición con token expirado  
**Cuando** accede a cualquier endpoint de acts  
**Entonces** recibe status 401 con mensaje "Token expired"

## No Funcionales

- Tiempo de respuesta < 200ms para operaciones de lectura
- Tiempo de respuesta < 500ms para operaciones de escritura
- Todos los endpoints deben validar el token JWT
- Los datos sensibles no deben exponerse en mensajes de error

## Validaciones

| Campo | Regla |
|-------|-------|
| actName | Requerido, máximo 200 caracteres, único por notaría |
| actDescription | Requerido, máximo 500 caracteres |
| status | Opcional en POST (default 1), valores permitidos: 1 o 2 |

## Casos Edge

- Nombre con espacios al inicio/final: debe trimmearse antes de validar unicidad
- Búsqueda con caracteres especiales: debe manejarse correctamente (SQL injection prevention)
- ID con formato inválido (no numérico): debe retornar 400

## Criterios de Rechazo

- El endpoint permite ver/modificar actos de otra notaría
- No se valida el token JWT
- Se permite crear actos con nombres duplicados
- Los mensajes de error revelan información sensible del sistema
- No se registra el usuario que modifica el acto
