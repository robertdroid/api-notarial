# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar todos los tipos de escritura
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/deed-types`  
**Entonces** recibe un array con todos los tipos de escritura de su notaría con status 200

### Escenario 2: Listar tipos filtrados por estatus
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/deed-types?status=1`  
**Entonces** recibe solo los tipos activos (status=1) de su notaría

### Escenario 3: Listar tipos filtrados por nombre
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/deed-types?name=infonavit`  
**Entonces** recibe los tipos cuyo nombre contiene "infonavit" (búsqueda parcial, case-insensitive)

### Escenario 4: Combinar filtros
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/deed-types?status=1&name=actual`  
**Entonces** recibe los tipos activos cuyo nombre contiene "actual"

### Escenario 5: Obtener tipo por ID
**Dado** un usuario autenticado y un tipo existente en su notaría  
**Cuando** hace GET a `/api/v3/deed-types/{id}`  
**Entonces** recibe el detalle del tipo con status 200

### Escenario 6: Obtener tipo inexistente
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deed-types/{id}` con un ID que no existe  
**Entonces** recibe status 404 con mensaje "Deed type not found"

### Escenario 7: Obtener tipo de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deed-types/{id}` con un ID de otra notaría  
**Entonces** recibe status 404 (no debe revelar que existe en otra notaría)

### Escenario 8: Crear tipo exitosamente
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/deed-types` con datos válidos  
**Entonces** el tipo se crea y recibe status 201 sin body

### Escenario 9: Crear tipo con nombre duplicado
**Dado** un usuario autenticado y un tipo "Infonavit" ya existente  
**Cuando** hace POST a `/api/v3/deed-types` con nombre "Infonavit"  
**Entonces** recibe status 400 con mensaje "Deed type name already exists"

### Escenario 10: Crear tipo con datos inválidos
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/deed-types` sin nombre o con nombre vacío  
**Entonces** recibe status 400 con mensaje de validación

### Escenario 11: Actualizar tipo exitosamente
**Dado** un usuario autenticado y un tipo existente en su notaría  
**Cuando** hace PUT a `/api/v3/deed-types/{id}` con datos válidos  
**Entonces** el tipo se actualiza y recibe status 204 sin body

### Escenario 12: Desactivar tipo (soft delete)
**Dado** un usuario autenticado y un tipo activo  
**Cuando** hace PUT a `/api/v3/deed-types/{id}` con status=2  
**Entonces** el tipo queda inactivo (soft delete)

### Escenario 13: Actualizar tipo de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace PUT a `/api/v3/deed-types/{id}` de otra notaría  
**Entonces** recibe status 404

### Escenario 14: Actualizar con nombre duplicado
**Dado** tipos "Infonavit" y "Actuales" existentes  
**Cuando** intenta cambiar "Actuales" a "Infonavit"  
**Entonces** recibe status 400 con mensaje "Deed type name already exists"

### Escenario 15: Acceso sin token
**Dado** una petición sin token de autorización  
**Cuando** accede a cualquier endpoint de deed-types  
**Entonces** recibe status 401 con mensaje "Unauthorized"

### Escenario 16: Token expirado
**Dado** una petición con token expirado  
**Cuando** accede a cualquier endpoint de deed-types  
**Entonces** recibe status 401 con mensaje "Token expired"

### Escenario 17: Crear tipo con maxInscriptions personalizado
**Dado** un usuario autenticado  
**Cuando** hace POST con maxInscriptions=5  
**Entonces** el tipo se crea con ese valor de máximo inscripciones

### Escenario 18: Crear tipo con enableSubdivisions activo
**Dado** un usuario autenticado  
**Cuando** hace POST con enableSubdivisions=true  
**Entonces** el tipo se crea con fraccionamientos habilitados

## No Funcionales

- Tiempo de respuesta < 200ms para operaciones de lectura
- Tiempo de respuesta < 500ms para operaciones de escritura
- Todos los endpoints deben validar el token JWT
- Los datos sensibles no deben exponerse en mensajes de error

## Validaciones

| Campo | Regla |
|-------|-------|
| name | Requerido, máximo 200 caracteres, único por notaría |
| description | Requerido, máximo 500 caracteres |
| maxInscriptions | Opcional, entero positivo, default 1 |
| enableSubdivisions | Opcional, booleano (0/1), default 0 |
| status | Opcional en POST (default 1), valores permitidos: 1 o 2 |

## Casos Edge

- Nombre con espacios al inicio/final: debe trimmearse antes de validar unicidad
- Búsqueda con caracteres especiales: debe manejarse correctamente (SQL injection prevention)
- ID con formato inválido (no numérico): debe retornar 400
- maxInscriptions con valor 0 o negativo: debe rechazarse o usar default
- enableSubdivisions con valor diferente a 0/1: debe normalizarse a booleano

## Criterios de Rechazo

- El endpoint permite ver/modificar tipos de otra notaría
- No se valida el token JWT
- Se permite crear tipos con nombres duplicados
- Los mensajes de error revelan información sensible del sistema
- No se registra el usuario que modifica el tipo
