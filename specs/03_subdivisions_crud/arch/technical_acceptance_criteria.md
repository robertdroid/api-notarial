# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
Referencia: `specs/03_subdivisions_crud/po/acceptance_criteria.md`

## Criterios por Capa

### Controller Layer

- [ ] Endpoint `GET /api/v3/subdivisions` implementado
  - [ ] Acepta query params: `status` (1|2), `name` (string)
  - [ ] Retorna 200 con array de subdivisions
  - [ ] Valida que `status` sea 1 o 2 si se proporciona
  
- [ ] Endpoint `GET /api/v3/subdivisions/{id}` implementado
  - [ ] Valida que `id` sea numérico
  - [ ] Retorna 200 con subdivision encontrada
  - [ ] Retorna 404 si no existe o pertenece a otra notaría
  - [ ] Retorna 400 si ID no es numérico

- [ ] Endpoint `POST /api/v3/subdivisions` implementado
  - [ ] Parsea body JSON con `name` y `status` opcional
  - [ ] Retorna 201 sin body en éxito
  - [ ] Retorna 400 con errores de validación

- [ ] Endpoint `PUT /api/v3/subdivisions/{id}` implementado
  - [ ] Parsea body JSON con `name` y `status`
  - [ ] Retorna 204 sin body en éxito
  - [ ] Retorna 404 si no existe
  - [ ] Retorna 400 con errores de validación

### Service Layer

- [ ] Lógica de negocio encapsulada en `SubdivisionService`
- [ ] Método `getAll()` delega filtros a Repository
- [ ] Método `getById()` filtra por notary_id
- [ ] Método `create()`:
  - [ ] Aplica `trim()` al nombre antes de validar unicidad
  - [ ] Verifica nombre único por notaría
  - [ ] Retorna `true` en éxito, string con error si falla
- [ ] Método `update()`:
  - [ ] Verifica que subdivision exista en la notaría
  - [ ] Aplica `trim()` al nombre
  - [ ] Verifica unicidad excluyendo el registro actual
  - [ ] Retorna `true` en éxito, `null` si no existe, string si error

### Repository Layer

- [ ] Queries usan prepared statements (PDO)
- [ ] TODOS los queries filtran por `id_notaria`
- [ ] `findAllByNotary()`:
  - [ ] Construye query dinámico con filtros opcionales
  - [ ] Usa `LIKE LOWER()` para búsqueda case-insensitive
  - [ ] Ordena por nombre ASC
- [ ] `findByIdAndNotary()`:
  - [ ] Retorna null si no existe o no pertenece a notaría
- [ ] `existsByNameAndNotary()`:
  - [ ] Compara con `LOWER()` para unicidad case-insensitive
  - [ ] Acepta `excludeId` para excluir registro actual en updates
- [ ] `create()`:
  - [ ] Inserta con fecha actual
  - [ ] Retorna ID generado
- [ ] `update()`:
  - [ ] Actualiza todos los campos incluyendo fecha y usuario

### Entity

- [ ] Entity `Subdivision` con propiedades readonly tipadas:
  - [ ] `int $id`
  - [ ] `int $notaryId`
  - [ ] `string $name`
  - [ ] `int $userId`
  - [ ] `DateTime $modificationDate`
  - [ ] `int $statusId`
- [ ] Método estático `fromRow(array $row): self` para mapear desde BD
- [ ] Constructor con promoted properties

### DTOs

- [ ] `CreateSubdivisionRequest`:
  - [ ] Campo `name`: requerido, max 500 caracteres
  - [ ] Campo `status`: opcional, default 1, valores 1 o 2
  - [ ] Método `fromArray()` para crear desde request body
  - [ ] Método `isValid()` retorna bool
  - [ ] Método `getErrors()` retorna array asociativo

- [ ] `UpdateSubdivisionRequest`:
  - [ ] Campo `name`: requerido, max 500 caracteres
  - [ ] Campo `status`: requerido, valores 1 o 2
  - [ ] Mismos métodos que Create

- [ ] `SubdivisionResponse`:
  - [ ] Método `fromEntity()` retorna array con campos:
    - `subdivisionId` (no `id`)
    - `notaryId`
    - `name`
    - `userId`
    - `modificationDate` (formato ISO 8601: `Y-m-d\TH:i:s`)
    - `status`
  - [ ] Método `fromCollection()` mapea array de entities

## Tests Requeridos (Fase QA)

- [ ] Test GET /subdivisions sin filtros
- [ ] Test GET /subdivisions con filtro status
- [ ] Test GET /subdivisions con filtro name
- [ ] Test GET /subdivisions con ambos filtros
- [ ] Test GET /subdivisions/{id} existente
- [ ] Test GET /subdivisions/{id} inexistente → 404
- [ ] Test GET /subdivisions/{id} de otra notaría → 404
- [ ] Test GET /subdivisions/{id} con ID no numérico → 400
- [ ] Test POST /subdivisions exitoso → 201
- [ ] Test POST /subdivisions nombre duplicado → 400
- [ ] Test POST /subdivisions sin nombre → 400
- [ ] Test POST /subdivisions nombre > 500 chars → 400
- [ ] Test PUT /subdivisions/{id} exitoso → 204
- [ ] Test PUT /subdivisions/{id} inexistente → 404
- [ ] Test PUT /subdivisions/{id} nombre duplicado → 400
- [ ] Test PUT /subdivisions/{id} status=2 (soft delete) → 204
- [ ] Test sin token → 401
- [ ] Test token expirado → 401

## Criterios de Código

- [ ] PSR-12 coding style
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] Typed properties y return types en todos los métodos
- [ ] Namespaces correctos: `App\Module\Subdivision\{Controller|Service|Repository|Entity|DTO}`
- [ ] Sin dependencias circulares
- [ ] ServiceLocator usado solo en Controller

## Performance

- [ ] Queries con índices apropiados (id_notaria, nombre_fraccionamiento)
- [ ] Sin N+1 queries (una sola query por operación)
- [ ] Respuestas < 200ms para lectura
- [ ] Respuestas < 500ms para escritura

## Seguridad

- [ ] JWT requerido en todos los endpoints (AuthMiddleware)
- [ ] notary_id extraído del token, NUNCA del request body
- [ ] user_id extraído del token para auditoría
- [ ] Prepared statements para TODOS los queries
- [ ] Mensajes de error no revelan información interna
- [ ] 404 retornado para recursos de otra notaría (no 403)
