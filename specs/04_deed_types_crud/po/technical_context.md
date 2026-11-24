# Contexto Técnico

## Entidades Involucradas

### Tabla: `tipo_escritura`
| Columna (BD) | Tipo | Mapeo API | Descripción |
|--------------|------|-----------|-------------|
| id_tipo_escitura | INT (PK, AUTO) | deedTypeId | Identificador único (nota: typo en BD es legacy) |
| id_notaria | INT (FK) | notaryId | Notaría propietaria |
| nombre_tipo | VARCHAR(200) | name | Nombre del tipo |
| descripcion_tipo | VARCHAR(500) | description | Descripción del tipo |
| max_inscripciones | INT | maxInscriptions | Máximo de inscripciones permitidas (default 1) |
| activar_fraccionamientos | INT | enableSubdivisions | Flag para habilitar fraccionamientos (0/1) |
| id_usuario | INT (FK) | userId | Usuario que modificó |
| fecha_modificacion | DATETIME | modificationDate | Fecha última modificación |
| id_estatus | INT | status | 1=activo, 2=inactivo |

### Nota sobre typo en BD
El campo `id_tipo_escitura` tiene un error ortográfico (falta la 'r' en escritura). Se mantiene así por compatibilidad con sistema legacy. En la API se mapea correctamente a `deedTypeId`.

### Relaciones
- `tipo_escritura.id_notaria` → `notaria.id_notaria`
- `tipo_escritura.id_usuario` → `usuarios.id_usuario`
- `escrituras.id_tipo_escritura` → `tipo_escritura.id_tipo_escitura` (usado por escrituras)

## Endpoints

### GET /api/v3/deed-types
**Descripción:** Listar tipos de escritura de la notaría del usuario autenticado

**Query Parameters:**
| Param | Tipo | Descripción |
|-------|------|-------------|
| status | integer | Filtrar por estatus (1 o 2) |
| name | string | Búsqueda parcial por nombre (case-insensitive) |

**Response 200:**
```json
[
  {
    "deedTypeId": 1,
    "notaryId": 1,
    "name": "Actuales",
    "description": "Actuales",
    "maxInscriptions": 5,
    "enableSubdivisions": false,
    "userId": 7,
    "modificationDate": "2022-02-23T13:05:36",
    "status": 1
  }
]
```

### GET /api/v3/deed-types/{id}
**Descripción:** Obtener un tipo de escritura por ID

**Path Parameters:**
| Param | Tipo | Descripción |
|-------|------|-------------|
| id | integer | ID del tipo de escritura |

**Response 200:**
```json
{
  "deedTypeId": 1,
  "notaryId": 1,
  "name": "Actuales",
  "description": "Actuales",
  "maxInscriptions": 5,
  "enableSubdivisions": false,
  "userId": 7,
  "modificationDate": "2022-02-23T13:05:36",
  "status": 1
}
```

**Response 404:**
```json
{
  "error": "Deed type not found"
}
```

### POST /api/v3/deed-types
**Descripción:** Crear nuevo tipo de escritura

**Request Body:**
```json
{
  "name": "string (requerido, max 200)",
  "description": "string (requerido, max 500)",
  "maxInscriptions": 1,
  "enableSubdivisions": false,
  "status": 1
}
```

**Response 201:** Sin body

**Response 400:**
```json
{
  "error": "Validation failed",
  "details": {
    "name": "Deed type name is required"
  }
}
```

### PUT /api/v3/deed-types/{id}
**Descripción:** Actualizar tipo de escritura existente

**Request Body:**
```json
{
  "name": "string (requerido, max 200)",
  "description": "string (requerido, max 500)",
  "maxInscriptions": 1,
  "enableSubdivisions": false,
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
- Debe seguir patrones establecidos en Act y Subdivision modules

## Estructura de Archivos a Crear

```
src/Module/DeedType/
├── Controller/
│   └── DeedTypeController.php
├── Service/
│   └── DeedTypeService.php
├── Repository/
│   └── DeedTypeRepository.php
├── Entity/
│   └── DeedType.php
└── DTO/
    ├── CreateDeedTypeRequest.php
    ├── UpdateDeedTypeRequest.php
    └── DeedTypeResponse.php
```

## Consideraciones de Datos

- La tabla `tipo_escritura` ya existe con datos de producción
- No se requieren migraciones
- El campo `fecha_modificacion` debe actualizarse automáticamente en cada PUT
- El campo `id_usuario` debe tomarse del token JWT
- El campo `enableSubdivisions` se almacena como INT (0/1) pero se expone como boolean en API

## Notas para Implementación

1. **Multi-tenancy**: TODAS las queries deben filtrar por `id_notaria` del token
2. **Auditoría**: El `id_usuario` se obtiene del JWT, no del request body
3. **Unicidad**: Validar `nombre_tipo` único POR notaría, no globalmente
4. **Trim**: Aplicar trim() al nombre antes de validar unicidad
5. **Búsqueda**: Usar LIKE con wildcards para búsqueda parcial
6. **Formato fecha**: Usar ISO 8601 en respuestas (2024-01-15T10:30:00)
7. **SQL Injection**: Usar prepared statements para todos los queries
8. **Typo en BD**: El campo PK es `id_tipo_escitura` (sin 'r'), mapearlo correctamente
9. **Boolean conversion**: `activar_fraccionamientos` (0/1) → `enableSubdivisions` (true/false)
10. **Patrón a seguir**: Usar el módulo Act y Subdivision como referencia
