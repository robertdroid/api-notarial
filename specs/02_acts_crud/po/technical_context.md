# Contexto Técnico

## Entidades Involucradas

### Tabla: `actos_notaria`
| Columna (BD) | Tipo | Mapeo API | Descripción |
|--------------|------|-----------|-------------|
| id_acto | INT (PK, AUTO) | actId | Identificador único |
| id_notaria | INT (FK) | notaryId | Notaría propietaria |
| nombre_acto | VARCHAR(200) | actName | Nombre del acto |
| descripcion_acto | VARCHAR(500) | actDescription | Descripción del acto |
| id_usuario | INT (FK) | userId | Usuario que modificó |
| fecha_modificacion | DATETIME | modificationDate | Fecha última modificación |
| id_status | INT | statusId | 1=activo, 2=inactivo |

### Relaciones
- `actos_notaria.id_notaria` → `notaria.id_notaria`
- `actos_notaria.id_usuario` → `usuarios.id_usuario`

## Endpoints

### GET /api/v3/acts
**Descripción:** Listar actos de la notaría del usuario autenticado

**Query Parameters:**
| Param | Tipo | Descripción |
|-------|------|-------------|
| status | integer | Filtrar por estatus (1 o 2) |
| name | string | Búsqueda parcial por nombre (case-insensitive) |

**Response 200:**
```json
[
  {
    "actId": 1,
    "notaryId": 1,
    "actName": "Compraventa",
    "actDescription": "Acto de compraventa de inmuebles",
    "userId": 5,
    "modificationDate": "2024-01-15T10:30:00",
    "statusId": 1
  }
]
```

### GET /api/v3/acts/{id}
**Descripción:** Obtener un acto por ID

**Path Parameters:**
| Param | Tipo | Descripción |
|-------|------|-------------|
| id | integer | ID del acto |

**Response 200:**
```json
{
  "actId": 1,
  "notaryId": 1,
  "actName": "Compraventa",
  "actDescription": "Acto de compraventa de inmuebles",
  "userId": 5,
  "modificationDate": "2024-01-15T10:30:00",
  "statusId": 1
}
```

**Response 404:**
```json
{
  "error": "Act not found"
}
```

### POST /api/v3/acts
**Descripción:** Crear nuevo acto

**Request Body:**
```json
{
  "actName": "string (requerido, max 200)",
  "actDescription": "string (requerido, max 500)",
  "status": 1
}
```

**Response 201:** Sin body

**Response 400:**
```json
{
  "error": "Validation failed",
  "details": {
    "actName": "Act name is required"
  }
}
```

### PUT /api/v3/acts/{id}
**Descripción:** Actualizar acto existente

**Request Body:**
```json
{
  "actName": "string (requerido, max 200)",
  "actDescription": "string (requerido, max 500)",
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
- Debe seguir patrones establecidos en Auth module

## Estructura de Archivos a Crear

```
src/Module/Act/
├── Controller/
│   └── ActController.php
├── Service/
│   └── ActService.php
├── Repository/
│   └── ActRepository.php
├── Entity/
│   └── Act.php
└── DTO/
    ├── ActRequest.php
    └── ActResponse.php
```

## Consideraciones de Datos

- La tabla `actos_notaria` ya existe con datos de producción
- No se requieren migraciones
- El campo `fecha_modificacion` debe actualizarse automáticamente en cada PUT
- El campo `id_usuario` debe tomarse del token JWT

## Notas para Implementación

1. **Multi-tenancy**: TODAS las queries deben filtrar por `id_notaria` del token
2. **Auditoría**: El `id_usuario` se obtiene del JWT, no del request body
3. **Unicidad**: Validar `nombre_acto` único POR notaría, no globalmente
4. **Trim**: Aplicar trim() al nombre antes de validar unicidad
5. **Búsqueda**: Usar LIKE con wildcards para búsqueda parcial
6. **Formato fecha**: Usar ISO 8601 en respuestas (2024-01-15T10:30:00)
7. **SQL Injection**: Usar prepared statements para todos los queries
