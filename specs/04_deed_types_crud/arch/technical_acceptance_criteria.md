# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
Referencia: `specs/04_deed_types_crud/po/acceptance_criteria.md`

## Criterios por Capa

### Controller Layer

- [x] Endpoint `GET /api/v3/deed-types` implementado
- [x] Endpoint `GET /api/v3/deed-types/{id}` implementado
- [x] Endpoint `POST /api/v3/deed-types` implementado
- [x] Endpoint `PUT /api/v3/deed-types/{id}` implementado
- [x] Validación de query params (status: 1|2, name: string)
- [x] Validación de path param ID numérico
- [x] Validación de request body vía DTOs
- [x] Códigos HTTP correctos: 200, 201, 204, 400, 401, 404
- [x] Respuestas JSON en formato estándar

### Service Layer

- [x] Método `getAll(notaryId, ?status, ?name): array`
- [x] Método `getById(id, notaryId): ?DeedType`
- [x] Método `create(dto, notaryId, userId): bool|string`
- [x] Método `update(id, dto, notaryId, userId): bool|string|null`
- [x] Validación de nombre único por notaría (case-insensitive)
- [x] Trim del nombre antes de validar unicidad
- [x] Retorna mensaje de error específico si nombre duplicado

### Repository Layer

- [x] Método `findAllByNotary(notaryId, ?status, ?name): array`
- [x] Método `findByIdAndNotary(id, notaryId): ?DeedType`
- [x] Método `existsByNameAndNotary(name, notaryId, ?excludeId): bool`
- [x] Método `create(DeedType): int`
- [x] Método `update(DeedType): void`
- [x] TODAS las queries filtran por `id_notaria` (multi-tenant)
- [x] Búsqueda por nombre usa `LOWER()` y `LIKE` con wildcards
- [x] Prepared statements en todos los queries

### Entity/DTO

- [x] Entity `DeedType` con propiedades readonly tipadas
- [x] Entity tiene `fromRow(array): DeedType` para mapear desde BD
- [x] Entity maneja typo `id_tipo_escitura` correctamente
- [x] `CreateDeedTypeRequest` con validaciones
- [x] `UpdateDeedTypeRequest` con validaciones
- [x] `DeedTypeResponse::fromEntity()` convierte a JSON
- [x] `DeedTypeResponse::fromCollection()` para arrays
- [x] Conversión `enableSubdivisions`: INT(0/1) → boolean en Response

## Validaciones en DTOs

| Campo | CreateDeedTypeRequest | UpdateDeedTypeRequest |
|-------|----------------------|----------------------|
| name | Requerido, max 200 chars | Requerido, max 200 chars |
| description | Requerido, max 500 chars | Requerido, max 500 chars |
| maxInscriptions | Opcional, int > 0, default 1 | Opcional, int > 0, default 1 |
| enableSubdivisions | Opcional, bool, default false | Opcional, bool, default false |
| status | Opcional, 1 o 2, default 1 | Requerido, 1 o 2 |

## Mapeo BD → API

| Columna BD | Campo API | Transformación |
|------------|-----------|----------------|
| id_tipo_escitura | deedTypeId | (int) |
| id_notaria | notaryId | (int) |
| nombre_tipo | name | (string) |
| descripcion_tipo | description | (string) |
| max_inscripciones | maxInscriptions | (int) |
| activar_fraccionamientos | enableSubdivisions | (int) → (bool) |
| id_usuario | userId | (int) |
| fecha_modificacion | modificationDate | DateTime → ISO 8601 |
| id_estatus | status | (int) |

## Tests Requeridos

### Unit Tests (Service)
- [ ] `testGetAllReturnsOnlyNotaryData`
- [ ] `testGetByIdReturnsNullForOtherNotary`
- [ ] `testCreateFailsWithDuplicateName`
- [ ] `testCreateTrimsNameBeforeValidation`
- [ ] `testUpdateFailsIfNotExists`
- [ ] `testUpdateFailsWithDuplicateName`

### Integration Tests (Repository)
- [ ] `testFindAllByNotaryFiltersCorrectly`
- [ ] `testFindByIdAndNotaryRespectsMultiTenancy`
- [ ] `testExistsByNameIsCaseInsensitive`
- [ ] `testCreateInsertsCorrectly`
- [ ] `testUpdateModifiesCorrectly`

### Feature Tests (Endpoints)
- [ ] `testIndexReturns200WithArray`
- [ ] `testIndexFiltersbyStatus`
- [ ] `testIndexFiltersByName`
- [ ] `testShowReturns200ForValidId`
- [ ] `testShowReturns404ForInvalidId`
- [ ] `testShowReturns404ForOtherNotary`
- [ ] `testStoreReturns201OnSuccess`
- [ ] `testStoreReturns400OnDuplicateName`
- [ ] `testStoreReturns400OnValidationError`
- [ ] `testUpdateReturns204OnSuccess`
- [ ] `testUpdateReturns404ForOtherNotary`
- [ ] `testAllEndpointsReturn401WithoutToken`

## Criterios de Código

- [x] PSR-12 coding style
- [x] `declare(strict_types=1)` en todos los archivos
- [x] Typed properties y return types
- [x] Sin dependencias circulares
- [x] Principios SOLID aplicados
- [x] Namespace: `App\Module\DeedType\*`

## Performance

- [x] Queries usan índices de `id_notaria` y `id_tipo_escitura`
- [x] Sin N+1 queries (una sola query por operación)
- [x] Sin paginación requerida (catálogo pequeño)

## Seguridad

- [x] AuthMiddleware en todas las rutas
- [x] notaryId y userId extraídos del JWT, no del request
- [x] ID validado como numérico antes de usar
- [x] Prepared statements previenen SQL injection
- [x] 404 genérico para recursos de otra notaría (no revelar existencia)
