# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar todos los fraccionamientos
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/subdivisions`  
**Entonces** recibe un array con todos los fraccionamientos de su notaría con status 200

### Escenario 2: Listar fraccionamientos filtrados por estatus
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/subdivisions?status=1`  
**Entonces** recibe solo los fraccionamientos activos (status=1) de su notaría

### Escenario 3: Listar fraccionamientos filtrados por nombre
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/subdivisions?name=centro`  
**Entonces** recibe los fraccionamientos cuyo nombre contiene "centro" (búsqueda parcial, case-insensitive)

### Escenario 4: Combinar filtros
**Dado** un usuario autenticado con token válido  
**Cuando** hace GET a `/api/v3/subdivisions?status=1&name=residencial`  
**Entonces** recibe los fraccionamientos activos cuyo nombre contiene "residencial"

### Escenario 5: Obtener fraccionamiento por ID
**Dado** un usuario autenticado y un fraccionamiento existente en su notaría  
**Cuando** hace GET a `/api/v3/subdivisions/{id}`  
**Entonces** recibe el detalle del fraccionamiento con status 200

### Escenario 6: Obtener fraccionamiento inexistente
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/subdivisions/{id}` con un ID que no existe  
**Entonces** recibe status 404 con mensaje "Subdivision not found"

### Escenario 7: Obtener fraccionamiento de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/subdivisions/{id}` con un ID de otra notaría  
**Entonces** recibe status 404 (no debe revelar que existe en otra notaría)

### Escenario 8: Crear fraccionamiento exitosamente
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/subdivisions` con nombre válido  
**Entonces** el fraccionamiento se crea y recibe status 201 sin body

### Escenario 9: Crear fraccionamiento con nombre duplicado
**Dado** un usuario autenticado y un fraccionamiento "Residencial Centro" ya existente  
**Cuando** hace POST a `/api/v3/subdivisions` con nombre "Residencial Centro"  
**Entonces** recibe status 400 con mensaje "Subdivision name already exists"

### Escenario 10: Crear fraccionamiento con datos inválidos
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/subdivisions` sin nombre o con nombre vacío  
**Entonces** recibe status 400 con mensaje de validación

### Escenario 11: Actualizar fraccionamiento exitosamente
**Dado** un usuario autenticado y un fraccionamiento existente en su notaría  
**Cuando** hace PUT a `/api/v3/subdivisions/{id}` con datos válidos  
**Entonces** el fraccionamiento se actualiza y recibe status 204 sin body

### Escenario 12: Desactivar fraccionamiento (soft delete)
**Dado** un usuario autenticado y un fraccionamiento activo  
**Cuando** hace PUT a `/api/v3/subdivisions/{id}` con status=2  
**Entonces** el fraccionamiento queda inactivo (soft delete)

### Escenario 13: Actualizar fraccionamiento de otra notaría
**Dado** un usuario autenticado  
**Cuando** hace PUT a `/api/v3/subdivisions/{id}` de otra notaría  
**Entonces** recibe status 404

### Escenario 14: Actualizar con nombre duplicado
**Dado** fraccionamientos "Residencial Centro" y "Residencial Norte" existentes  
**Cuando** intenta cambiar "Residencial Norte" a "Residencial Centro"  
**Entonces** recibe status 400 con mensaje "Subdivision name already exists"

### Escenario 15: Acceso sin token
**Dado** una petición sin token de autorización  
**Cuando** accede a cualquier endpoint de subdivisions  
**Entonces** recibe status 401 con mensaje "Unauthorized"

### Escenario 16: Token expirado
**Dado** una petición con token expirado  
**Cuando** accede a cualquier endpoint de subdivisions  
**Entonces** recibe status 401 con mensaje "Token expired"

## No Funcionales

- Tiempo de respuesta < 200ms para operaciones de lectura
- Tiempo de respuesta < 500ms para operaciones de escritura
- Todos los endpoints deben validar el token JWT
- Los datos sensibles no deben exponerse en mensajes de error

## Validaciones

| Campo | Regla |
|-------|-------|
| name | Requerido, máximo 500 caracteres, único por notaría |
| status | Opcional en POST (default 1), valores permitidos: 1 o 2 |

## Casos Edge

- Nombre con espacios al inicio/final: debe trimmearse antes de validar unicidad
- Búsqueda con caracteres especiales: debe manejarse correctamente (SQL injection prevention)
- ID con formato inválido (no numérico): debe retornar 400

## Criterios de Rechazo

- El endpoint permite ver/modificar fraccionamientos de otra notaría
- No se valida el token JWT
- Se permite crear fraccionamientos con nombres duplicados
- Los mensajes de error revelan información sensible del sistema
- No se registra el usuario que modifica el fraccionamiento
