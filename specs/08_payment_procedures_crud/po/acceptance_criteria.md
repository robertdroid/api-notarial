# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar procedimientos
**Dado** un usuario autenticado con token JWT válido  
**Cuando** hace GET a `/api/v3/payment-procedures`  
**Entonces** recibe lista de procedimientos de su notaría con código 200

### Escenario 2: Listar con filtro de status
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/payment-procedures?status=1`  
**Entonces** recibe solo procedimientos activos de su notaría

### Escenario 3: Listar con filtro de nombre
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/payment-procedures?name=escritura`  
**Entonces** recibe procedimientos cuyo nombre contiene "escritura" (case-insensitive)

### Escenario 4: Obtener procedimiento por ID
**Dado** un usuario autenticado y un procedimiento existente de su notaría  
**Cuando** hace GET a `/api/v3/payment-procedures/{id}`  
**Entonces** recibe el procedimiento con código 200

### Escenario 5: Obtener procedimiento de otra notaría
**Dado** un usuario autenticado y un procedimiento de OTRA notaría  
**Cuando** hace GET a `/api/v3/payment-procedures/{id}`  
**Entonces** recibe código 404 (no exponer que existe en otra notaría)

### Escenario 6: Crear procedimiento
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-procedures` con `{"procedureName": "Nuevo trámite"}`  
**Entonces** se crea el procedimiento asociado a su notaría con status=1 y recibe código 201

### Escenario 7: Crear procedimiento con nombre duplicado
**Dado** un usuario autenticado y un procedimiento existente "Compraventa"  
**Cuando** hace POST con `{"procedureName": "compraventa"}` (case-insensitive)  
**Entonces** recibe código 409 Conflict con mensaje de error

### Escenario 8: Crear procedimiento con nombre vacío
**Dado** un usuario autenticado  
**Cuando** hace POST con `{"procedureName": ""}` o sin el campo  
**Entonces** recibe código 400 Bad Request

### Escenario 9: Actualizar procedimiento
**Dado** un usuario autenticado y un procedimiento existente de su notaría  
**Cuando** hace PUT a `/api/v3/payment-procedures/{id}` con nuevos datos  
**Entonces** se actualiza el procedimiento y recibe código 200

### Escenario 10: Desactivar procedimiento (soft delete)
**Dado** un usuario autenticado y un procedimiento activo de su notaría  
**Cuando** hace PUT con `{"status": 2}`  
**Entonces** el procedimiento queda inactivo (soft delete)

### Escenario 11: Actualizar procedimiento de otra notaría
**Dado** un usuario autenticado y un procedimiento de OTRA notaría  
**Cuando** hace PUT a `/api/v3/payment-procedures/{id}`  
**Entonces** recibe código 404

### Escenario 12: Token inválido o ausente
**Dado** una petición sin token o con token inválido/expirado  
**Cuando** accede a cualquier endpoint de payment-procedures  
**Entonces** recibe código 401 Unauthorized

### Escenario 13: Actualizar con nombre duplicado
**Dado** procedimientos "Compraventa" (id=1) y "Donación" (id=2) en la misma notaría  
**Cuando** hace PUT a `/api/v3/payment-procedures/2` con `{"procedureName": "Compraventa"}`  
**Entonces** recibe código 409 Conflict

## No Funcionales

- **Rendimiento**: Las consultas deben usar índice en `(id_notary, id_status)`
- **Seguridad**: Todas las queries filtran por `id_notary` del token JWT
- **Consistencia**: Respuestas siguen el mismo formato que otros catálogos (payment-taxes, acts, etc.)

## Validaciones

| Campo         | Regla                                      |
|---------------|-------------------------------------------|
| procedureName | Requerido, max 500 chars, trim aplicado   |
| procedureName | Único por notaría (case-insensitive)      |
| status        | Opcional en POST (default 1), valores 1 o 2 |

## Casos Edge

- Nombre con solo espacios → rechazar como vacío
- Nombre con 500+ caracteres → rechazar con 400
- ID no numérico en ruta → 400 Bad Request
- ID numérico pero no existe → 404 Not Found
- Filtro status con valor inválido (ej: 3) → ignorar filtro o 400

## Criterios de Rechazo

- Permite acceso a procedimientos de otra notaría
- No valida unicidad de nombre
- No aplica trim al nombre
- Expone campos internos (created_at, modified_at, notaryId)
- Permite crear/actualizar sin token válido
