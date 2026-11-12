# Criterios de Aceptación Técnicos: CRUD Completo de Escrituras

## Basado en: PO Acceptance Criteria
[specs/13_deeds_crud/po/acceptance_criteria.md](../po/acceptance_criteria.md)

### Cambios respecto al PO (ver ADRs en `implementation_plan.md`)

| Escenario / regla del PO | Cambio | ADR |
|---|---|---|
| Esc. 6 y 15 (409 `Deed number already exists`) y edge "PUT mismo deedNumber" | **Eliminados**: `deedNumber` no es único | ADR-002 |
| Migración `UNIQUE (id_notaria, num_escritura)` | **No se crea** (365 grupos duplicados en datos reales) | ADR-002 |
| Sin reglas del tipo de escritura | **Nuevas** 422 `registration_limit_exceeded` y `subdivision_not_allowed` | ADR-003 |
| Esc. 10 y 20 (`{"error": "Invalid participant type"}`) | Formato `{"error": "Validation failed", "code": "validation_failed", "errors": {"typeId": "Invalid participant type"}}` | ADR-005 |
| Edge "nombre de participante muy largo (truncar a 250)" | **400**, no se trunca | ADR-007 |
| Todas las respuestas de error | Agregan `code` (y `details` cuando aplica) | ADR-005 |
| PUT de escritura | Reemplazo completo de los campos editables | ADR-006 |
| Auditoría | También al modificar participantes e inscripciones | ADR-010 |

## Criterios por Capa

### Base de Datos
- [ ] `escrituras`, `participantes_escritura` e `inscripciones_escritura` en `ENGINE=InnoDB`
- [ ] `idx_inscripciones_escritura (id_escritura)` creado
- [ ] **No** existe índice UNIQUE sobre `(id_notaria, num_escritura)`
- [ ] Migraciones en `.db/pending/` con secciones UP y DOWN
- [ ] `participantes_escritura.id_estatus = 0` normalizado a `2` en local

### Controller Layer
- [ ] `GET /api/v3/deeds/{id}` → 200 `Deed`
- [ ] `POST /api/v3/deeds` → 201 `Deed`
- [ ] `PUT /api/v3/deeds/{id}` → 200 `Deed`
- [ ] `POST /api/v3/deeds/{id}/finish` → 200 `Deed`
- [ ] `GET /api/v3/deeds/{id}/participants` → 200 `Participant[]` (solo activos)
- [ ] `POST /api/v3/deeds/{id}/participants` → 201 `Participant`
- [ ] `PUT /api/v3/deeds/{id}/participants/{participantId}` → 200 `Participant`
- [ ] `DELETE /api/v3/deeds/{id}/participants/{participantId}` → 204
- [ ] `GET /api/v3/deeds/{id}/registrations` → 200 `Registration[]`
- [ ] `POST /api/v3/deeds/{id}/registrations` → 201 `Registration`
- [ ] `PUT /api/v3/deeds/{id}/registrations/{registrationId}` → 200 `Registration`
- [ ] `DELETE /api/v3/deeds/{id}/registrations/{registrationId}` → 204
- [ ] Las 12 rutas tienen `->withMiddleware('auth')`; sin token → 401
- [ ] `GET /api/v3/deeds` (listado) sigue funcionando igual
- [ ] Ids de ruta no enteros (`abc`, `1.5`) → 400 `invalid_id`; `≤ 0` → 404 del recurso correspondiente
- [ ] `notaryId` y `userId` se toman **solo** del token (`getNotaryId()`, `getUserId()`), nunca del body
- [ ] Todo error de dominio responde `{error, code}` (+ `details` en `registration_limit_exceeded` y `subdivision_not_allowed`)
- [ ] Errores de validación: 400 `{error: "Validation failed", code: "validation_failed", errors: {...}}`
- [ ] Controllers sin lógica de negocio: parsean, delegan y mapean errores con `DeedErrorResponse`

### DTO Layer
- [ ] `CreateDeedRequest` valida según la tabla del PO: `deedNumber` (trim, 1..50), `book` (trim, 1..100), `deedDate` (Y-m-d real), `actId`/`deedTypeId` (`is_int` > 0), `subdivisionId` (`null` o int > 0), `property`/`observations` (string ≤ 65535 bytes), `deliveryDate` (Y-m-d), `deliveredBy` (≤ 250)
- [ ] `"2024-02-30"`, `"15/01/2024"`, `20240115` y `"2024-1-5"` son fechas inválidas
- [ ] `"5"` (string) en `actId` es inválido (no se hace cast)
- [ ] Opcionales con `""` o solo espacios se guardan como `NULL`
- [ ] `participants` no lista o > 50 items → 400; errores por item con clave `participants.N.campo`
- [ ] `registrations` no lista o > 20 items → 400; errores por item con clave `registrations.N.campo`
- [ ] `participants[].typeId` fuera de {1, 2} → `Invalid participant type`
- [ ] `participants[].name` vacío → requerido; > 250 caracteres (`mb_strlen`) → 400 (no se trunca)
- [ ] Inscripción con los 4 campos vacíos/null → 400 `errors.registration`
- [ ] `UpdateDeedRequest` ignora `participants`, `registrations`, `isFinished` y claves desconocidas sin error
- [ ] `UpdateDeedRequest`: opcionales omitidos quedan en `null` (reemplazo completo)
- [ ] `DeedResponse` produce exactamente la forma del PO; `subdivision`/`modifiedBy` pueden ser `null`; `act.name`/`deedType.name` pueden ser `null`
- [ ] `modifiedAt` en ISO 8601 con offset de `America/Mexico_City`
- [ ] Fechas `0000-00-00` se devuelven como `null` (deed, entrega, inscripción y en el listado existente)

### Service Layer
- [ ] `DeedService::create` valida acto, tipo y fraccionamiento **antes** de abrir la transacción
- [ ] `DeedService::create` inserta escritura, participantes e inscripciones en **una** llamada a `transactional()`
- [ ] `DeedService::update` hace `lockForUpdate` dentro de la transacción y aplica las reglas "solo si cambia" (acto, tipo, fraccionamiento)
- [ ] `DeedService::update` con tipo nuevo valida `max_inscripciones` contra las inscripciones actuales
- [ ] `DeedService::finish`: escritura terminada → `deed_already_finished`; nunca pone `terminada = 0`
- [ ] `ParticipantService` y `RegistrationService` hacen `lockForUpdate` de la escritura (404 si no es de la notaría) antes de buscar el hijo
- [ ] Hijo que pertenece a otra escritura → 404 del hijo
- [ ] `RegistrationService::create` cuenta las inscripciones **después** del lock
- [ ] Toda operación de escritura (incluidos subrecursos) llama `touch()` o actualiza `id_usuario` + `fecha_modificacion` en la misma transacción
- [ ] Los Services no conocen HTTP (lanzan `DeedException`, no devuelven `Response`)

### Repository Layer
- [ ] Todas las consultas a `escrituras` filtran por `id_notaria`
- [ ] Consultas de hijos filtran por `id_escritura` de una escritura ya validada contra la notaría
- [ ] Los `LEFT JOIN` a catálogos y usuarios incluyen `AND x.id_notaria = e.id_notaria`
- [ ] La sentencia `UPDATE` del PUT **no contiene** la columna `terminada`
- [ ] `id_fraccionamiento` se guarda como `-1` cuando `subdivisionId` es `null`
- [ ] `id_tipo_escitura` (typo legacy) en todas las queries
- [ ] Inserciones de varios participantes/inscripciones en un solo INSERT multi-row; lista vacía no ejecuta SQL
- [ ] Ningún repositorio llama `beginTransaction/commit/rollBack`
- [ ] Prepared statements con parámetros con nombre únicos (`EMULATE_PREPARES=false` no permite repetir placeholders)

### Entity
- [ ] `Participant::isActive()` es `statusId === 1` (0, 2 y NULL son inactivos)
- [ ] `Deed::subdivision` es `null` si `id_fraccionamiento ≤ 0`
- [ ] `Deed::isFinished` es `terminada === 1`
- [ ] `ParticipantType` enum con `label()`; `typeId` desconocido → `type: null` (no lanza excepción)
- [ ] Propiedades `readonly` y tipadas

## Tests Requeridos

### Unit tests (obligatorios)

**`LegacyDateTest`**
- [ ] `null`, `''`, `'0000-00-00'` → `null`; `'2024-01-15'` → `'2024-01-15'`

**`DeedListItemTest`** (modificar)
- [ ] `fecha_escritura = '0000-00-00'` → `deedDate` null

**`DeedTest` / `ParticipantTest` / `RegistrationTest`**
- [ ] Mapeo completo desde fila; subdivision `-1`, `0` → null; act sin nombre → `{id, null}`; `terminada` 0/1/NULL
- [ ] Participante con `id_estatus` 0, 1, 2

**`CreateDeedRequestTest`**
- [ ] Payload completo del escenario 4 válido; payload mínimo del escenario 5 válido
- [ ] Cada campo requerido faltante genera su error
- [ ] Longitudes límite (50/51, 100/101, 250/251) con caracteres multibyte (`ñ`, `á`)
- [ ] Fechas inválidas (lista arriba)
- [ ] `participants` como objeto (no lista), 51 items, item no objeto, `typeId` 0/3/"1"
- [ ] Inscripción vacía; inscripción solo con `date`

**`UpdateDeedRequestTest`**
- [ ] `isFinished: true`, `participants: [...]`, `registrations: [...]` se ignoran sin error
- [ ] Opcional omitido → null

**`SaveParticipantRequestTest` / `SaveRegistrationRequestTest`**
- [ ] Casos válidos, requeridos, `typeId` inválido, límites

**`DeedErrorTest`**
- [ ] Cada caso tiene mensaje, `code` y HTTP de la tabla del plan
- [ ] `registration_limit_exceeded` arma el mensaje con `maxRegistrations`

**`DeedReferenceValidatorTest`**
- [ ] Acto/tipo/fraccionamiento: inexistente → 422; inactivo (`id_estatus = 2`) → 422; activo → OK
- [ ] `assertSubdivisionAllowed`: `enableSubdivisions = 0` + subdivisión → error; `= 1` → OK; subdivisión null → OK siempre
- [ ] `assertRegistrationLimit`: `count = max` OK; `max + 1` error con `details`; `max = 0` sin límite; tipo null sin límite

**`DeedServiceTest`** (ampliar)
- [ ] `getById` inexistente → `deed_not_found`
- [ ] `create` llama `transactional` una vez e inserta participantes e inscripciones dentro
- [ ] `create` con acto inactivo no llama `transactional`
- [ ] `create` con 3 inscripciones y tipo con max 2 → `registration_limit_exceeded`
- [ ] `update` sin cambio de acto/tipo/fraccionamiento **no** valida estatus de catálogos (escritura legacy con acto inactivo se puede editar)
- [ ] `update` cambiando a tipo con max 1 y 2 inscripciones actuales → `registration_limit_exceeded`
- [ ] `update` cambiando solo el fraccionamiento con tipo actual sin `activar_fraccionamientos` → `subdivision_not_allowed`
- [ ] `update` de escritura inexistente → `deed_not_found`
- [ ] `finish` de escritura terminada → `deed_already_finished` y no llama `markFinished`
- [ ] `finish` de no terminada llama `markFinished` con el `userId` del token

**`ParticipantServiceTest`**
- [ ] Escritura de otra notaría → `deed_not_found`
- [ ] Participante de otra escritura → `participant_not_found`
- [ ] `update` de inactivo → `participant_inactive`
- [ ] `deactivate` de inactivo (`id_estatus` 0 o 2) → `participant_already_inactive`
- [ ] Toda escritura llama `touch()`

**`RegistrationServiceTest`**
- [ ] `create` con `countByDeed = max` → `registration_limit_exceeded`
- [ ] `create` con tipo inexistente → sin límite
- [ ] `delete` llama `delete()` y `touch()`
- [ ] Inscripción de otra escritura → `registration_not_found`

### Pruebas manuales / QA (contra la BD local con InnoDB)
- [ ] **Rollback:** forzar una excepción después de insertar la escritura (p. ej. un `throw` temporal en `insertMany` de inscripciones) → no queda ni la escritura ni sus participantes
- [ ] **Concurrencia de inscripciones:** dos `POST /registrations` simultáneos sobre una escritura con tipo max 1 y 0 inscripciones → uno 201, otro 422
- [ ] **Finish concurrente:** dos `POST /finish` simultáneos → uno 200, otro 409
- [ ] PUT sobre escritura terminada → 200 y `terminada` sigue en 1 (verificar en BD)
- [ ] `PUT` con `isFinished: true` sobre no terminada → `terminada` sigue en 0
- [ ] Escritura con `id_fraccionamiento = -1` y `0` → `subdivision: null`
- [ ] Escritura 3904 (acto de otra notaría) → `act: {id: 1, name: null}`
- [ ] Escritura con número duplicado (p. ej. notaría 1, `23209`): POST con ese número → 201
- [ ] Agregar participante → `modifiedAt` y `modifiedBy` de la escritura cambian
- [ ] Token de notaría A con id de escritura de notaría B en los 12 endpoints → 404 `deed_not_found`

## Criterios de Código
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] PSR-12; typed properties, parámetros y returns
- [ ] Entities, VOs y DTOs `final readonly` (o propiedades `readonly`)
- [ ] Repositories, `DeedReferenceValidator` y `TransactionManager` **no** `final` (mockeables)
- [ ] Sin dependencias circulares; los repositorios no dependen entre sí
- [ ] Constantes en lugar de números mágicos (`STATUS_ACTIVE`, `NO_SUBDIVISION = -1`, límites)
- [ ] Sin `SELECT *` en las queries nuevas

## Performance
- [ ] `GET /deeds/{id}`: exactamente 3 queries
- [ ] `POST /deeds`: ≤ 3 lecturas de validación + 3 INSERT + 3 lecturas de relectura
- [ ] Consultas de participantes usan `idx_participantes_escritura_tipo`; de inscripciones, `idx_inscripciones_escritura` (verificar con `EXPLAIN`)
- [ ] < 500 ms por operación en local

## Seguridad
- [ ] JWT requerido en las 12 rutas
- [ ] Escritura de otra notaría → 404 (nunca 403, no se revela existencia)
- [ ] Subrecurso de otra escritura → 404
- [ ] `notaryId`/`userId` nunca del body
- [ ] Prepared statements en todo; sin concatenar input en SQL
- [ ] Sin trazas ni mensajes de PDO en respuestas (el handler global ya lo cubre con `APP_DEBUG=false`)

## Documentación
- [ ] `docs/openapi.yaml` con las 12 operaciones, schemas y enum de `code`
- [ ] OpenAPI indica: `deedNumber` no único; PUT reemplazo completo; PUT ignora `participants`/`registrations`/`isFinished`; `finish` irreversible
- [ ] Rutas comentadas de deeds eliminadas del final de `routes.php`
