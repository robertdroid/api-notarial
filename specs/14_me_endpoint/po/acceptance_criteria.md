# Criterios de Aceptación

## Funcionales

### Escenario 1: Consulta exitosa
**Dado** un usuario autenticado, activo, de una notaría activa
**Cuando** hace `GET /api/v3/me` con su token
**Entonces** recibe `200` con los objetos `user` y `notary` de su propia sesión

Response esperado:
```json
{
  "user": {
    "id": 2,
    "username": "maria.lopez",
    "firstName": "María Elena",
    "paternalLastName": "López",
    "maternalLastName": "Ramírez",
    "fullName": "María Elena López Ramírez",
    "access": ["ESC", "PAG"],
    "status": 1,
    "lastAccess": "2026-11-12T09:41:07"
  },
  "notary": {
    "id": 5,
    "name": "Notaría Pública No. 12",
    "notaryName": "Lic. Juan Pérez Gómez",
    "city": "Guadalajara",
    "address": "Av. Chapultepec 123, Col. Americana",
    "phone": "3312345678",
    "rfc": "PEGJ800101ABC",
    "hasLogo": true,
    "status": 1
  }
}
```

### Escenario 2: Sin token o token inválido
**Dado** una petición sin token, con token expirado o malformado
**Cuando** se hace `GET /api/v3/me`
**Entonces** recibe `401`

### Escenario 3: Usuario inactivo después de emitir el token
**Dado** un token válido cuyo usuario fue desactivado después del login
**Cuando** se hace `GET /api/v3/me`
**Entonces** recibe `403` y no se devuelve ningún dato

### Escenario 4: Notaría inactiva después de emitir el token
**Dado** un token válido cuya notaría fue desactivada después del login
**Cuando** se hace `GET /api/v3/me`
**Entonces** recibe `403` y no se devuelve ningún dato

### Escenario 5: Usuario sin apellido materno
**Dado** un usuario con `maternalLastName` vacío
**Cuando** se hace `GET /api/v3/me`
**Entonces** `fullName` no contiene espacios sobrantes (ej. `"María López"`)

### Escenario 6: Notaría sin logo
**Dado** una notaría sin logo cargado
**Cuando** se hace `GET /api/v3/me`
**Entonces** `notary.hasLogo` es `false`

### Escenario 7: Usuario nunca ha accedido
**Dado** un usuario sin registro de último acceso
**Cuando** se hace `GET /api/v3/me`
**Entonces** `user.lastAccess` es `null`

## No Funcionales
- Una sola consulta o dos consultas simples; sin N+1
- Respuesta en menos de 200 ms en condiciones normales
- Campos en inglés y `camelCase`

## Validaciones
- El endpoint no acepta parámetros: cualquier `id` en ruta, query o body se ignora
- `userId` y `notaryId` salen únicamente del token
- Solo método `GET`; otros métodos responden `405`

## Casos Edge
- Usuario eliminado después del login: `404`
- Usuario cuyo `notaryId` del token ya no coincide con su notaría en BD: `403`
- Código de acceso sin lista de permisos: `access` es `[]`
- Estado legacy `0` o `NULL` en usuario: se normaliza a `2` (inactivo), igual que `/users`

## Criterios de Rechazo
- El response incluye password, hash, nombre del archivo del logo o `notaryId` dentro de `user`
- Se puede consultar a otro usuario u otra notaría manipulando parámetros
- Se devuelven datos con usuario o notaría inactivos
