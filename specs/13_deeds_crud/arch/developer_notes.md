# Notas para el Developer: CRUD Completo de Escrituras

## Contexto Rápido

Completar el módulo `Deed` con 12 rutas: detalle, alta (con participantes e inscripciones en una sola llamada), modificación, cierre irreversible (`finish`) y CRUD de los subrecursos participantes e inscripciones. Hay que leer el `implementation_plan.md` completo, pero estas son las 6 cosas que más fácil se rompen:

1. **El `UPDATE` del PUT no lleva `terminada`.** No basta con que el DTO ignore `isFinished`. Así se corrige el bug del legacy.
2. **Todo lo que escribe va dentro de `TransactionManager::transactional()`** y, salvo `POST /deeds`, empieza con `DeedRepository::lockForUpdate()`. Ese lock es también el 404 multi-tenant.
3. **`deedNumber` NO es único.** No implementes el 409 ni el índice UNIQUE del PO (ADR-002).
4. **Reglas del tipo de escritura** (`max_inscripciones`, `activar_fraccionamientos`) → 422 con `code` (ADR-003).
5. **Convivimos con la v1 ~2 semanas.** Lee `id_estatus ≠ 1` como inactivo, `id_fraccionamiento ≤ 0` como "sin fraccionamiento", `0000-00-00` como `null`, y escribe `-1` para "sin fraccionamiento" (ADR-004).
6. **Toda respuesta de error lleva `code`** (ADR-005). El frontend decide por `code`.

## Archivos Clave

| Archivo | Tipo | Propósito |
|---------|------|-----------|
| `.db/pending/20261112_100000_deeds_innodb_and_indexes.sql` | Crear | InnoDB + índice de inscripciones |
| `.db/pending/20261112_100100_normalize_participant_status.sql` | Crear | `id_estatus` 0 → 2 |
| `src/Module/Deed/Entity/ParticipantType.php` | Crear | Enum 1 = Seller (`Enajenante`), 2 = Buyer (`Adquiriente`) |
| `src/Module/Deed/Entity/LegacyDate.php` | Crear | `0000-00-00`/`''`/`null` → `null` |
| `src/Module/Deed/Entity/NamedReference.php` | Crear | `{id, ?name}` para act, deedType, subdivision, modifiedBy |
| `src/Module/Deed/Entity/Deed.php` | Crear | Read model del detalle |
| `src/Module/Deed/Entity/DeedLock.php` | Crear | Resultado del `SELECT ... FOR UPDATE` |
| `src/Module/Deed/Entity/Participant.php` | Crear | |
| `src/Module/Deed/Entity/Registration.php` | Crear | |
| `src/Module/Deed/Entity/DeedListItem.php` | Modificar | Usar `LegacyDate` (bug `-0001-11-30`) |
| `src/Module/Deed/Exception/DeedError.php` | Crear | Enum: `value` = `code`, `message()`, `httpStatus()` |
| `src/Module/Deed/Exception/DeedException.php` | Crear | `DeedError` + `details` |
| `src/Module/Deed/DTO/DeedFieldRules.php` | Crear | Reglas estáticas compartidas |
| `src/Module/Deed/DTO/DeedFields.php` | Crear | Campos editables de la escritura (VO) |
| `src/Module/Deed/DTO/CreateDeedRequest.php` | Crear | `DeedFields` + participants + registrations |
| `src/Module/Deed/DTO/UpdateDeedRequest.php` | Crear | Solo `DeedFields` |
| `src/Module/Deed/DTO/ParticipantInput.php`, `RegistrationInput.php` | Crear | VOs validados |
| `src/Module/Deed/DTO/SaveParticipantRequest.php`, `SaveRegistrationRequest.php` | Crear | POST/PUT de subrecursos |
| `src/Module/Deed/DTO/DeedResponse.php`, `ParticipantResponse.php`, `RegistrationResponse.php` | Crear | Mappers de salida |
| `src/Module/Deed/Repository/DeedRepository.php` | Modificar | `findHeaderByIdAndNotary`, `lockForUpdate`, `insert`, `update`, `markFinished`, `touch` |
| `src/Module/Deed/Repository/ParticipantRepository.php` | Crear | |
| `src/Module/Deed/Repository/RegistrationRepository.php` | Crear | |
| `src/Module/Deed/Service/DeedReferenceValidator.php` | Crear | Acto/tipo/fraccionamiento + reglas del tipo |
| `src/Module/Deed/Service/DeedService.php` | Modificar | `getById`, `create`, `update`, `finish` |
| `src/Module/Deed/Service/ParticipantService.php` | Crear | |
| `src/Module/Deed/Service/RegistrationService.php` | Crear | |
| `src/Module/Deed/Controller/DeedErrorResponse.php` | Crear | `{error, code, details?}` |
| `src/Module/Deed/Controller/DeedController.php` | Modificar | `show`, `store`, `update`, `finish` |
| `src/Module/Deed/Controller/ParticipantController.php` | Crear | `index`, `store`, `update`, `destroy` |
| `src/Module/Deed/Controller/RegistrationController.php` | Crear | `index`, `store`, `update`, `destroy` |
| `src/bootstrap.php` | Modificar | Wiring |
| `src/Config/routes.php` | Modificar | 12 rutas + limpiar comentarios de deeds |
| `docs/openapi.yaml` | Modificar | |

## Patrones a Seguir

| Qué | Dónde mirarlo |
|-----|---------------|
| Service con `TransactionManager`, `FOR UPDATE` y reglas "solo si cambia" | `src/Module/PaymentTransaction/Service/PaymentTransactionService.php` |
| Enum de errores con `message()` + `httpStatus()` | `src/Module/PaymentTransaction/Exception/TransactionError.php`, `src/Module/User/Exception/UserError.php` |
| Controller con `parseId()` y `errorResponse()` | `src/Module/User/Controller/UserController.php` |
| Reglas de campo estáticas + DTO `fromArray/isValid/getErrors` | `src/Module/PaymentTransaction/DTO/TransactionFieldRules.php`, `CreateTransactionRequest.php` |
| Parser de lista de items con errores `campo.N.sub` | `src/Module/PaymentTransaction/DTO/TransactionDetailsParser.php` |
| INSERT multi-row | `PaymentTransactionRepository::insertDetails()` |
| Mock de `TransactionManager` en tests | `tests/Module/PaymentTransaction/Service/PaymentTransactionServiceTest.php` |
| Placeholders únicos (`EMULATE_PREPARES=false`) | Comentario en `DeedRepository::buildFilter()` |

## Código de Referencia

### Controller (acción con subrecurso)

```php
final class ParticipantController
{
    private ParticipantService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(ParticipantService::class);
    }

    public function update(Request $request): Response
    {
        $deedId = $this->parseId($request, 'id', DeedError::DeedNotFound);
        if ($deedId instanceof Response) {
            return $deedId;
        }
        $participantId = $this->parseId($request, 'participantId', DeedError::ParticipantNotFound);
        if ($participantId instanceof Response) {
            return $participantId;
        }

        $dto = SaveParticipantRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return DeedErrorResponse::validation($dto->getErrors());
        }

        try {
            $participant = $this->service->update(
                $deedId,
                $participantId,
                $dto->toInput(),
                $request->getNotaryId(),
                $request->getUserId(),
            );
        } catch (DeedException $e) {
            return DeedErrorResponse::fromException($e);
        }

        return Response::json(ParticipantResponse::fromEntity($participant));
    }

    private function parseId(Request $request, string $param, DeedError $notFound): int|Response
    {
        $id = filter_var($request->getRouteParam($param), FILTER_VALIDATE_INT);

        if ($id === false) {
            return DeedErrorResponse::invalidId();
        }

        if ($id <= 0) {
            return DeedErrorResponse::fromException(new DeedException($notFound));
        }

        return $id;
    }
}
```

> `parseId()` se repite en los 3 controllers. Si molesta, muévelo a un trait `ParsesDeedIds` dentro de `Module/Deed/Controller/`.

### `DeedErrorResponse`

```php
final class DeedErrorResponse
{
    public static function fromException(DeedException $e): Response
    {
        $body = ['error' => $e->getMessage(), 'code' => $e->error->value];
        if ($e->details !== []) {
            $body['details'] = $e->details;
        }

        return Response::json($body, $e->error->httpStatus());
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors): Response
    {
        return Response::json([
            'error' => 'Validation failed',
            'code' => 'validation_failed',
            'errors' => $errors,
        ], 400);
    }

    public static function invalidId(): Response
    {
        return Response::json(['error' => 'Invalid ID format', 'code' => 'invalid_id'], 400);
    }
}
```

### `DeedException`

```php
final class DeedException extends \DomainException
{
    /** @param array<string, int> $details */
    public function __construct(
        public readonly DeedError $error,
        public readonly array $details = [],
    ) {
        parent::__construct($error->message($details));
    }
}

// DeedError::message(array $details = []): string
//   self::RegistrationLimitExceeded => sprintf('Deed type allows at most %d registrations', $details['maxRegistrations'] ?? 0),
```

### Service: PUT de escritura

```php
public function update(int $id, UpdateDeedRequest $dto, int $notaryId, int $userId): Deed
{
    $f = $dto->fields;

    $this->transactions->transactional(function () use ($id, $f, $notaryId, $userId): void {
        $current = $this->deeds->lockForUpdate($id, $notaryId)
            ?? throw new DeedException(DeedError::DeedNotFound);

        $actChanged = $f->actId !== $current->actId;
        $typeChanged = $f->deedTypeId !== $current->deedTypeId;
        $subdivisionChanged = $f->subdivisionId !== $current->subdivisionId;

        if ($actChanged) {
            $this->references->requireActiveAct($f->actId, $notaryId);
        }
        if ($subdivisionChanged && $f->subdivisionId !== null) {
            $this->references->requireActiveSubdivision($f->subdivisionId, $notaryId);
        }

        $type = $typeChanged
            ? $this->references->requireActiveDeedType($f->deedTypeId, $notaryId)
            : $this->references->findDeedType($f->deedTypeId, $notaryId);

        if (($typeChanged || $subdivisionChanged) && $type !== null) {
            $this->references->assertSubdivisionAllowed($type, $f->subdivisionId);
        }
        if ($typeChanged) {
            $this->references->assertRegistrationLimit($type, $this->registrations->countByDeed($id));
        }

        // Never touches `terminada` (legacy bug fix)
        $this->deeds->update($id, $notaryId, $f, $userId, new \DateTimeImmutable());
    });

    return $this->getById($id, $notaryId);
}
```

### Service: alta de inscripción (lock + límite + auditoría)

```php
public function create(int $deedId, RegistrationInput $in, int $notaryId, int $userId): Registration
{
    $id = $this->transactions->transactional(function () use ($deedId, $in, $notaryId, $userId): int {
        $deed = $this->lockDeed($deedId, $notaryId);

        // Counted after the lock: concurrent POSTs on the same deed are serialized
        $type = $deed->deedTypeId !== null
            ? $this->references->findDeedType($deed->deedTypeId, $notaryId)
            : null;
        $this->references->assertRegistrationLimit($type, $this->registrations->countByDeed($deedId) + 1);

        $id = $this->registrations->insert($deedId, $in);
        $this->deeds->touch($deedId, $notaryId, $userId, new \DateTimeImmutable());

        return $id;
    });

    return $this->registrations->findByIdAndDeed($id, $deedId)
        ?? throw new \LogicException('Registration not found after insert');
}
```

### Repository: UPDATE de escritura (sin `terminada`)

```php
public function update(int $id, int $notaryId, DeedFields $f, int $userId, \DateTimeImmutable $now): void
{
    $sql = 'UPDATE escrituras
            SET num_escritura = :deedNumber, libro = :book, fecha_escritura = :deedDate,
                id_acto = :actId, id_tipo_escitura = :deedTypeId, id_fraccionamiento = :subdivisionId,
                predio = :property, fecha_entrega = :deliveryDate, entrego = :deliveredBy,
                observaciones = :observations,
                id_usuario = :userId, fecha_modificacion = :modifiedAt
            WHERE id_escritura = :id AND id_notaria = :notaryId';

    $this->db->prepare($sql)->execute([
        'id' => $id,
        'notaryId' => $notaryId,
        'deedNumber' => $f->deedNumber,
        'book' => $f->book,
        'deedDate' => $f->deedDate,
        'actId' => $f->actId,
        'deedTypeId' => $f->deedTypeId,
        'subdivisionId' => $f->subdivisionId ?? self::NO_SUBDIVISION, // -1, legacy convention (ADR-004)
        'property' => $f->property,
        'deliveryDate' => $f->deliveryDate,
        'deliveredBy' => $f->deliveredBy,
        'observations' => $f->observations,
        'userId' => $userId,
        'modifiedAt' => $now->format('Y-m-d H:i:s'),
    ]);
}
```

## Base de Datos

### Tablas Involucradas

| Tabla | Uso | Columnas relevantes |
|-------|-----|---------------------|
| `escrituras` | Lectura/escritura | `id_escritura`, `id_notaria`, `num_escritura`, `libro`, `fecha_escritura`, `id_acto`, **`id_tipo_escitura`** (typo), `id_fraccionamiento` (`-1`/`0` = ninguno), `predio`, `fecha_entrega`, `entrego`, `observaciones`, `terminada`, `id_usuario`, `fecha_modificacion` |
| `participantes_escritura` | Lectura/escritura | `id_participante`, `id_escritura`, `nombre_participante`, `id_tipo_participante` (1/2), `id_estatus` (1 activo; 0 o 2 inactivo) |
| `inscripciones_escritura` | Lectura/escritura | `id_inscripcion`, `id_escritura`, `numero_inscripcion`, `seccion_inscripcion`, `tomo_inscripcion`, `fecha_inscripcion` |
| `actos_notaria` | Solo lectura | `id_acto`, `id_notaria`, `nombre_acto`, `id_estatus` |
| `tipo_escritura` | Solo lectura | **`id_tipo_escitura`**, `id_notaria`, `nombre_tipo`, `max_inscripciones`, `activar_fraccionamientos`, `id_estatus` |
| `fraccionamientos` | Solo lectura | `id_fraccionamiento`, `id_notaria`, `nombre_fraccionamiento`, `id_estatus` |
| `usuarios_notaria` | Solo lectura | `id_usuario`, `id_notaria`, `nombre`, `apellido_paterno`, `apellido_materno` |

> Ojo: CLAUDE.md y el PO hablan de `tipos_escritura`, `usuarios` e `inscripciones`. Los nombres reales son `tipo_escritura`, `usuarios_notaria` e `inscripciones_escritura`. El estatus de los catálogos es `id_estatus` (el PO dice `estatus`).

### Queries Sugeridas

```sql
-- Cabecera del detalle (1 query). Todos los JOIN filtran por la notaría de la escritura.
SELECT e.id_escritura, e.id_notaria, e.num_escritura, e.libro, e.fecha_escritura,
       e.id_acto, a.nombre_acto,
       e.id_tipo_escitura, t.nombre_tipo,
       e.id_fraccionamiento, f.nombre_fraccionamiento,
       e.predio, e.fecha_entrega, e.entrego, e.observaciones, e.terminada,
       e.id_usuario, CONCAT_WS(' ', u.nombre, u.apellido_paterno, u.apellido_materno) AS nombre_usuario,
       e.fecha_modificacion
FROM escrituras e
LEFT JOIN actos_notaria a     ON a.id_acto = e.id_acto                     AND a.id_notaria = e.id_notaria
LEFT JOIN tipo_escritura t    ON t.id_tipo_escitura = e.id_tipo_escitura   AND t.id_notaria = e.id_notaria
LEFT JOIN fraccionamientos f  ON f.id_fraccionamiento = e.id_fraccionamiento AND f.id_notaria = e.id_notaria
LEFT JOIN usuarios_notaria u  ON u.id_usuario = e.id_usuario               AND u.id_notaria = e.id_notaria
WHERE e.id_escritura = :id AND e.id_notaria = :notaryId;

-- Participantes activos
SELECT id_participante, id_escritura, nombre_participante, id_tipo_participante, id_estatus
FROM participantes_escritura
WHERE id_escritura = :deedId AND id_estatus = 1
ORDER BY id_participante;

-- Inscripciones
SELECT id_inscripcion, id_escritura, numero_inscripcion, seccion_inscripcion, tomo_inscripcion, fecha_inscripcion
FROM inscripciones_escritura
WHERE id_escritura = :deedId
ORDER BY id_inscripcion;

-- Lock de la escritura padre (dentro de transactional)
SELECT id_escritura, id_acto, id_tipo_escitura, id_fraccionamiento, terminada
FROM escrituras
WHERE id_escritura = :id AND id_notaria = :notaryId
FOR UPDATE;

-- Auditoría desde subrecursos
UPDATE escrituras SET id_usuario = :userId, fecha_modificacion = :modifiedAt
WHERE id_escritura = :id AND id_notaria = :notaryId;
```

> Si `CONCAT_WS` con `nombre` NULL da un nombre vacío, devolver `name: null` cuando el resultado sea `''` o el JOIN no encontró usuario.

### Migraciones Necesarias

`.db/pending/20261112_100000_deeds_innodb_and_indexes.sql`

```sql
-- Migration: deeds_innodb_and_indexes
-- Description: Moves escrituras, participantes_escritura and inscripciones_escritura to InnoDB
--              (atomic deed + participants + registrations writes, SELECT ... FOR UPDATE) and
--              indexes inscripciones_escritura by deed.
--              NO unique index on (id_notaria, num_escritura): duplicated numbers are legitimate (ADR-002).
-- Author: <dev>
-- Date: 2026-11-12

-- PRE-DEPLOY (production): task 0.1 answered; backup of the 3 tables; low-usage window (v1 still writes here).

-- UP
ALTER TABLE escrituras ENGINE=InnoDB;
ALTER TABLE participantes_escritura ENGINE=InnoDB;
ALTER TABLE inscripciones_escritura ENGINE=InnoDB;

ALTER TABLE inscripciones_escritura
    ADD INDEX idx_inscripciones_escritura (id_escritura);

-- DOWN (rollback)
-- ALTER TABLE inscripciones_escritura DROP INDEX idx_inscripciones_escritura;
-- ALTER TABLE inscripciones_escritura ENGINE=MyISAM;
-- ALTER TABLE participantes_escritura ENGINE=MyISAM;
-- ALTER TABLE escrituras ENGINE=MyISAM;
```

`.db/pending/20261112_100100_normalize_participant_status.sql`

```sql
-- Migration: normalize_participant_status
-- Description: Legacy "deleted" participants (id_estatus = 0) become inactive (2).
--              Cosmetic while v1 coexists (it may keep writing 0); v3 code treats any value <> 1 as inactive.
--              Re-run after v1 is retired.
-- Author: <dev>
-- Date: 2026-11-12

-- UP
UPDATE participantes_escritura SET id_estatus = 2 WHERE id_estatus = 0;

-- DOWN (not reversible: 0 and 2 are indistinguishable afterwards; both mean inactive)
```

Verificación después de aplicar:

```sql
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('escrituras', 'participantes_escritura', 'inscripciones_escritura');

SELECT id_estatus, COUNT(*) FROM participantes_escritura GROUP BY id_estatus;  -- sin 0
```

### Rutas

```php
// Deeds routes (protected)
$router->get('/api/v3/deeds', [DeedController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/deeds/{id}', [DeedController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/deeds', [DeedController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deeds/{id}', [DeedController::class, 'update'])->withMiddleware('auth');
$router->post('/api/v3/deeds/{id}/finish', [DeedController::class, 'finish'])->withMiddleware('auth');

$router->get('/api/v3/deeds/{id}/participants', [ParticipantController::class, 'index'])->withMiddleware('auth');
$router->post('/api/v3/deeds/{id}/participants', [ParticipantController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deeds/{id}/participants/{participantId}', [ParticipantController::class, 'update'])->withMiddleware('auth');
$router->delete('/api/v3/deeds/{id}/participants/{participantId}', [ParticipantController::class, 'destroy'])->withMiddleware('auth');

$router->get('/api/v3/deeds/{id}/registrations', [RegistrationController::class, 'index'])->withMiddleware('auth');
$router->post('/api/v3/deeds/{id}/registrations', [RegistrationController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deeds/{id}/registrations/{registrationId}', [RegistrationController::class, 'update'])->withMiddleware('auth');
$router->delete('/api/v3/deeds/{id}/registrations/{registrationId}', [RegistrationController::class, 'destroy'])->withMiddleware('auth');
```

### Wiring (`bootstrap.php`)

```php
ServiceLocator::register(ParticipantRepository::class, fn () => new ParticipantRepository(Connection::getInstance()));
ServiceLocator::register(RegistrationRepository::class, fn () => new RegistrationRepository(Connection::getInstance()));

ServiceLocator::register(DeedReferenceValidator::class, fn () => new DeedReferenceValidator(
    ServiceLocator::get(ActRepository::class),
    ServiceLocator::get(DeedTypeRepository::class),
    ServiceLocator::get(SubdivisionRepository::class),
));

ServiceLocator::register(DeedService::class, fn () => new DeedService(
    ServiceLocator::get(DeedRepository::class),
    ServiceLocator::get(ParticipantRepository::class),
    ServiceLocator::get(RegistrationRepository::class),
    ServiceLocator::get(DeedReferenceValidator::class),
    ServiceLocator::get(TransactionManager::class),
));

// ParticipantService(DeedRepository, ParticipantRepository, TransactionManager)
// RegistrationService(DeedRepository, RegistrationRepository, DeedReferenceValidator, TransactionManager)
```

`TransactionManager` ya está registrado (spec 11). Todos usan `Connection::getInstance()`: es indispensable que sea la misma conexión PDO.

### Prueba de rollback (QA y developer)

1. Agrega temporalmente `throw new \RuntimeException('boom');` al final de `RegistrationRepository::insertMany()`.
2. `POST /api/v3/deeds` con 2 participantes y 1 inscripción → 500.
3. Verifica que no existe la escritura ni sus participantes (`SELECT ... WHERE num_escritura = '<el enviado>' ORDER BY id_escritura DESC`).
4. Quita el `throw`.

## Checklist Pre-PR
- [ ] Migraciones aplicadas en local y movidas a `.db/applied/` según el flujo del repo
- [ ] Código sigue la arquitectura definida (sin lógica en Controllers, sin transacciones en Repositories)
- [ ] `grep -n terminada src/Module/Deed/Repository/DeedRepository.php` → solo aparece en SELECT, en `insert` (= 0) y en `markFinished`
- [ ] Tests escritos y pasando (`composer test` o `vendor/bin/phpunit`)
- [ ] Sin warnings de PHP
- [ ] Prueba de rollback hecha
- [ ] 12 rutas probadas con curl, incluido acceso cruzado entre notarías
- [ ] `GET /deeds` (listado) sin regresiones
- [ ] `docs/openapi.yaml` actualizado
- [ ] Respuestas de la tarea 0.1 en la descripción del PR
- [ ] Revisé `technical_acceptance_criteria.md`

## Preguntas Frecuentes

**P: ¿Por qué no hay 409 por número de escritura si el PO lo pide?**
R: En la BD real hay 365 números repetidos: una escritura con varios inmuebles se registra como varias filas. El equipo decidió quitar la regla (ADR-002). No la implementes ni crees el índice.

**P: Una escritura legacy tiene un acto inactivo. ¿El PUT falla?**
R: No, mientras el PUT no cambie el acto. Las validaciones de catálogo solo aplican a lo que cambia (tabla "Validación de referencias" del plan).

**P: Una escritura legacy tiene 3 inscripciones y su tipo permite 2. ¿Qué pasa?**
R: Se puede editar la escritura sin cambiar el tipo, editar o borrar inscripciones, pero `POST /registrations` responde 422 `registration_limit_exceeded` hasta que queden menos de 2.

**P: ¿Por qué guardo `-1` y no `NULL` cuando no hay fraccionamiento?**
R: Es lo que escribe la v1 en el 97 % de los casos y sigue en producción ~2 semanas. Al retirarla se normaliza a `NULL` (deuda técnica en ADR-004). Al leer, cualquier `≤ 0` es "sin fraccionamiento".

**P: ¿El participante con `id_estatus = 0` es activo?**
R: No. Activo es exactamente `1`. La migración convierte `0` en `2`, pero la v1 puede seguir escribiendo `0`.

**P: ¿El `type` del participante va en inglés?**
R: No: `Enajenante`/`Adquiriente` como dice el PO, porque es una etiqueta de datos (ADR-008). Los casos del enum PHP sí van en inglés (`Seller`/`Buyer`). El frontend usa `typeId` para la lógica.

**P: ¿Puedo modificar participantes o inscripciones de una escritura terminada?**
R: Sí. El PO permite editar escrituras terminadas (escenario 14). `finish` solo bloquea el estado, no los datos.

**P: ¿Por qué el `FOR UPDATE` si ya filtro por notaría?**
R: Hace tres cosas a la vez: confirma que la escritura existe en la notaría (404), serializa operaciones concurrentes sobre la misma escritura (conteo de inscripciones, doble `finish`) y bloquea la fila que después actualiza `touch()`. Requiere InnoDB.

**P: ¿`fecha_modificacion` con `NOW()` o desde PHP?**
R: Desde PHP (`new \DateTimeImmutable()`, zona horaria de la app), como `ActRepository`. La zona horaria del servidor de BD puede no ser `America/Mexico_City`.

**P: Un JSON escalar (`"abc"`) como body devuelve 500.**
R: Es un problema de `Core\Http\Request` ya documentado por QA en el spec 12 (`06_scalar_body_preexisting_core_issue.log`). Fuera de alcance; no lo arregles en este PR.

## Contacto
- Dudas de negocio: `specs/13_deeds_crud/po/`
- Dudas de arquitectura: `specs/13_deeds_crud/arch/implementation_plan.md` (ADRs) o consultar con el arquitecto
