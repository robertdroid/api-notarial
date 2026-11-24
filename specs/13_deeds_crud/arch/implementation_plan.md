# Plan de Implementación: CRUD Completo de Escrituras

## Resumen

Completa el módulo `Deed` (hoy solo tiene `GET /deeds`) con detalle, alta, modificación y cierre de escrituras, más dos subrecursos: **participantes** e **inscripciones**. Son 12 rutas nuevas. Todo vive dentro de `src/Module/Deed/` porque participantes e inscripciones no existen fuera de una escritura.

Puntos clave del diseño:

1. **Atomicidad real**: se migran `escrituras`, `participantes_escritura` e `inscripciones_escritura` a InnoDB y se reutiliza `TransactionManager` (spec 11). Toda escritura en BD (incluidos los subrecursos) ocurre dentro de `transactional()` y empieza con un `SELECT ... FOR UPDATE` de la escritura padre.
2. **`terminada` solo cambia en `POST /finish`**. El `UPDATE` del PUT no incluye la columna (no basta con ignorar el campo en el DTO).
3. **Auditoría en cada operación**: toda escritura en la escritura **o en sus subrecursos** actualiza `escrituras.id_usuario` y `escrituras.fecha_modificacion` en la misma transacción.
4. **Sin unicidad de `deedNumber`** (decisión del equipo, ADR-002): en los datos reales, una escritura con varios inmuebles se registra como varias filas con el mismo número.
5. **Reglas del tipo de escritura** (decisión del equipo, ADR-003): `max_inscripciones` y `activar_fraccionamientos` se validan con errores 422 que incluyen un `code` identificable por el frontend.
6. **Convivencia con la v1 por ~2 semanas** (ADR-004): se respetan los centinelas legacy (`id_fraccionamiento = -1`, `id_estatus = 0`, fechas `0000-00-00`). La normalización queda como deuda técnica para cuando la v1 se retire.

### Hallazgos en la BD local (2026-11-12)

| Dato | Valor | Impacto |
|------|-------|---------|
| Escrituras / participantes / inscripciones | 19,432 / 60,350 / 16,414 | Tablas pequeñas: el `ALTER ... ENGINE=InnoDB` tarda segundos |
| Motor / collation | MyISAM, `utf8mb3_bin` | Sin transacciones hasta migrar |
| **Grupos `(id_notaria, num_escritura)` duplicados** | **365 grupos, 757 filas** (notarías 1 y 3) | **El índice UNIQUE del PO no se puede crear** → ADR-002 |
| Duplicados idénticos en todos los campos | 22 grupos | Probables capturas dobles; no se limpian (fuera de alcance) |
| `num_escritura` no numérico | 15 (`16024 BIS`, `19*318`, `CDXXIX`, …) y algunos con espacios al final | `deedNumber` es texto libre; se aplica `trim` al escribir |
| `id_fraccionamiento` | `-1`: 18,891 · `0`: 224 · `> 0`: 317 · `NULL`: 0 | `-1` es el "sin fraccionamiento" de la v1 → ADR-004 |
| `participantes_escritura.id_estatus` | `1`: 59,740 · `0`: 610 | `0` = eliminado en la v1 |
| `id_tipo_participante` | Solo `1` y `2` | Catálogo fijo en código (enum) |
| Fechas `0000-00-00` | `fecha_escritura`: 19 · `fecha_entrega`: 2 · `fecha_inscripcion`: 16 | Se leen como `null`. **El `GET /deeds` actual las muestra como `-0001-11-30`** (tarea 2.6) |
| Escrituras sobre `max_inscripciones` de su tipo | 6 | El legacy valida (casi siempre); no se corrigen datos |
| Escrituras con fraccionamiento y tipo sin `activar_fraccionamientos` | 1 | Ídem |
| Escritura con `id_acto` de otra notaría | 1 (id 3904) | El JOIN filtra por notaría → `act.name = null` |
| Participantes con nombre vacío | 2 | Se muestran tal cual; las nuevas altas exigen nombre |
| Huérfanos (participantes / inscripciones sin escritura) | 0 / 0 | — |
| `id_usuario` / `fecha_modificacion` nulos | 0 / 0 | `modifiedBy` y `modifiedAt` siempre tienen valor en datos actuales |
| `sql_mode` | `STRICT_TRANS_TABLES,…` sin `NO_ZERO_DATE` | Las fechas cero se pueden seguir insertando desde la v1 |

## Arquitectura Propuesta

### Diagrama de Componentes

```
HTTP  GET    /api/v3/deeds/{id}
      POST   /api/v3/deeds
      PUT    /api/v3/deeds/{id}
      POST   /api/v3/deeds/{id}/finish
      GET|POST        /api/v3/deeds/{id}/participants
      PUT|DELETE      /api/v3/deeds/{id}/participants/{participantId}
      GET|POST        /api/v3/deeds/{id}/registrations
      PUT|DELETE      /api/v3/deeds/{id}/registrations/{registrationId}
    │
    ▼
Router ──► AuthMiddleware (JWT → notaryId, userId) ── 401
    │
    ▼
DeedController │ ParticipantController │ RegistrationController
  │ parseId()                → 400 "Invalid ID format" | 404
  │ DTO::fromArray()         → 400 {error, code: "validation_failed", errors}
  │ catch DeedException      → DeedErrorResponse → 404 | 409 | 422 {error, code[, details]}
  ▼
DeedService                ParticipantService          RegistrationService
  │ getById                  │ list / create             │ list / create
  │ create / update          │ update / deactivate       │ update / delete
  │ finish                   │                           │
  │                          └──────────┬────────────────┘
  │                                     │ DeedGuard (lock + touch)
  ├──► DeedReferenceValidator ──► ActRepository / DeedTypeRepository / SubdivisionRepository  (solo lectura)
  ├──► TransactionManager (Core/Database)
  ▼
DeedRepository ─────────► escrituras                (InnoDB)
ParticipantRepository ──► participantes_escritura   (InnoDB)
RegistrationRepository ─► inscripciones_escritura   (InnoDB)
  ▼
Deed (read model con act/deedType/subdivision/modifiedBy) + Participant[] + Registration[]
  ▼
DeedResponse / ParticipantResponse / RegistrationResponse
```

Flujo de toda operación de escritura (POST/PUT/finish y subrecursos):

```
transactional {
    lockForUpdate(deedId, notaryId)      → DeedNotFound (404)            [no aplica a POST /deeds]
    reglas de dominio                    → 409 / 422
    INSERT / UPDATE / DELETE
    touch(deedId, notaryId, userId, now) → auditoría
}
releer y responder
```

### Módulos Afectados

- [ ] `src/Module/Deed/` - Extender: Entities, DTOs, Exception, 2 Repositories nuevos + métodos en `DeedRepository`, 2 Services nuevos + métodos en `DeedService`, 2 Controllers nuevos + acciones en `DeedController`
- [ ] `src/Module/Deed/Entity/DeedListItem.php` - Corregir fechas `0000-00-00` (tarea 2.6)
- [ ] `src/bootstrap.php` - Registrar repositorios, servicios y helpers nuevos
- [ ] `src/Config/routes.php` - 12 rutas protegidas
- [ ] `.db/pending/` - 2 migraciones (InnoDB + índice; normalización de estatus)
- [ ] `docs/openapi.yaml` - Paths de deeds, participants y registrations
- [ ] `tests/Module/Deed/` - Tests unitarios

**No se modifican** `Act`, `DeedType`, `Subdivision` ni `User`: ya tienen `findByIdAndNotary()` y las entidades exponen `statusId`, `maxInscriptions` y `enableSubdivisions`.

## Reglas de Negocio Consolidadas

### Validación de referencias (POST /deeds y PUT /deeds/{id})

| Regla | POST | PUT |
|-------|------|-----|
| `actId` existe en la notaría y `id_estatus = 1` | Siempre | Solo si cambia |
| `deedTypeId` existe en la notaría y `id_estatus = 1` | Siempre | Solo si cambia |
| `subdivisionId` (si no es null) existe en la notaría y `id_estatus = 1` | Siempre | Solo si cambia |
| `subdivisionId` no null ⇒ el tipo tiene `activar_fraccionamientos = 1` | Siempre | Si cambia el tipo o el fraccionamiento |
| Nº de inscripciones ≤ `max_inscripciones` del tipo | Con `registrations[]` del body | Solo si cambia el tipo (cuenta las inscripciones actuales) |

"Solo si cambia" conserva escrituras históricas que apuntan a catálogos hoy inactivos (mismo criterio que el ADR-006 del spec 11). Si el PUT no cambia nada de esto, una escritura legacy que ya incumplía una regla se puede seguir editando.

`max_inscripciones ≤ 0` se trata como "sin límite" (no hay casos en los datos actuales).

### Subrecursos

| Operación | Reglas |
|-----------|--------|
| `POST /registrations` | Inscripciones actuales + 1 ≤ `max_inscripciones` del tipo **actual** de la escritura (aunque el tipo esté inactivo). Si el tipo ya no existe, no se aplica límite |
| `PUT /registrations/{rid}` | Sin regla de límite |
| `DELETE /registrations/{rid}` | Borrado físico |
| `GET /participants` | Solo `id_estatus = 1` |
| `PUT /participants/{pid}` | Participante con `id_estatus ≠ 1` → 409 `Participant is inactive` |
| `DELETE /participants/{pid}` | `id_estatus ≠ 1` → 409 `Participant is already inactive`; si no, `id_estatus = 2` |

Los subrecursos de una escritura **terminada** se pueden modificar, igual que el PUT de la escritura (escenario 14 del PO).

### Precedencia de validaciones

**POST /deeds:** 401 → 400 body → acto 422 → tipo 422 → fraccionamiento 422 → fraccionamiento no permitido 422 → límite de inscripciones 422 → escritura → 201.

**PUT /deeds/{id}:** 401 → 400 `{id}` → 400 body → 404 escritura → (solo lo que cambia) acto → tipo → fraccionamiento → fraccionamiento no permitido → límite de inscripciones → escritura → 200.

**POST /deeds/{id}/finish:** 401 → 400 `{id}` → 404 → 409 ya terminada → 200.

**Subrecursos:** 401 → 400 `{id}`/`{pid}`/`{rid}` → 400 body → 404 escritura → 404 participante/inscripción (no existe o es de otra escritura) → 409/422 → 200/201/204.

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 1.5 h

#### 0.1 Confirmar cómo escribe la v1 en estas tablas
- **Archivo(s):** Código de la v1 (fuera de este repo)
- **Tipo:** Investigación. **Bloquea el deploy de 0.2 a producción, no el desarrollo.**
- **Descripción:** Responder en el PR:
  1. ¿La v1 usa `LOCK TABLES`, `INSERT DELAYED`, `SHOW TABLE STATUS` u otra cosa específica de MyISAM sobre estas tablas?
  2. ¿Al "eliminar" un participante escribe `id_estatus = 0`? (esperado: sí; la v3 lo tolera).
  3. ¿Escribe `-1` o `0` en `id_fraccionamiento` cuando no hay fraccionamiento?
  4. ¿Al editar participantes/inscripciones hace `DELETE` + `INSERT` de todos? Si es así, los ids de participantes e inscripciones cambian cada vez que se edita en la v1: el frontend v3 no debe cachear esos ids entre pantallas.
- **Dependencias:** Ninguna

#### 0.2 Migración InnoDB + índice de inscripciones
- **Archivo:** `.db/pending/20261112_100000_deeds_innodb_and_indexes.sql`
- **Tipo:** Crear
- **Descripción:** `ENGINE=InnoDB` para las 3 tablas e `INDEX idx_inscripciones_escritura (id_escritura)`. **Sin** el `UNIQUE (id_notaria, num_escritura)` del PO (ADR-002). No se agrega índice para participantes: `idx_participantes_escritura_tipo (id_escritura, …)` ya cubre la búsqueda por escritura. SQL en `developer_notes.md`.
- **Dependencias:** 0.1 (solo para producción)

#### 0.3 Migración de normalización de estatus de participantes
- **Archivo:** `.db/pending/20261112_100100_normalize_participant_status.sql`
- **Tipo:** Crear
- **Descripción:** `UPDATE participantes_escritura SET id_estatus = 2 WHERE id_estatus = 0` (610 filas locales). Es cosmética mientras la v1 conviva (puede volver a escribir `0`); el código **no** depende de ella (ADR-004).
- **Dependencias:** Ninguna

### Fase 1: Dominio (Entities, Enums, Errores)
Estimación: 2.5 h

#### 1.1 Enum `ParticipantType`
- **Archivo:** `src/Module/Deed/Entity/ParticipantType.php`
- **Tipo:** Crear
- **Descripción:** `enum ParticipantType: int { case Seller = 1; case Buyer = 2; }` con `label(): string` → `Enajenante` / `Adquiriente` (ADR-008). `tryFrom()` se usa para validar `typeId`.
- **Dependencias:** Ninguna

#### 1.2 Helper `LegacyDate`
- **Archivo:** `src/Module/Deed/Entity/LegacyDate.php`
- **Tipo:** Crear
- **Descripción:** `final class` con `static toYmd(?string $value): ?string`: devuelve `null` para `null`, `''` y `0000-00-00`; si no, `Y-m-d`. Lo usan todas las entidades de este módulo (incluida la corrección de `DeedListItem`).
- **Dependencias:** Ninguna

#### 1.3 Entity `Participant`
- **Archivo:** `src/Module/Deed/Entity/Participant.php`
- **Tipo:** Crear
- **Descripción:** `final` readonly: `id`, `deedId`, `name` (string, `''` si NULL), `typeId` (int), `statusId` (int). `STATUS_ACTIVE = 1`, `STATUS_INACTIVE = 2`. `isActive(): bool` → `statusId === 1` (cualquier otro valor, incluido 0, es inactivo). `type(): ?ParticipantType`. `fromRow()`.
- **Dependencias:** 1.1

#### 1.4 Entity `Registration`
- **Archivo:** `src/Module/Deed/Entity/Registration.php`
- **Tipo:** Crear
- **Descripción:** `final` readonly: `id`, `deedId`, `number`, `section`, `volume` (`?string`), `date` (`?string` vía `LegacyDate`). `fromRow()`.
- **Dependencias:** 1.2

#### 1.5 Value objects `NamedReference` y Entity `Deed`
- **Archivos:** `src/Module/Deed/Entity/NamedReference.php`, `src/Module/Deed/Entity/Deed.php`
- **Tipo:** Crear
- **Descripción:**
  - `NamedReference(int $id, ?string $name)`: para `act`, `deedType`, `subdivision` y `modifiedBy`.
  - `Deed` (read model, `final` readonly): `id`, `notaryId`, `deedNumber`, `book`, `deedDate` (`?string`), `act` (`?NamedReference`), `deedType` (`?NamedReference`), `subdivision` (`?NamedReference`), `property`, `deliveryDate`, `deliveredBy`, `observations`, `isFinished` (bool, `terminada === 1`), `modifiedBy` (`?NamedReference`), `modifiedAt` (`?DateTimeImmutable`), `participants` (`Participant[]`), `registrations` (`Registration[]`).
  - Además expone los ids crudos para las reglas del PUT: `actId`, `deedTypeId`, `subdivisionId` (`?int`, null si `≤ 0`).
  - `fromRow(array $row, array $participants, array $registrations)`. `subdivision` es `null` cuando `id_fraccionamiento ≤ 0`; `act`/`deedType` son `{id, name: null}` si el JOIN no encuentra el catálogo.
- **Dependencias:** 1.2, 1.3, 1.4

#### 1.6 Enum `DeedError` + `DeedException`
- **Archivos:** `src/Module/Deed/Exception/DeedError.php`, `src/Module/Deed/Exception/DeedException.php`
- **Tipo:** Crear
- **Descripción:** Mismo patrón que `TransactionError`/`UserError`, con `code()` estable para el frontend (= `value` del enum). `DeedException(DeedError $error, array $details = [])` extiende `\DomainException`; `$details` lleva datos para el frontend (p. ej. el límite).

  | Caso (`value` = `code`) | Mensaje | HTTP | `details` |
  |---|---|---|---|
  | `deed_not_found` | `Deed not found` | 404 | — |
  | `participant_not_found` | `Participant not found` | 404 | — |
  | `registration_not_found` | `Registration not found` | 404 | — |
  | `deed_already_finished` | `Deed is already finished` | 409 | — |
  | `participant_already_inactive` | `Participant is already inactive` | 409 | — |
  | `participant_inactive` | `Participant is inactive` | 409 | — |
  | `act_unavailable` | `Act not found or inactive` | 422 | — |
  | `deed_type_unavailable` | `Deed type not found or inactive` | 422 | — |
  | `subdivision_unavailable` | `Subdivision not found or inactive` | 422 | — |
  | `subdivision_not_allowed` | `Deed type does not allow subdivisions` | 422 | `{deedTypeId}` |
  | `registration_limit_exceeded` | `Deed type allows at most {max} registrations` | 422 | `{maxRegistrations, currentRegistrations}` |

  El mensaje de `registration_limit_exceeded` se construye con `details.maxRegistrations` (`DeedException::getMessage()`).
- **Dependencias:** Ninguna

### Fase 2: DTOs
Estimación: 3 h

#### 2.1 Reglas `DeedFieldRules`
- **Archivo:** `src/Module/Deed/DTO/DeedFieldRules.php`
- **Tipo:** Crear
- **Descripción:** Métodos estáticos compartidos (estilo `TransactionFieldRules`):
  - `positiveInt(mixed $v): bool` → `is_int($v) && $v > 0`.
  - `date(mixed $v): bool` → string `Y-m-d` real (round-trip con `DateTimeImmutable::createFromFormat('!Y-m-d')`).
  - `string(mixed $v, int $maxChars): ?string` → error o null. Longitud con `mb_strlen` (columnas `varchar` cuentan caracteres).
  - `text(mixed $v): ?string` → `strlen ≤ 65535` (bytes, columnas `TEXT`).
  - Constantes: `MAX_DEED_NUMBER = 50`, `MAX_BOOK = 100`, `MAX_DELIVERED_BY = 250`, `MAX_PARTICIPANT_NAME = 250`, `MAX_REGISTRATION_FIELD = 100`, `MAX_PARTICIPANTS = 50`, `MAX_REGISTRATIONS = 20`.
  - `normalizeOptional(?string): ?string` → `trim`; `''` → `null`.
- **Dependencias:** Ninguna

#### 2.2 `ParticipantInput` + `RegistrationInput` (VOs) y sus DTOs de request
- **Archivos:** `src/Module/Deed/DTO/{ParticipantInput,RegistrationInput,SaveParticipantRequest,SaveRegistrationRequest}.php`
- **Tipo:** Crear
- **Descripción:**
  - `ParticipantInput(string $name, ParticipantType $type)` y `RegistrationInput(?string $number, ?string $section, ?string $volume, ?string $date)`: `final readonly`.
  - `SaveParticipantRequest::fromArray(array $body)` → `isValid()`, `getErrors()`, `toInput()`. `name` requerido (trim, 1..250, **no se trunca**: ADR-007). `typeId` requerido; si no es 1 ni 2 → `errors.typeId = "Invalid participant type"`. Lo usan POST y PUT del subrecurso (PUT es reemplazo completo).
  - `SaveRegistrationRequest::fromArray(array $body)`: `number`, `section`, `volume` opcionales (string ≤ 100, trim, `''` → null), `date` opcional (`null` o `Y-m-d`). Si los 4 quedan en null → `errors.registration = "At least one registration field is required"`. Lo usan POST y PUT (reemplazo completo).
  - La validación por item vive en métodos estáticos `validate(mixed $item, string $prefix, array &$errors): ?Input` reutilizados por 2.3 con prefijos `participants.N.` / `registrations.N.`.
- **Dependencias:** 1.1, 2.1

#### 2.3 `SaveDeedRequest` (base) + `CreateDeedRequest` + `UpdateDeedRequest`
- **Archivos:** `src/Module/Deed/DTO/{DeedFields,CreateDeedRequest,UpdateDeedRequest}.php`
- **Tipo:** Crear
- **Descripción:**
  - `DeedFields` (`final readonly`): `deedNumber`, `book`, `deedDate`, `actId`, `deedTypeId`, `?subdivisionId`, `?property`, `?deliveryDate`, `?deliveredBy`, `?observations`. Lo produce un parser común con las reglas de la tabla de validaciones del PO (`deedNumber` y `book` con `trim` y no vacíos).
  - `CreateDeedRequest`: `DeedFields` + `participants` (`ParticipantInput[]`, opcional, lista ≤ 50) + `registrations` (`RegistrationInput[]`, opcional, lista ≤ 20). Errores por item con clave `participants.0.typeId`, etc.
  - `UpdateDeedRequest`: solo `DeedFields`. **Reemplazo completo** (ADR-006): los opcionales omitidos quedan en `null`. Las claves `participants`, `registrations`, `isFinished`, `id`, `modifiedBy`, `modifiedAt` y cualquier otra desconocida se **ignoran** (escenario 13).
  - `subdivisionId`: `null` o ausente = sin fraccionamiento; si viene, `positiveInt`.
- **Dependencias:** 2.1, 2.2

#### 2.4 DTOs de respuesta
- **Archivos:** `src/Module/Deed/DTO/{DeedResponse,ParticipantResponse,RegistrationResponse}.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` / `fromCollection()`. Forma exacta de la sección "Estructura de Respuesta" del `technical_context.md` del PO, con estas precisiones:
  - `act`, `deedType`: `{id, name}`; `name` puede ser `null`.
  - `subdivision`, `modifiedBy`: objeto o `null`.
  - `modifiedAt`: `DATE_ATOM` en `America/Mexico_City` (`2024-01-15T10:30:00-06:00`) o `null`.
  - `participants[]`: `{id, name, typeId, type}`; `type` = `ParticipantType::label()` o `null` si el `typeId` es desconocido.
  - `registrations[]`: `{id, number, section, volume, date}`.
  - No expone `notaryId` ni `statusId`.
- **Dependencias:** 1.5

#### 2.5 `DeedErrorResponse`
- **Archivo:** `src/Module/Deed/Controller/DeedErrorResponse.php`
- **Tipo:** Crear
- **Descripción:** Helper estático compartido por los 3 controllers:
  - `fromException(DeedException $e): Response` → `Response::json(['error' => msg, 'code' => code] + (details ? ['details' => details] : []), httpStatus)`.
  - `validation(array $errors): Response` → `{"error": "Validation failed", "code": "validation_failed", "errors": {...}}` con 400.
  - `invalidId(): Response` → 400 `{"error": "Invalid ID format", "code": "invalid_id"}`.
  - No modifica `Core\Http\Response` (ADR-005).
- **Dependencias:** 1.6

#### 2.6 Corrección de fechas cero en el listado existente
- **Archivo:** `src/Module/Deed/Entity/DeedListItem.php` (+ su test)
- **Tipo:** Modificar
- **Descripción:** `deedDate` usa `LegacyDate`: hoy `'0000-00-00'` se convierte en `-0001-11-30` (19 escrituras locales). Cambio mínimo; el tipo de la propiedad puede seguir siendo `?DateTimeImmutable` construido solo cuando `LegacyDate::toYmd()` no es null.
- **Dependencias:** 1.2

### Fase 3: Repositories
Estimación: 3 h

> Ningún repositorio abre transacciones. Todos los métodos de escritura filtran por `id_notaria` (directo o vía la escritura padre).

#### 3.1 Extender `DeedRepository`
- **Archivo:** `src/Module/Deed/Repository/DeedRepository.php`
- **Tipo:** Modificar (no tocar los métodos del listado)
- **Métodos nuevos:**
  - `findHeaderByIdAndNotary(int $id, int $notaryId): ?array` → **1 query** de cabecera con `LEFT JOIN` a `actos_notaria`, `tipo_escritura`, `fraccionamientos` y `usuarios_notaria` (todos con `AND x.id_notaria = e.id_notaria`). Devuelve la fila; `DeedService::getById()` la combina con participantes e inscripciones en `Deed::fromRow()`. Total del detalle: **3 queries fijas**, sin N+1.
  - `lockForUpdate(int $id, int $notaryId): ?DeedLock` → `SELECT id_escritura, id_acto, id_tipo_escitura, id_fraccionamiento, terminada FROM escrituras WHERE id_escritura = :id AND id_notaria = :notaryId FOR UPDATE`. `DeedLock` (`src/Module/Deed/Entity/DeedLock.php`) es un `final readonly` con `id`, `actId` (`?int`), `deedTypeId` (`?int`), `subdivisionId` (`?int`, null si `≤ 0`), `isFinished`. Es todo lo que necesitan el PUT, el finish y los subrecursos.
  - `insert(int $notaryId, DeedFields $f, int $userId, DateTimeImmutable $now): int` → `terminada = 0`; `id_fraccionamiento = $f->subdivisionId ?? -1` (ADR-004).
  - `update(int $id, int $notaryId, DeedFields $f, int $userId, DateTimeImmutable $now): void` → **la sentencia no contiene `terminada`**. Sin check de `rowCount()` (es 0 si nada cambia).
  - `markFinished(int $id, int $notaryId, int $userId, DateTimeImmutable $now): void` → `SET terminada = 1, id_usuario, fecha_modificacion`.
  - `touch(int $id, int $notaryId, int $userId, DateTimeImmutable $now): void` → solo auditoría (subrecursos).
- **Dependencias:** 1.5, 2.3, 3.2, 3.3, 0.2

> Los repositorios no dependen entre sí: `DeedService::getById()` orquesta las 3 lecturas (cabecera, participantes activos, inscripciones).

#### 3.2 `ParticipantRepository`
- **Archivo:** `src/Module/Deed/Repository/ParticipantRepository.php`
- **Tipo:** Crear (no `final`, para mockear)
- **Métodos:**
  - `findActiveByDeed(int $deedId): Participant[]` → `WHERE id_escritura = :deedId AND id_estatus = 1 ORDER BY id_participante`.
  - `findByIdAndDeed(int $id, int $deedId): ?Participant` → sin filtro de estatus (el Service decide 409).
  - `insertMany(int $deedId, ParticipantInput[] $items): void` → un INSERT multi-row con `id_estatus = 1`; lista vacía → no ejecuta SQL.
  - `insert(int $deedId, ParticipantInput $item): int`.
  - `update(int $id, int $deedId, ParticipantInput $item): void`.
  - `deactivate(int $id, int $deedId): void` → `id_estatus = 2`.
- **Dependencias:** 1.3, 2.2, 0.2

> El `notaryId` no se repite aquí: el Service siempre resuelve antes la escritura con `lockForUpdate`/`findByIdAndNotary` filtrando por notaría, y luego filtra el hijo por `id_escritura`. Eso cierra el acceso cruzado entre notarías.

#### 3.3 `RegistrationRepository`
- **Archivo:** `src/Module/Deed/Repository/RegistrationRepository.php`
- **Tipo:** Crear (no `final`)
- **Métodos:** `findByDeed(int $deedId): Registration[]` (`ORDER BY id_inscripcion`), `findByIdAndDeed`, `countByDeed(int $deedId): int`, `insertMany`, `insert`, `update`, `delete(int $id, int $deedId): void`.
- **Dependencias:** 1.4, 2.2, 0.2

### Fase 4: Services
Estimación: 4 h

#### 4.1 `DeedReferenceValidator`
- **Archivo:** `src/Module/Deed/Service/DeedReferenceValidator.php`
- **Tipo:** Crear (no `final`)
- **Constructor:** `ActRepository`, `DeedTypeRepository`, `SubdivisionRepository`.
- **Métodos:**
  - `requireActiveAct(int $id, int $notaryId): void` → `act_unavailable`.
  - `requireActiveDeedType(int $id, int $notaryId): DeedType` → `deed_type_unavailable`.
  - `requireActiveSubdivision(int $id, int $notaryId): void` → `subdivision_unavailable`.
  - `findDeedType(int $id, int $notaryId): ?DeedType` → sin validar estatus (para el límite en subrecursos).
  - `assertSubdivisionAllowed(DeedType $type, ?int $subdivisionId): void` → `subdivision_not_allowed` si `subdivisionId !== null && $type->enableSubdivisions !== 1`.
  - `assertRegistrationLimit(?DeedType $type, int $count): void` → `registration_limit_exceeded` si `$type !== null && $type->maxInscriptions > 0 && $count > $type->maxInscriptions`.
- **Dependencias:** 1.6
- **Por qué separado:** lo usan `DeedService` y `RegistrationService`; mantiene cada Service con una sola responsabilidad.

#### 4.2 Extender `DeedService`
- **Archivo:** `src/Module/Deed/Service/DeedService.php`
- **Tipo:** Modificar (`list()` no cambia)
- **Constructor:** `DeedRepository`, `ParticipantRepository`, `RegistrationRepository`, `DeedReferenceValidator`, `TransactionManager`.
- **Métodos:**
  - `getById(int $id, int $notaryId): Deed` → `deed_not_found`.
  - `create(CreateDeedRequest $dto, int $notaryId, int $userId): Deed`
    1. `requireActiveAct`, `requireActiveDeedType` (→ `$type`), `requireActiveSubdivision` si viene.
    2. `assertSubdivisionAllowed($type, …)`, `assertRegistrationLimit($type, count($dto->registrations))`.
    3. `transactional { insert; insertMany participantes; insertMany inscripciones }`.
    4. `getById()`.
  - `update(int $id, UpdateDeedRequest $dto, int $notaryId, int $userId): Deed` — todo en `transactional`:
    1. `lockForUpdate($id, $notaryId)` → `deed_not_found`. Los ids actuales salen del `DeedLock`.
    2. Acto cambió → `requireActiveAct`. Fraccionamiento cambió y no es null → `requireActiveSubdivision`.
    3. Tipo cambió → `$type = requireActiveDeedType(nuevo)`; si no cambió → `$type = findDeedType(actual)` (sin validar estatus).
    4. Tipo o fraccionamiento cambió y `$type !== null` → `assertSubdivisionAllowed($type, dto.subdivisionId)`.
    5. Tipo cambió → `assertRegistrationLimit($type, countByDeed($id))`.
    6. `update()`.
    7. Fuera de la transacción: `getById()`.
  - `finish(int $id, int $notaryId, int $userId): Deed` — en `transactional`: `lockForUpdate` → 404; `isFinished` → `deed_already_finished`; `markFinished()`. Luego `getById()`.
- **Dependencias:** 3.x, 4.1

#### 4.3 `ParticipantService`
- **Archivo:** `src/Module/Deed/Service/ParticipantService.php`
- **Tipo:** Crear
- **Constructor:** `DeedRepository`, `ParticipantRepository`, `TransactionManager`.
- **Métodos:**
  - `list(int $deedId, int $notaryId): Participant[]` → verifica que la escritura exista en la notaría (404) y devuelve activos.
  - `create(int $deedId, ParticipantInput $in, int $notaryId, int $userId): Participant` → `transactional { lock (404); insert; touch }` → relee.
  - `update(int $deedId, int $id, ParticipantInput $in, …): Participant` → `transactional { lock; findByIdAndDeed (404); !isActive → participant_inactive; update; touch }`.
  - `deactivate(int $deedId, int $id, …): void` → `transactional { lock; findByIdAndDeed (404); !isActive → participant_already_inactive; deactivate; touch }`.
  - Privado `lockDeed(int $deedId, int $notaryId): DeedLock` → `deed_not_found`.
- **Dependencias:** 3.1, 3.2

#### 4.4 `RegistrationService`
- **Archivo:** `src/Module/Deed/Service/RegistrationService.php`
- **Tipo:** Crear
- **Constructor:** `DeedRepository`, `RegistrationRepository`, `DeedReferenceValidator`, `TransactionManager`.
- **Métodos:** igual que 4.3, con estas diferencias:
  - `create`: dentro de la transacción, después del lock: `assertRegistrationLimit(findDeedType($lock->deedTypeId), countByDeed + 1)`. El `FOR UPDATE` de la escritura serializa dos POST simultáneos, así que el conteo es fiable.
  - `delete`: borrado físico; sin 409 (no hay estatus).
- **Dependencias:** 3.1, 3.3, 4.1

### Fase 5: Controllers, rutas y wiring
Estimación: 2 h

#### 5.1 `DeedController` (extender)
- **Archivo:** `src/Module/Deed/Controller/DeedController.php`
- **Tipo:** Modificar
- **Descripción:** Nuevas acciones `show`, `store` (201 + body), `update` (200 + body), `finish` (200 + body). `parseId()` como en `UserController`: no entero → 400 `invalid_id`; `≤ 0` → 404 `deed_not_found`. Errores vía `DeedErrorResponse`.
- **Dependencias:** 4.2, 2.5

#### 5.2 `ParticipantController` y `RegistrationController`
- **Archivos:** `src/Module/Deed/Controller/{ParticipantController,RegistrationController}.php`
- **Tipo:** Crear
- **Descripción:** `index` (200, array), `store` (201 + objeto), `update` (200 + objeto), `destroy` (204). Parsean `{id}` y `{participantId}`/`{registrationId}` con el mismo `parseId`.
- **Dependencias:** 4.3, 4.4, 2.5

#### 5.3 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `ParticipantRepository`, `RegistrationRepository`, `DeedReferenceValidator`, `ParticipantService`, `RegistrationService`; actualizar las factories de `DeedRepository` y `DeedService`. Todos con `Connection::getInstance()` (misma conexión que `TransactionManager`, que ya está registrado).
- **Dependencias:** 3.x, 4.x

#### 5.4 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** 12 rutas con `->withMiddleware('auth')` (lista en `developer_notes.md`). Borrar del bloque comentado del final las líneas de deeds (`show/store/update/destroy`) para no dejar un `DELETE /deeds/{id}` sugerido que está fuera de alcance.
- **Dependencias:** 5.1, 5.2

### Fase 6: Tests y documentación
Estimación: 4 h

#### 6.1 Tests unitarios
- **Archivos:** `tests/Module/Deed/{Entity,DTO,Exception,Service}/...Test.php`
- **Tipo:** Crear / modificar `DeedListItemTest` y `DeedServiceTest`
- **Descripción:** Casos mínimos en `technical_acceptance_criteria.md`. El mock de `TransactionManager` ejecuta el callable (`willReturnCallback(fn (callable $op) => $op())`).
- **Dependencias:** Fases 1–4

#### 6.2 OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Paths `/deeds/{id}`, `/deeds/{id}/finish`, `/deeds/{id}/participants`, `/deeds/{id}/participants/{participantId}`, `/deeds/{id}/registrations`, `/deeds/{id}/registrations/{registrationId}` y `POST /deeds`. Schemas: `Deed`, `NamedReference`, `Participant`, `Registration`, `CreateDeedRequest`, `UpdateDeedRequest`, `ParticipantInput`, `RegistrationInput`, `DeedError` (`error`, `code`, `details`) con el enum de `code`. Documentar: PUT es reemplazo completo; PUT ignora `participants`, `registrations` e `isFinished`; `deedNumber` **no** es único.
- **Dependencias:** 5.4

## Orden de Implementación

1. **0.1 en paralelo** desde el primer día (afecta solo al deploy).
2. **Migraciones en local (0.2, 0.3)**: sin InnoDB no se puede probar el rollback ni el `FOR UPDATE`.
3. **Dominio (1.1–1.6)** + tests: piezas aisladas sin dependencias.
4. **DTOs (2.1–2.5)** + tests: es donde hay más casos de validación.
5. **2.6** (corrección del listado): pequeña y aprovecha `LegacyDate`.
6. **Repositories (3.2, 3.3, luego 3.1)**: probar contra la BD local.
7. **Services (4.1 → 4.2 → 4.3 → 4.4)** + tests con mocks.
8. **bootstrap (5.3) → Controllers (5.1, 5.2) → rutas (5.4)**: probar con curl, incluida la prueba de rollback (ver `developer_notes.md`).
9. **OpenAPI (6.2)**.

**Estimación total: ~20 h.**

## Catálogo de Errores (para frontend)

Todas las respuestas de error de este módulo traen `code`. El frontend debe decidir por `code`, nunca por el texto de `error`.

| HTTP | `code` | `error` | Causa |
|------|--------|---------|-------|
| 400 | `invalid_id` | `Invalid ID format` | `{id}`, `{participantId}` o `{registrationId}` no es entero |
| 400 | `validation_failed` | `Validation failed` | Body inválido; ver `errors` (claves por campo, p. ej. `deedNumber`, `participants.1.typeId`, `registration`) |
| 401 | — | (AuthMiddleware) | Token ausente, inválido o expirado |
| 404 | `deed_not_found` | `Deed not found` | No existe o es de otra notaría |
| 404 | `participant_not_found` | `Participant not found` | No existe o es de otra escritura |
| 404 | `registration_not_found` | `Registration not found` | No existe o es de otra escritura |
| 409 | `deed_already_finished` | `Deed is already finished` | `POST /finish` sobre escritura terminada |
| 409 | `participant_already_inactive` | `Participant is already inactive` | `DELETE` de participante inactivo |
| 409 | `participant_inactive` | `Participant is inactive` | `PUT` de participante inactivo |
| 422 | `act_unavailable` | `Act not found or inactive` | |
| 422 | `deed_type_unavailable` | `Deed type not found or inactive` | |
| 422 | `subdivision_unavailable` | `Subdivision not found or inactive` | |
| 422 | `subdivision_not_allowed` | `Deed type does not allow subdivisions` | `details.deedTypeId` |
| 422 | `registration_limit_exceeded` | `Deed type allows at most 2 registrations` | `details.maxRegistrations`, `details.currentRegistrations` |

Mensajes de campo (`errors.*`) principales: `Deed number is required`, `Deed number must not exceed 50 characters`, `Book is required`, `Deed date must be a valid date in YYYY-MM-DD format`, `Act ID must be a positive integer`, `Participants must be an array`, `Participants must not exceed 50 items`, `Participant name is required`, `Participant name must not exceed 250 characters`, `Invalid participant type`, `At least one registration field is required`.

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| La v1 usa algo específico de MyISAM y falla tras pasar a InnoDB | Baja | Alto | Tarea 0.1 antes del deploy. Respaldo de las 3 tablas y DOWN documentado |
| `ALTER ... ENGINE=InnoDB` bloquea las tablas mientras la v1 está en uso | Media | Bajo | Tablas pequeñas (segundos). Aplicar en ventana de bajo uso |
| La v1 sigue escribiendo `id_estatus = 0`, `id_fraccionamiento = 0/-1` y fechas cero durante la convivencia | Alta | Bajo | El código trata `≠ 1` como inactivo, `≤ 0` como "sin fraccionamiento" y fechas cero como `null` (ADR-004). La v3 escribe `-1` |
| Edición simultánea v1 + v3 de la misma escritura (la v1 no usa transacciones ni locks) | Media | Medio | Last-write-wins, igual que hoy entre usuarios de la v1. Riesgo temporal (~2 semanas) |
| La v1 reescribe participantes/inscripciones con `DELETE`+`INSERT` y cambia sus ids | Media | Medio | Confirmar en 0.1. El frontend relee la escritura antes de editar un subrecurso; un id viejo responde 404 limpio |
| Sin unicidad, se capturan números duplicados por error | Media | Bajo | Decisión del equipo (ADR-002): el duplicado es legítimo. El listado ya permite buscar por número |
| El frontend omite campos opcionales en el PUT y los borra | Media | Alto | PUT es reemplazo completo, documentado en OpenAPI; el GET devuelve todos los campos para reenviarlos. Caso en el test plan de QA |
| Escrituras legacy que ya incumplen `max_inscripciones` (6) quedan sin poder agregar inscripciones | Baja | Bajo | Es el comportamiento correcto; se puede editar o borrar las existentes. Documentado en FAQ |
| Excepción a mitad de un POST deja participantes sin escritura | Baja | Alto | InnoDB + `transactional`. Prueba de rollback obligatoria en QA |
| El JOIN con `usuarios_notaria` falla si un usuario se borra físicamente | Baja | Bajo | `LEFT JOIN`: `modifiedBy` = `{id, name: null}` |

## Decisiones de Arquitectura

### ADR-001: Participantes e inscripciones como subrecursos dentro del módulo `Deed`
- **Contexto:** El PO propone `ParticipantRepository/Service/Controller` y `RegistrationRepository/Service/Controller`. No existen ni tienen sentido fuera de una escritura y todas sus operaciones necesitan el lock y la auditoría de la escritura padre.
- **Decisión:** Clases separadas por responsabilidad, pero dentro de `src/Module/Deed/` (no módulos nuevos). La pertenencia a la notaría se valida siempre sobre la escritura padre.
- **Consecuencias:** Un solo módulo con 3 controllers; no hay dependencias entre módulos para estos subrecursos.

### ADR-002: `deedNumber` no es único (cambio respecto al PO, decisión del equipo)
- **Contexto:** El PO pide `UNIQUE (id_notaria, num_escritura)` y 409. Hay 365 grupos duplicados (757 filas). Ejemplo: la 23209 tiene 4 filas con el mismo número y fecha, y predios distintos (fracciones de un lote). Una escritura con varios inmuebles o actos se registra como varias filas. Ni `(número, tipo)`, ni `(número, libro)`, ni `(número, acto)` son únicos.
- **Decisión:** Sin índice UNIQUE, sin verificación en la app y sin 409. Se eliminan los escenarios 6 y 15 y el edge case "PUT con el mismo deedNumber".
- **Consecuencias:** QA no prueba 409 por número. El índice `(id_notaria, num_escritura)` tampoco se crea porque ninguna consulta busca por igualdad de número (el listado usa `LIKE`).

### ADR-003: Se aplican las reglas del tipo de escritura (cambio respecto al PO, decisión del equipo)
- **Contexto:** `tipo_escritura` tiene `max_inscripciones` y `activar_fraccionamientos`, que el PO no menciona. Los datos muestran que la v1 las respeta (6 y 1 excepciones en 19,432 escrituras).
- **Decisión:** 422 `registration_limit_exceeded` y 422 `subdivision_not_allowed`, con `code` y `details` para que el frontend muestre el problema. En el PUT solo se evalúan si cambia el tipo o el fraccionamiento. El límite en `POST /registrations` usa el tipo actual aunque esté inactivo.
- **Consecuencias:** `RegistrationService` necesita el tipo de la escritura y el `FOR UPDATE` para que el conteo sea fiable bajo concurrencia.

### ADR-004: Compatibilidad con la v1 durante la convivencia
- **Contexto:** La v1 seguirá escribiendo en estas tablas ~2 semanas mientras se valida la v3 y después se retira.
- **Decisión:** Mientras convivan:
  - Lectura: `id_estatus ≠ 1` = inactivo; `id_fraccionamiento ≤ 0` = sin fraccionamiento; `0000-00-00` = `null`; `terminada = 1` = terminada (otro valor = no terminada).
  - Escritura: la v3 guarda `-1` cuando no hay fraccionamiento (convención mayoritaria de la v1) y `2` al desactivar participantes.
  - Sin FKs, sin cambios de tipos ni de collation.
- **Consecuencias (deuda técnica, crear tickets al retirar la v1):** (1) `UPDATE escrituras SET id_fraccionamiento = NULL WHERE id_fraccionamiento <= 0` y cambiar la escritura a `NULL`; (2) repetir la normalización `0 → 2`; (3) fechas cero → `NULL` y activar `NO_ZERO_DATE`; (4) evaluar FKs entre escritura, participantes e inscripciones (ya son InnoDB); (5) limpiar los 22 grupos de duplicados idénticos.

### ADR-005: Errores con `code` estable sin modificar `Core\Http\Response`
- **Contexto:** El equipo pide errores que el frontend pueda identificar. Los módulos actuales solo devuelven `{error}`. Cambiar `Response::badRequest/conflict/...` afectaría a todos los módulos.
- **Decisión:** `DeedError` (enum con `value` = `code`) y el helper `DeedErrorResponse` del módulo construyen `{error, code, details?}` con `Response::json()`. Los 400 de validación agregan `code: "validation_failed"` y conservan `errors`.
- **Consecuencias:** Formato compatible (solo agrega campos). Si se adopta en el resto de la API, mover el helper a `Core` en un spec aparte.

### ADR-006: PUT de escritura como reemplazo completo de los campos editables
- **Contexto:** Los campos opcionales (`subdivisionId`, `deliveryDate`, etc.) deben poder vaciarse. Con PUT parcial no se distingue "no lo envié" de "quiero borrarlo" sin lógica extra.
- **Decisión:** Mismas reglas de requeridos que el POST. Los opcionales omitidos quedan en `null`. Se ignoran `participants`, `registrations`, `isFinished` y claves desconocidas. Lo mismo aplica al PUT de participante e inscripción.
- **Consecuencias:** El frontend reenvía el formulario completo. Mismo criterio que el spec 11.

### ADR-007: Nombre de participante > 250 caracteres → 400 (no se trunca)
- **Contexto:** El PO se contradice: la tabla de validaciones dice "max 250" y los edge cases dicen "truncar a 250".
- **Decisión:** 400 con `errors.name = "Participant name must not exceed 250 characters"`. Truncar en silencio perdería datos de un documento legal sin avisar.
- **Consecuencias:** QA prueba el 400, no el truncado.

### ADR-008: Etiqueta `type` de participante en español
- **Contexto:** CLAUDE.md pide salida en inglés, pero `type` es una etiqueta de dato de negocio, igual que `act.name` ("Compraventa").
- **Decisión:** `typeId` es el valor contractual (1/2); `type` es una etiqueta de visualización con los valores del PO (`Enajenante`/`Adquiriente`). Los nombres de los casos del enum sí van en inglés (`Seller`/`Buyer`).
- **Consecuencias:** El frontend debe usar `typeId` para la lógica.

### ADR-009: Respuestas con body y `finish` como acción
- **Contexto:** El PO pide 201/200 con el recurso. El servidor genera ids, resuelve nombres y actualiza la auditoría.
- **Decisión:** `POST /deeds` → 201 + `Deed`; `PUT` y `POST /finish` → 200 + `Deed`; subrecursos: `POST` → 201 + objeto, `PUT` → 200 + objeto, `DELETE` → 204. `finish` es una acción (`POST /deeds/{id}/finish`) porque es irreversible y no idempotente (la segunda vez da 409).
- **Consecuencias:** El Service relee después de escribir (3 queries extra en el caso de la escritura).

### ADR-010: Auditoría de la escritura también al modificar subrecursos
- **Contexto:** El PO pide actualizar `id_usuario` y `fecha_modificacion` "en cada operación". Participantes e inscripciones no tienen columnas de auditoría.
- **Decisión:** Toda escritura en un subrecurso hace `touch()` de la escritura padre en la misma transacción. `fecha_modificacion` se calcula en PHP con la zona horaria de la app (`America/Mexico_City`), igual que `ActRepository`, y no con `NOW()` del servidor de BD.
- **Consecuencias:** Agregar un participante cambia `modifiedBy`/`modifiedAt` de la escritura. Es lo esperado: refleja la última persona que tocó el expediente.
