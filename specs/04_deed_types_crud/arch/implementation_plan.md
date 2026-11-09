# Plan de Implementación: CRUD Tipos de Escritura

## Resumen

Implementar el módulo CRUD para tipos de escritura siguiendo el patrón establecido en `Subdivision` module. La implementación incluye 4 endpoints protegidos con JWT, validación de unicidad por notaría, y campos adicionales específicos del dominio (`maxInscriptions`, `enableSubdivisions`).

## Arquitectura Propuesta

### Diagrama de Componentes

```
Request → Router → AuthMiddleware → DeedTypeController
                                           │
                                           ▼
                                    DeedTypeService
                                           │
                                           ▼
                                   DeedTypeRepository
                                           │
                                           ▼
                                    tipo_escritura (BD)
```

### Módulos Afectados

- [x] `Module/DeedType/` - Crear módulo completo (nuevo)
- [x] `Config/routes.php` - Agregar 4 rutas protegidas

## Tareas de Implementación

### Fase 1: Entidades y DTOs
Estimación: 30 minutos

#### 1.1 Crear Entity DeedType
- **Archivo:** `src/Module/DeedType/Entity/DeedType.php`
- **Tipo:** Crear
- **Descripción:** Entidad con propiedades readonly, método `fromRow()` para mapear desde BD. Incluye conversión de `activar_fraccionamientos` (0/1) a boolean.
- **Dependencias:** Ninguna

#### 1.2 Crear DTO CreateDeedTypeRequest
- **Archivo:** `src/Module/DeedType/DTO/CreateDeedTypeRequest.php`
- **Tipo:** Crear
- **Descripción:** DTO para POST con validación de name (requerido, max 200), description (requerido, max 500), maxInscriptions (opcional, default 1), enableSubdivisions (opcional, default false), status (opcional, default 1).
- **Dependencias:** Ninguna

#### 1.3 Crear DTO UpdateDeedTypeRequest
- **Archivo:** `src/Module/DeedType/DTO/UpdateDeedTypeRequest.php`
- **Tipo:** Crear
- **Descripción:** Similar a Create, pero para PUT. Mismas validaciones.
- **Dependencias:** Ninguna

#### 1.4 Crear DTO DeedTypeResponse
- **Archivo:** `src/Module/DeedType/DTO/DeedTypeResponse.php`
- **Tipo:** Crear
- **Descripción:** Transformador de Entity a array JSON con `fromEntity()` y `fromCollection()`. Convierte enableSubdivisions a boolean.
- **Dependencias:** Entity/DeedType

### Fase 2: Repository
Estimación: 20 minutos

#### 2.1 Crear DeedTypeRepository
- **Archivo:** `src/Module/DeedType/Repository/DeedTypeRepository.php`
- **Tipo:** Crear
- **Descripción:** Acceso a datos con métodos: `findAllByNotary()`, `findByIdAndNotary()`, `existsByNameAndNotary()`, `create()`, `update()`. **IMPORTANTE:** El PK en BD es `id_tipo_escitura` (typo legacy, sin 'r').
- **Dependencias:** Entity/DeedType, PDO

### Fase 3: Service
Estimación: 15 minutos

#### 3.1 Crear DeedTypeService
- **Archivo:** `src/Module/DeedType/Service/DeedTypeService.php`
- **Tipo:** Crear
- **Descripción:** Lógica de negocio: `getAll()`, `getById()`, `create()`, `update()`. Valida unicidad de nombre antes de crear/actualizar.
- **Dependencias:** Repository, DTOs

### Fase 4: Controller y Rutas
Estimación: 15 minutos

#### 4.1 Crear DeedTypeController
- **Archivo:** `src/Module/DeedType/Controller/DeedTypeController.php`
- **Tipo:** Crear
- **Descripción:** Endpoints `index()`, `show()`, `store()`, `update()`. Usa ServiceLocator para inyectar service.
- **Dependencias:** Service, DTOs, Response

#### 4.2 Registrar rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Agregar 4 rutas para /api/v3/deed-types con middleware auth
- **Dependencias:** DeedTypeController

### Fase 5: Integración con ServiceLocator
Estimación: 5 minutos

#### 5.1 Registrar servicios en container
- **Archivo:** `src/Core/Container/ServiceLocator.php`
- **Tipo:** Modificar
- **Descripción:** Registrar DeedTypeRepository y DeedTypeService en el contenedor
- **Dependencias:** Repository, Service

## Orden de Implementación

1. **Entity/DeedType.php** - Base para todo lo demás
2. **DTOs (Create, Update, Response)** - Dependen de Entity
3. **Repository/DeedTypeRepository.php** - Depende de Entity
4. **Service/DeedTypeService.php** - Depende de Repository y DTOs
5. **ServiceLocator.php** - Registrar dependencias
6. **Controller/DeedTypeController.php** - Depende de Service
7. **routes.php** - Activar endpoints

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Typo en PK `id_tipo_escitura` causa confusión | Media | Bajo | Documentar en código, usar constantes |
| Conversión boolean incorrecta | Baja | Medio | Tests unitarios para conversión 0/1 ↔ boolean |
| Validación maxInscriptions ≤ 0 | Baja | Bajo | Normalizar a 1 si ≤ 0 en DTO |

## Decisiones de Arquitectura

### ADR-001: Seguir patrón Subdivision exactamente
- **Contexto:** Existen 2 módulos similares (Act, Subdivision). Debemos elegir cuál seguir.
- **Decisión:** Seguir Subdivision porque tiene estructura más limpia (DTOs separados para Create/Update).
- **Consecuencias:** Código consistente, fácil de mantener.

### ADR-002: Conversión boolean en Response DTO (no en Entity)
- **Contexto:** `activar_fraccionamientos` es INT(0/1) en BD, pero debe ser boolean en API.
- **Decisión:** Entity mantiene `int`, Response DTO convierte a `bool`. Esto mantiene la Entity fiel a BD.
- **Consecuencias:** Entity refleja BD, la transformación es responsabilidad del DTO de salida.

### ADR-003: Normalización de maxInscriptions en DTO
- **Contexto:** maxInscriptions puede venir como 0, negativo, o null.
- **Decisión:** En CreateDeedTypeRequest, si es ≤ 0 o null, usar default 1.
- **Consecuencias:** API robusta, siempre valores válidos.
