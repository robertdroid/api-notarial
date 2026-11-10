# Criterios de Aceptación

## Funcionales

### Escenario 1: Listado sin búsqueda
**Dado** un usuario autenticado de la notaría  
**Cuando** hace GET a `/api/v3/deeds` sin parámetro de búsqueda  
**Entonces** recibe las primeras 100 escrituras ordenadas por número descendente (primero números, luego letras)

### Escenario 2: Listado con paginación
**Dado** un usuario autenticado y la notaría tiene más de 100 escrituras  
**Cuando** hace GET a `/api/v3/deeds?page=2`  
**Entonces** recibe las escrituras 101-200 con información de paginación completa

### Escenario 3: Búsqueda por número de escritura
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deeds?search=12345`  
**Entonces** recibe escrituras cuyo número contenga "12345"

### Escenario 4: Búsqueda por nombre de participante
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deeds?search=garcia`  
**Entonces** recibe escrituras donde algún enajenante (tipo 1) o adquiriente (tipo 2) tenga "garcia" en su nombre

### Escenario 5: Búsqueda con palabras en cualquier orden
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deeds?search=perez%20juan`  
**Entonces** recibe escrituras donde algún participante contenga tanto "perez" como "juan" en su nombre (sin importar el orden)

### Escenario 6: Búsqueda por nombre de acto
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deeds?search=compraventa`  
**Entonces** recibe escrituras cuyo acto contenga "compraventa" en su nombre

### Escenario 7: Búsqueda sin resultados
**Dado** un usuario autenticado  
**Cuando** hace GET a `/api/v3/deeds?search=xyz123nonexistent`  
**Entonces** recibe status 200 con `data: []` y `pagination.total: 0`

### Escenario 8: Página fuera de rango
**Dado** un usuario autenticado y la notaría tiene 50 escrituras  
**Cuando** hace GET a `/api/v3/deeds?page=99`  
**Entonces** recibe status 200 con `data: []` (lista vacía, no error 404)

### Escenario 9: Participantes eliminados no se buscan
**Dado** un participante con `id_estatus = 0` (eliminado) llamado "Juan Pérez"  
**Cuando** el usuario busca "juan perez"  
**Entonces** la escritura NO aparece por ese participante (sí puede aparecer por otro participante activo, número o acto)

> **Nota (arquitectura, ADR-004):** `escrituras` no tiene columna de soft delete; por decisión del usuario se listan todas las escrituras. El soft delete de escrituras se diseñará con el endpoint DELETE.

### Escenario 10: Multi-tenencia
**Dado** un usuario autenticado de la notaría A  
**Cuando** lista escrituras  
**Entonces** solo ve escrituras de la notaría A, nunca de otras notarías

## No Funcionales

- **Rendimiento**: La consulta debe responder en menos de 2 segundos para notarías con hasta 50,000 escrituras
- **Seguridad**: El `notary_id` se obtiene del token JWT, nunca del request
- **Consistencia**: El formato de respuesta sigue el estándar del API v3

## Validaciones

| Campo | Validación |
|-------|------------|
| `page` | Entero positivo, default 1. No numérico, `0` o negativo → 400 (ADR-007) |
| `search` | String, opcional, sin longitud mínima |

## Formato de Respuesta

```json
{
  "data": [
    {
      "id": 1,
      "deedNumber": "12345",
      "book": "LIBRO-2024",
      "deedDate": "2024-01-15",
      "actName": "Compraventa",
      "deedTypeName": "Poder Notarial",
      "isFinished": false
    }
  ],
  "pagination": {
    "page": 1,
    "perPage": 100,
    "total": 1500,
    "totalPages": 15
  }
}
```

## Casos Edge

- **Búsqueda vacía** (`search=`): se trata igual que sin parámetro search
- **Búsqueda con espacios múltiples**: "juan   perez" se normaliza a dos palabras
- **Caracteres especiales**: se escapan para evitar SQL injection
- **Acentos**: "perez" debe encontrar "Pérez" (búsqueda case-insensitive, idealmente accent-insensitive según collation de BD)
- **Escritura sin participantes**: aparece en listado general y en búsquedas por número o acto, pero no por nombre de participante
- **Varias palabras**: todas deben coincidir en el mismo participante (junto con número/acto de la escritura); "juan perez" no coincide con "Juan López" + "María Pérez" en la misma escritura (ADR-002)
- **Escritura sin acto asignado**: aparece con `actName: null`

## Criterios de Rechazo

- Mostrar escrituras de otra notaría
- Encontrar escrituras por participantes eliminados (`id_estatus = 0`) o de tipo distinto a 1/2
- Devolver error 404 para página sin resultados
- No respetar el orden descendente por número de escritura
- Búsqueda sensible al orden de palabras (debe funcionar en cualquier orden)
