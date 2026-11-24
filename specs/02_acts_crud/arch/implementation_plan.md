# Plan de Implementación: CRUD de Actos Jurídicos

## Resumen

Implementar el módulo Act siguiendo la arquitectura establecida (Controller → Service → Repository) con soporte multi-tenant y validación JWT. Se reutilizan los patrones del módulo Auth existente.

## Arquitectura Propuesta

### Diagrama de Flujo

```
Request → Router → AuthMiddleware → ActController → ActService → ActRepository → DB
                        ↓                  ↓              ↓
                   Valida JWT         Valida datos    Filtra por
                   Extrae notaryId    Lógica negocio  notary_id
```

### Estructura del Módulo

```
src/Module/Act/
├── Controller/
│   └── ActController.php      # Endpoints HTTP
├── Service/
│   └── ActService.php         # Lógica de negocio
├── Repository/
│   └── ActRepository.php      # Acceso a datos
├── Entity/
│   └── Act.php                # Entidad de dominio
└── DTO/
    ├── CreateActRequest.php   # Validación de input (POST)
    ├── UpdateActRequest.php   # Validación de input (PUT)
    └── ActResponse.php        # Formato de output
```

## Tareas de Implementación

### Fase 1: Entity y DTOs
**Estimación: 1 hora**

#### 1.1 Crear Entity Act
- **Archivo:** `src/Module/Act/Entity/Act.php`
- **Tipo:** Crear
- **Descripción:** Entidad con propiedades tipadas que mapea la tabla `actos_notaria`

#### 1.2 Crear CreateActRequest DTO
- **Archivo:** `src/Module/Act/DTO/CreateActRequest.php`
- **Tipo:** Crear
- **Descripción:** DTO para validar datos del POST (actName, actDescription, status)

#### 1.3 Crear UpdateActRequest DTO
- **Archivo:** `src/Module/Act/DTO/UpdateActRequest.php`
- **Tipo:** Crear
- **Descripción:** DTO para validar datos del PUT

#### 1.4 Crear ActResponse DTO
- **Archivo:** `src/Module/Act/DTO/ActResponse.php`
- **Tipo:** Crear
- **Descripción:** DTO para formatear la respuesta JSON con campos camelCase

---

### Fase 2: Repository
**Estimación: 1.5 horas**

#### 2.1 Crear ActRepository
- **Archivo:** `src/Module/Act/Repository/ActRepository.php`
- **Tipo:** Crear
- **Descripción:** Métodos CRUD con filtro obligatorio por `notary_id`
- **Métodos:**
  - `findAllByNotary(int $notaryId, ?int $status, ?string $name): array`
  - `findByIdAndNotary(int $id, int $notaryId): ?Act`
  - `existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId): bool`
  - `create(Act $act): int`
  - `update(Act $act): void`

---

### Fase 3: Service
**Estimación: 1.5 horas**

#### 3.1 Crear ActService
- **Archivo:** `src/Module/Act/Service/ActService.php`
- **Tipo:** Crear
- **Descripción:** Lógica de negocio y validaciones de dominio
- **Métodos:**
  - `getAll(int $notaryId, ?int $status, ?string $name): array`
  - `getById(int $id, int $notaryId): ?Act`
  - `create(CreateActRequest $request, int $notaryId, int $userId): void`
  - `update(int $id, UpdateActRequest $request, int $notaryId, int $userId): bool`
- **Validaciones:**
  - Nombre único por notaría
  - Trim del nombre antes de guardar

---

### Fase 4: Controller
**Estimación: 1 hora**

#### 4.1 Crear ActController
- **Archivo:** `src/Module/Act/Controller/ActController.php`
- **Tipo:** Crear
- **Descripción:** Endpoints REST que delegan al Service
- **Métodos:**
  - `index(Request): Response` → GET /acts
  - `show(Request): Response` → GET /acts/{id}
  - `store(Request): Response` → POST /acts
  - `update(Request): Response` → PUT /acts/{id}

---

### Fase 5: Integración
**Estimación: 0.5 horas**

#### 5.1 Registrar rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Agregar rutas del módulo Act con middleware auth

#### 5.2 Registrar servicios
- **Archivo:** `public/index.php` (o donde se configura el ServiceLocator)
- **Tipo:** Modificar
- **Descripción:** Registrar ActRepository y ActService en el contenedor

---

### Fase 6: Documentación API
**Estimación: 0.5 horas**

#### 6.1 Actualizar OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Documentar los 4 endpoints del módulo Acts

---

## Orden de Implementación

1. **Entity/Act.php** - Base para todo el módulo
2. **DTOs** - Definen contratos de entrada/salida
3. **ActRepository.php** - Acceso a datos (se puede testear con BD)
4. **ActService.php** - Lógica de negocio (depende de Repository)
5. **ActController.php** - Endpoints HTTP (depende de Service)
6. **routes.php** - Habilitar rutas
7. **ServiceLocator** - Registrar dependencias
8. **openapi.yaml** - Documentación

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Inconsistencia con patrones Auth | Baja | Medio | Seguir exactamente la estructura de AuthController/AuthService |
| SQL Injection en búsqueda por nombre | Media | Alto | Usar prepared statements con LIKE y parámetros bound |
| Filtro de notary_id olvidado | Baja | Crítico | Siempre pasar notaryId como parámetro obligatorio en Repository |
| Nombres duplicados por race condition | Baja | Bajo | Índice único en BD (nombre + notaría) como respaldo |

## Decisiones de Arquitectura

### ADR-001: No usar interfaces para Repository
- **Contexto:** El módulo Auth no usa interfaces, solo clases concretas
- **Decisión:** Mantener consistencia, no crear interfaces por ahora
- **Consecuencias:** Menos abstracción pero código más simple y consistente

### ADR-002: Validación en DTOs vs Service
- **Contexto:** ¿Dónde validar los datos de entrada?
- **Decisión:** DTOs validan formato/tipo, Service valida reglas de negocio (unicidad)
- **Consecuencias:** Separación clara de responsabilidades

### ADR-003: Soft delete via PUT, no DELETE endpoint
- **Contexto:** El PO definió que no hay DELETE, solo cambio de status
- **Decisión:** No crear endpoint DELETE, desactivación es PUT con status=2
- **Consecuencias:** API más simple, datos nunca se pierden

### ADR-004: Response sin body para POST/PUT exitosos
- **Contexto:** API legacy retorna 201/204 sin body
- **Decisión:** Mantener comportamiento legacy (201 sin body, 204 sin body)
- **Consecuencias:** Consistencia con v1, cliente debe hacer GET si necesita datos
