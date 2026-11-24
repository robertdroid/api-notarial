# Plan de Implementación: CRUD de Fraccionamientos (Subdivisions)

## Resumen

Implementar módulo CRUD completo para fraccionamientos siguiendo el patrón establecido en el módulo Act. El módulo es más simple que Act ya que **no tiene campo de descripción**. Solo maneja nombre y estatus.

## Arquitectura Propuesta

### Diagrama de Componentes

```
Request → Router → AuthMiddleware → SubdivisionController
                                           ↓
                                    SubdivisionService
                                           ↓
                                   SubdivisionRepository → MariaDB (fraccionamientos)
                                           ↓
Response ← SubdivisionController ← SubdivisionService
```

### Módulos Afectados

- [x] `Module/Subdivision/` - Crear módulo completo (nuevo)
- [x] `Config/routes.php` - Agregar rutas de subdivisions

## Tareas de Implementación

### Fase 1: Capa de Entidad y Mapeo
Estimación: 30 minutos

#### 1.1 Crear Entity Subdivision
- **Archivo:** `src/Module/Subdivision/Entity/Subdivision.php`
- **Tipo:** Crear
- **Descripción:** Entidad inmutable con propiedades tipadas. Incluye método `fromRow()` para mapear columnas BD (español) a propiedades (inglés).
- **Dependencias:** Ninguna

**Mapeo de columnas:**
| BD (español) | Entity (inglés) |
|--------------|-----------------|
| id_fraccionamiento | id |
| id_notaria | notaryId |
| nombre_fraccionamiento | name |
| id_usuario | userId |
| fecha_modificacion | modificationDate |
| id_estatus | statusId |

### Fase 2: Capa de Repositorio
Estimación: 45 minutos

#### 2.1 Crear SubdivisionRepository
- **Archivo:** `src/Module/Subdivision/Repository/SubdivisionRepository.php`
- **Tipo:** Crear
- **Descripción:** Acceso a datos con prepared statements. Todos los queries filtran por `id_notaria`.
- **Dependencias:** Entity/Subdivision

**Métodos:**
- `findAllByNotary(int $notaryId, ?int $status, ?string $name): array`
- `findByIdAndNotary(int $id, int $notaryId): ?Subdivision`
- `existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool`
- `create(Subdivision $subdivision): int`
- `update(Subdivision $subdivision): void`

### Fase 3: Capa de DTOs
Estimación: 30 minutos

#### 3.1 Crear CreateSubdivisionRequest
- **Archivo:** `src/Module/Subdivision/DTO/CreateSubdivisionRequest.php`
- **Tipo:** Crear
- **Descripción:** DTO para validar datos de creación. Solo `name` (requerido, max 500 chars) y `status` (opcional, default 1).
- **Dependencias:** Ninguna

**Diferencia con Act:** NO tiene campo `description`.

#### 3.2 Crear UpdateSubdivisionRequest
- **Archivo:** `src/Module/Subdivision/DTO/UpdateSubdivisionRequest.php`
- **Tipo:** Crear
- **Descripción:** DTO para validar datos de actualización. Campos `name` y `status` requeridos.
- **Dependencias:** Ninguna

#### 3.3 Crear SubdivisionResponse
- **Archivo:** `src/Module/Subdivision/DTO/SubdivisionResponse.php`
- **Tipo:** Crear
- **Descripción:** DTO para transformar Entity a respuesta JSON con campos en camelCase.
- **Dependencias:** Entity/Subdivision

**Campos de respuesta:**
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

### Fase 4: Capa de Servicio
Estimación: 30 minutos

#### 4.1 Crear SubdivisionService
- **Archivo:** `src/Module/Subdivision/Service/SubdivisionService.php`
- **Tipo:** Crear
- **Descripción:** Lógica de negocio: validación de nombre único (con trim), coordinación entre Controller y Repository.
- **Dependencias:** Repository, DTOs, Entity

**Métodos:**
- `getAll(int $notaryId, ?int $status, ?string $name): array`
- `getById(int $id, int $notaryId): ?Subdivision`
- `create(CreateSubdivisionRequest $request, int $notaryId, int $userId): bool|string`
- `update(int $id, UpdateSubdivisionRequest $request, int $notaryId, int $userId): bool|string|null`

### Fase 5: Capa de Controlador
Estimación: 30 minutos

#### 5.1 Crear SubdivisionController
- **Archivo:** `src/Module/Subdivision/Controller/SubdivisionController.php`
- **Tipo:** Crear
- **Descripción:** Endpoints REST. Manejo de request/response HTTP, validación de parámetros, códigos de estado correctos.
- **Dependencias:** Service, DTOs, Request, Response

**Métodos:**
- `index(Request $request): Response` - GET /subdivisions
- `show(Request $request): Response` - GET /subdivisions/{id}
- `store(Request $request): Response` - POST /subdivisions
- `update(Request $request): Response` - PUT /subdivisions/{id}

### Fase 6: Configuración de Rutas
Estimación: 10 minutos

#### 6.1 Agregar rutas en routes.php
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Registrar las 4 rutas de subdivisions con middleware de autenticación.
- **Dependencias:** Controller creado

```php
// Subdivisions routes (protected)
$router->get('/api/v3/subdivisions', [SubdivisionController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/subdivisions/{id}', [SubdivisionController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/subdivisions', [SubdivisionController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/subdivisions/{id}', [SubdivisionController::class, 'update'])->withMiddleware('auth');
```

## Orden de Implementación

1. **Entity/Subdivision.php** - Base del módulo, sin dependencias
2. **Repository/SubdivisionRepository.php** - Depende de Entity
3. **DTO/CreateSubdivisionRequest.php** - Sin dependencias
4. **DTO/UpdateSubdivisionRequest.php** - Sin dependencias
5. **DTO/SubdivisionResponse.php** - Depende de Entity
6. **Service/SubdivisionService.php** - Depende de Repository, DTOs
7. **Controller/SubdivisionController.php** - Depende de Service
8. **routes.php** - Depende de Controller

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Inconsistencia con patrón Act | Baja | Medio | Usar Act como template literal, adaptar solo diferencias |
| Nombre no único detectado tarde | Baja | Bajo | Validar en Service antes de INSERT/UPDATE |
| SQL Injection | Muy Baja | Alto | Usar SOLO prepared statements con parámetros |
| Fuga de datos cross-tenant | Baja | Alto | TODOS los queries filtran por notary_id |

## Decisiones de Arquitectura

### ADR-001: Sin campo descripción
- **Contexto:** La tabla `fraccionamientos` no tiene campo de descripción como `actos_notaria`
- **Decisión:** El DTO y Entity solo manejan `name` y `status`
- **Consecuencias:** DTOs más simples, menos validaciones

### ADR-002: Response con subdivisionId vs id
- **Contexto:** El PO especifica `subdivisionId` en el JSON de respuesta, no solo `id`
- **Decisión:** Usar `subdivisionId` para consistencia con documentación del PO
- **Consecuencias:** El Response DTO mapea `id` → `subdivisionId`

### ADR-003: Formato de fecha ISO 8601
- **Contexto:** El PO especifica formato `2024-01-15T10:30:00`
- **Decisión:** Usar formato `Y-m-d\TH:i:s` en el Response (incluye T literal)
- **Consecuencias:** Difiere de Act que usa `Y-m-d H:i:s`. Mantener consistencia con spec del PO.

## Estimación Total

| Fase | Tiempo |
|------|--------|
| Fase 1: Entity | 30 min |
| Fase 2: Repository | 45 min |
| Fase 3: DTOs | 30 min |
| Fase 4: Service | 30 min |
| Fase 5: Controller | 30 min |
| Fase 6: Routes | 10 min |
| **Total** | **~3 horas** |
