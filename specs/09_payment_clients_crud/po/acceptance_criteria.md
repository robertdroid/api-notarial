# Criterios de Aceptación

## Funcionales

### Escenario 1: Listar clientes
**Dado** un usuario autenticado con token JWT válido  
**Cuando** hace GET a `/api/v3/payment-clients`  
**Entonces** recibe lista de clientes de su notaría con código 200

### Escenario 2: Listar con filtro de status
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/payment-clients?status=1`  
**Entonces** recibe solo clientes activos de su notaría

### Escenario 3: Listar con filtro de nombre
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/payment-clients?name=lopez`  
**Entonces** recibe clientes cuyo nombre contiene "lopez" (case-insensitive)

### Escenario 4: Obtener cliente por ID
**Dado** un usuario autenticado y un cliente existente de su notaría  
**Cuando** hace GET a `/api/v3/payment-clients/{id}`  
**Entonces** recibe el cliente con código 200

### Escenario 5: Obtener cliente de otra notaría
**Dado** un usuario autenticado y un cliente de OTRA notaría  
**Cuando** hace GET a `/api/v3/payment-clients/{id}`  
**Entonces** recibe código 404 (no exponer que existe en otra notaría)

### Escenario 6: Crear cliente con todos los campos
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-clients` con `{"fullName": "Juan Pérez", "phone": "555-1234", "email": "juan@example.com"}`  
**Entonces** se crea el cliente asociado a su notaría con status=1 y recibe código 201

### Escenario 7: Crear cliente solo con nombre
**Dado** un usuario autenticado  
**Cuando** hace POST a `/api/v3/payment-clients` con `{"fullName": "María García"}`  
**Entonces** se crea el cliente con phone="" y email="" y recibe código 201

### Escenario 8: Crear cliente con nombre vacío
**Dado** un usuario autenticado  
**Cuando** hace POST con `{"fullName": ""}` o sin el campo  
**Entonces** recibe código 400 Bad Request

### Escenario 9: Crear cliente con email inválido
**Dado** un usuario autenticado  
**Cuando** hace POST con `{"fullName": "Test", "email": "no-es-email"}`  
**Entonces** recibe código 400 Bad Request con mensaje de error de formato

### Escenario 10: Crear cliente con nombre duplicado (permitido)
**Dado** un usuario autenticado y un cliente existente "Juan Pérez"  
**Cuando** hace POST con `{"fullName": "Juan Pérez"}`  
**Entonces** se crea otro cliente con el mismo nombre y recibe código 201

### Escenario 11: Actualizar cliente
**Dado** un usuario autenticado y un cliente existente de su notaría  
**Cuando** hace PUT a `/api/v3/payment-clients/{id}` con nuevos datos  
**Entonces** se actualiza el cliente y recibe código 200

### Escenario 12: Desactivar cliente (soft delete)
**Dado** un usuario autenticado y un cliente activo de su notaría  
**Cuando** hace PUT con `{"status": 2}`  
**Entonces** el cliente queda inactivo (soft delete)

### Escenario 13: Actualizar cliente de otra notaría
**Dado** un usuario autenticado y un cliente de OTRA notaría  
**Cuando** hace PUT a `/api/v3/payment-clients/{id}`  
**Entonces** recibe código 404

### Escenario 14: Token inválido o ausente
**Dado** una petición sin token o con token inválido/expirado  
**Cuando** accede a cualquier endpoint de payment-clients  
**Entonces** recibe código 401 Unauthorized

## No Funcionales

- **Rendimiento**: Las consultas deben usar índice en `(id_notary, id_status)`
- **Seguridad**: Todas las queries filtran por `id_notary` del token JWT
- **Consistencia**: Respuestas siguen el mismo formato que otros catálogos

## Validaciones

| Campo    | Regla                                                      |
|----------|-----------------------------------------------------------|
| fullName | Requerido, max 250 chars, trim aplicado                   |
| phone    | Opcional, max 20 chars, trim aplicado                     |
| email    | Opcional, max 250 chars, trim, formato válido si no vacío |
| status   | Opcional en POST (default 1), valores 1 o 2               |

## Casos Edge

- Nombre con solo espacios → rechazar como vacío
- Nombre con 250+ caracteres → rechazar con 400
- Email con solo espacios → tratar como vacío (válido)
- Phone con 20+ caracteres → rechazar con 400
- ID no numérico en ruta → 400 Bad Request
- ID numérico pero no existe → 404 Not Found
- Filtro status con valor inválido (ej: 3) → ignorar filtro o 400

## Criterios de Rechazo

- Permite acceso a clientes de otra notaría
- No aplica trim a los campos de texto
- Expone campos internos (created_at, modified_at, notaryId)
- Permite crear/actualizar sin token válido
- Acepta email con formato inválido cuando tiene valor
