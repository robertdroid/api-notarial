# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
Referencia: `specs/02_acts_crud/po/acceptance_criteria.md`

---

## Criterios por Capa

### Controller Layer

- [ ] `GET /api/v3/acts` implementado con middleware `auth`
- [ ] `GET /api/v3/acts/{id}` implementado con middleware `auth`
- [ ] `POST /api/v3/acts` implementado con middleware `auth`
- [ ] `PUT /api/v3/acts/{id}` implementado con middleware `auth`
- [ ] Query params `status` y `name` procesados en index()
- [ ] Route param `id` extraído correctamente
- [ ] Validación de `id` numérico (retorna 400 si inválido)
- [ ] Respuestas usan métodos estáticos de Response (json, created, noContent, badRequest, notFound)

### Service Layer

- [ ] `ActService` recibe `ActRepository` via constructor
- [ ] Método `getAll()` aplica filtros opcionales
- [ ] Método `getById()` retorna null si no existe o es de otra notaría
- [ ] Método `create()` valida nombre único antes de insertar
- [ ] Método `create()` aplica trim() al nombre
- [ ] Método `update()` valida que el acto exista y pertenezca a la notaría
- [ ] Método `update()` valida nombre único (excluyendo el registro actual)
- [ ] Método `update()` actualiza `fecha_modificacion` y `id_usuario`

### Repository Layer

- [ ] Todas las queries filtran por `id_notaria` (multi-tenant)
- [ ] `findAllByNotary()` soporta filtros opcionales (status, name)
- [ ] Búsqueda por nombre usa `LIKE` con wildcards (case-insensitive)
- [ ] `findByIdAndNotary()` retorna Entity o null
- [ ] `existsByNameAndNotary()` excluye registro actual en updates
- [ ] `create()` retorna el ID insertado
- [ ] `update()` actualiza campos incluyendo `fecha_modificacion`
- [ ] Todos los queries usan prepared statements

### Entity

- [ ] `Act` tiene propiedades tipadas para todos los campos
- [ ] Propiedades: id, notaryId, name, description, userId, modificationDate, statusId
- [ ] Constructor acepta datos de BD y mapea nombres de columnas
- [ ] Método `toArray()` o similar para serialización

### DTOs

- [ ] `CreateActRequest` valida: actName (required, max 200), actDescription (required, max 500), status (optional, default 1)
- [ ] `UpdateActRequest` valida: actName (required, max 200), actDescription (required, max 500), status (required, 1 or 2)
- [ ] `ActResponse` formatea salida con campos camelCase
- [ ] DTOs tienen método estático `fromRequest(array $data)` para construir desde body

---

## Validaciones de Negocio

| Validación | Capa | Comportamiento |
|------------|------|----------------|
| actName requerido | DTO | 400 + mensaje |
| actName max 200 chars | DTO | 400 + mensaje |
| actDescription requerido | DTO | 400 + mensaje |
| actDescription max 500 chars | DTO | 400 + mensaje |
| status solo 1 o 2 | DTO | 400 + mensaje |
| Nombre único por notaría | Service | 400 + "Act name already exists" |
| Acto existe en la notaría | Service | 404 + "Act not found" |
| ID es numérico | Controller | 400 + mensaje |

---

## Tests Requeridos

### Unit Tests (Service)
- [ ] `testGetAllReturnsActsForNotary`
- [ ] `testGetAllFiltersbyStatus`
- [ ] `testGetAllFiltersByName`
- [ ] `testGetByIdReturnsActWhenExists`
- [ ] `testGetByIdReturnsNullWhenNotExists`
- [ ] `testGetByIdReturnsNullWhenDifferentNotary`
- [ ] `testCreateSucceedsWithValidData`
- [ ] `testCreateFailsWhenNameExists`
- [ ] `testCreateTrimsName`
- [ ] `testUpdateSucceedsWithValidData`
- [ ] `testUpdateFailsWhenActNotFound`
- [ ] `testUpdateFailsWhenNameExistsOnOtherAct`

### Integration Tests (Repository)
- [ ] `testFindAllByNotaryReturnsOnlyNotaryActs`
- [ ] `testFindAllByNotaryFiltersCorrectly`
- [ ] `testFindByIdAndNotaryReturnsNullForOtherNotary`
- [ ] `testExistsByNameAndNotaryWorks`
- [ ] `testCreateInsertsCorrectly`
- [ ] `testUpdateModifiesCorrectly`

### Feature Tests (Endpoints)
- [ ] `testIndexReturnsActsWithAuth`
- [ ] `testIndexReturns401WithoutToken`
- [ ] `testShowReturnsActWhenExists`
- [ ] `testShowReturns404WhenNotExists`
- [ ] `testStoreCreatesActWithValidData`
- [ ] `testStoreReturns400WithDuplicateName`
- [ ] `testUpdateModifiesActWithValidData`
- [ ] `testUpdateReturns404WhenActNotInNotary`

---

## Criterios de Código

- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] Namespace correcto: `App\Module\Act\...`
- [ ] Propiedades tipadas (PHP 8.x)
- [ ] Return types en todos los métodos
- [ ] Sin `@var` annotations redundantes
- [ ] Imports organizados (Core primero, Module después)
- [ ] Sin código comentado
- [ ] Sin `echo`, `var_dump`, `print_r`

---

## Performance

- [ ] Query de listado usa índice en `id_notaria`
- [ ] Búsqueda por nombre no causa full table scan (índice en nombre)
- [ ] No hay N+1 queries (todo en una sola query)
- [ ] Sin llamadas redundantes a BD

---

## Seguridad

- [ ] JWT validado antes de acceder a cualquier endpoint
- [ ] `notaryId` extraído del token, nunca del request body
- [ ] `userId` para auditoría extraído del token
- [ ] Queries usan prepared statements (sin concatenación de strings)
- [ ] Mensajes de error no revelan estructura interna
- [ ] Actos de otra notaría retornan 404 (no 403)
