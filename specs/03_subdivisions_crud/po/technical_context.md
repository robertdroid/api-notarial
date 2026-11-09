# Contexto Técnico

## Entidades Involucradas

### Tabla: `fraccionamientos`
| Columna (BD) | Tipo | Mapeo API | Descripción |
|--------------|------|-----------|-------------|
| id_fraccionamiento | INT (PK, AUTO) | subdivisionId | Identificador único |
| id_notaria | INT (FK) | notaryId | Notaría propietaria |
| nombre_fraccionamiento | VARCHAR(500) | name | Nombre del fraccionamiento |
| id_usuario | INT (FK) | userId | Usuario que modificó |
| fecha_modificacion | DATETIME | modificationDate | Fecha última modificación |
| id_estatus | INT | status | 1=activo, 2=inactivo |

### Relaciones
- `fraccionamientos.id_notaria` → `notaria.id_notaria`
- `fraccionamientos.id_usuario` → `usuarios.id_usuario`
- `escrituras.id_fraccionamiento` → `fraccionamientos.id_fraccionamiento` (usado por escrituras)

## Endpoints

### GET /api/v3/subdivisions
**Descripción:** Listar fraccionamientos de la notaría del usuario autenticado

**Query Parameters:**
| Param | Tipo | Descripción |
|-------|------|-------------|
| status | integer | Filtrar por estatus (1 o 2) |
| name | string | Búsqueda parcial por nombre (case-insensitive) |

**Response 200:**
```json
[
  {
    "subdivisionId": 1,
    "notaryId": 1,
    "name": "Residencial Centro",
    "userId": 5,
    "modificationDate": "2024-01-15T10:30:00",
    "status": 1
  }
]
```

### GET /api/v3/subdivisions/{id}
**Descripción:** Obtener un fraccionamiento por ID

**Path Parameters:**
| Param | Tipo | Descripción |
|-------|------|-------------|
| id | integer | ID del fraccionamiento |

**Response 200:**
```json
{
  "subdivisionId": 1,
  "notaryId": 1,
  "name": "Residencial Centro",
  "userId": 5,
  "modificationDate": "2024-01-15T10:30:00",
  "status": 1
}
```

**Response 404:**
```json
{
  "error": "Subdivision not found"
}
```

### POST /api/v3/subdivisions
**Descripción:** Crear nuevo fraccionamiento

**Request Body:**
```json
{
  "name": "string (requerido, max 500)",
  "status": 1
}
```

**Response 201:** Sin body

**Response 400:**
```json
{
  "error": "Validation failed",
  "details": {
    "name": "Subdivision name is required"
  }
}
```

### PUT /api/v3/subdivisions/{id}
**Descripción:** Actualizar fraccionamiento existente

**Request Body:**
```json
{
  "name": "string (requerido, max 500)",
  "status": 1
}
```

**Response 204:** Sin body

**Response 400/404:** Similar a otros endpoints

## Dependencias

- **Auth Module**: Validación de JWT y extracción de `notaryId` y `userId`
- **Core/Database**: Conexión a base de datos
- **Core/Http**: Request, Response, Router
- **Core/Middleware**: AuthMiddleware

## Impacto en Sistema Existente

- Agregar rutas en `src/Config/routes.php`
- No requiere migraciones (tabla ya existe)
- Debe seguir patrones establecidos en Act module

## Estructura de Archivos a Crear

```
src/Module/Subdivision/
├── Controller/
│   └── SubdivisionController.php
├── Service/
│   └── SubdivisionService.php
├── Repository/
│   └── SubdivisionRepository.php
├── Entity/
│   └── Subdivision.php
└── DTO/
    ├── CreateSubdivisionRequest.php
    ├── UpdateSubdivisionRequest.php
    └── SubdivisionResponse.php
```

## Consideraciones de Datos

- La tabla `fraccionamientos` ya existe con datos de producción
- No se requieren migraciones
- El campo `fecha_modificacion` debe actualizarse automáticamente en cada PUT
- El campo `id_usuario` debe tomarse del token JWT
- A diferencia de Acts, Subdivisions NO tiene campo de descripción

## Notas para Implementación

1. **Multi-tenancy**: TODAS las queries deben filtrar por `id_notaria` del token
2. **Auditoría**: El `id_usuario` se obtiene del JWT, no del request body
3. **Unicidad**: Validar `nombre_fraccionamiento` único POR notaría, no globalmente
4. **Trim**: Aplicar trim() al nombre antes de validar unicidad
5. **Búsqueda**: Usar LIKE con wildcards para búsqueda parcial
6. **Formato fecha**: Usar ISO 8601 en respuestas (2024-01-15T10:30:00)
7. **SQL Injection**: Usar prepared statements para todos los queries
8. **Patrón a seguir**: Usar el módulo Act como referencia para estructura y patrones
