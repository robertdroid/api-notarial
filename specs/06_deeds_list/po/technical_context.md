# Contexto Técnico

> ⚠️ **Reemplazado por `specs/06_deeds_list/arch/`.** Los nombres de tablas/columnas de este documento eran supuestos y varios no existen (p. ej. `tipos_escritura`, `escrituras.status`). Usar la tabla de nombres reales en `arch/developer_notes.md`.

## Entidades Involucradas

| Tabla | Uso |
|-------|-----|
| `escrituras` | Tabla principal de escrituras |
| `participantes_escritura` | Nombres de participantes (tipo 1=Enajenante, 2=Adquiriente) |
| `actos_notaria` | Catálogo de actos jurídicos por notaría |
| `tipos_escritura` | Catálogo de tipos de escritura por notaría |

### Campos relevantes de `escrituras`
- `id` - Identificador único
- `id_notaria` - FK a notaría (filtro multi-tenant)
- `num_escritura` - Número de la escritura (texto)
- `libro` - Libro donde está registrada
- `fecha` - Fecha de la escritura
- `id_acto` - FK al acto jurídico
- `id_tipo_escritura` - FK al tipo de escritura
- `terminada` - Bandera de escritura terminada (0/1)
- `status` - Soft delete (1=activo, 2=inactivo)

### Campos relevantes de `participantes_escritura`
- `id_escritura` - FK a escritura
- `nombre` - Nombre completo del participante
- `tipo_participante` - 1=Enajenante, 2=Adquiriente

## Endpoint

```
GET /api/v3/deeds
```

### Query Parameters
| Param | Tipo | Default | Descripción |
|-------|------|---------|-------------|
| `page` | int | 1 | Número de página |
| `search` | string | null | Texto de búsqueda |

### Headers Requeridos
- `Authorization: Bearer <token>`

## Dependencias

- **Módulo Auth**: Para validar JWT y extraer `notary_id`
- **Módulo Act**: Relación para obtener nombre del acto
- **Módulo DeedType**: Relación para obtener nombre del tipo

## Lógica de Búsqueda

### Algoritmo de búsqueda por palabras
1. Separar el texto de búsqueda en palabras (split por espacios)
2. Para cada palabra, verificar que aparezca en alguno de los campos buscables
3. Una escritura coincide si TODAS las palabras aparecen (AND entre palabras)
4. Los campos donde buscar son (OR entre campos):
   - `escrituras.num_escritura`
   - `participantes_escritura.nombre` (donde tipo IN (1,2))
   - `actos_notaria.nombre`

### Pseudocódigo SQL
```sql
SELECT DISTINCT e.id, e.num_escritura, e.libro, e.fecha, 
       a.nombre AS act_name, t.nombre AS deed_type_name, e.terminada
FROM escrituras e
LEFT JOIN actos_notaria a ON e.id_acto = a.id
LEFT JOIN tipos_escritura t ON e.id_tipo_escritura = t.id
LEFT JOIN participantes_escritura p ON e.id = p.id_escritura AND p.tipo_participante IN (1, 2)
WHERE e.id_notaria = :notaryId
  AND e.status = 1
  AND (
    -- Para cada palabra del search:
    (e.num_escritura LIKE '%palabra1%' OR p.nombre LIKE '%palabra1%' OR a.nombre LIKE '%palabra1%')
    AND
    (e.num_escritura LIKE '%palabra2%' OR p.nombre LIKE '%palabra2%' OR a.nombre LIKE '%palabra2%')
    -- ... más palabras
  )
ORDER BY 
  CAST(e.num_escritura AS UNSIGNED) DESC,  -- Números primero
  e.num_escritura DESC                      -- Luego alfabético
LIMIT 100 OFFSET 0
```

## Ordenamiento

El ordenamiento "primero números, luego letras, descendente" requiere:
1. Intentar convertir `num_escritura` a número para ordenar numéricamente
2. Fallback a orden alfabético para escrituras con letras
3. Todo en orden descendente

## Constantes Sugeridas

```php
// En Config o constantes del módulo Deed
const DEEDS_PER_PAGE = 100;
```

## Impacto en Sistema Existente

- **Nuevo módulo**: Crear `src/Module/Deed/` si no existe
- **Router**: Agregar ruta `GET /api/v3/deeds`
- **Sin migraciones**: No requiere cambios en base de datos

## Consideraciones de Rendimiento

- Índices recomendados en `escrituras`:
  - `(id_notaria, status)` - Filtro base
  - `(id_notaria, num_escritura)` - Ordenamiento
- Índice en `participantes_escritura`:
  - `(id_escritura, tipo_participante)` - JOIN y filtro de tipo
- La búsqueda con múltiples LIKE puede ser costosa; considerar FULLTEXT para futuras optimizaciones

## Notas para Implementación

1. **Sanitización**: Escapar caracteres especiales en el search para prevenir SQL injection
2. **Collation**: La BD debe usar collation case-insensitive (utf8mb4_general_ci o similar) para búsquedas
3. **Límite de palabras**: Considerar limitar a máximo 5-10 palabras de búsqueda para evitar queries muy complejos
4. **Palabra mínima**: Opcionalmente ignorar palabras de 1-2 caracteres para mejorar relevancia
5. **Mapeo de campos**: 
   - `num_escritura` → `deedNumber`
   - `libro` → `book`
   - `fecha` → `deedDate`
   - `acto.nombre` → `actName`
   - `tipo.nombre` → `deedTypeName`
   - `terminada` → `isFinished` (convertir 0/1 a boolean)
