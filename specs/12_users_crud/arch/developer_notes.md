# Notas para el Developer: Users CRUD

## Contexto Rápido

CRUD de usuarios de la notaría (`usuarios_notaria`): listar, ver, crear y editar (incluye desactivar con `status: 2`). Es un módulo de una sola tabla, sin transacciones. Lo delicado está en tres reglas:

1. **Contraseñas**: se hashean con bcrypt cost 10 al crear y **nunca** se leen ni devuelven.
2. **Username**: único global y case-insensitive; su formato solo se valida en PUT si cambia (hay usernames legacy inválidos).
3. **Auto-desactivación**: un usuario no puede ponerse `status: 2` a sí mismo (403).

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/User/Entity/AccessCode.php` | Enum `ESC`/`PAG`; orden canónico y conversión CSV ↔ array |
| `src/Module/User/Entity/User.php` | Entity sin contraseñas; `status()` normaliza 0/NULL → 2 |
| `src/Module/User/Exception/UserError.php` | Enum de errores de dominio con mensaje y HTTP |
| `src/Module/User/Exception/UserException.php` | `\DomainException` que envuelve un `UserError` |
| `src/Module/User/Exception/DuplicateUsernameException.php` | 1062 del Repository; la consume el Service |
| `src/Module/User/DTO/UserFieldRules.php` | Reglas de validación reutilizadas por POST y PUT |
| `src/Module/User/DTO/CreateUserRequest.php` | Validación del POST |
| `src/Module/User/DTO/UpdateUserRequest.php` | Validación del PUT (parcial) |
| `src/Module/User/DTO/UserResponse.php` | Serialización (exactamente 8 claves) |
| `src/Module/User/Repository/UserRepository.php` | Acceso a `usuarios_notaria` |
| `src/Module/User/Service/UserService.php` | Reglas de negocio |
| `src/Module/User/Controller/UserController.php` | HTTP |
| `.db/pending/20261111_120000_add_unique_username_to_usuarios_notaria.sql` | Índices |

## Patrones a Seguir

- `src/Module/PaymentClient/` - Estructura completa: `ClientFieldRules`, DTOs con `fromArray/isValid/getErrors`, `parseId`, Entity `fromRow`, `NAME_EXPR` para ordenar con collation CI.
- `src/Module/PaymentTransaction/Exception/` - Enum de errores + excepción de dominio + excepción de duplicado.
- `src/Module/PaymentTransaction/Repository/PaymentTransactionRepository.php` - Captura del 1062 con `errorInfo[1]`.
- `src/Module/PaymentTransaction/Controller/PaymentTransactionController.php` - Mapper `errorResponse()` con `match`.
- `src/Module/Subdivision/DTO/SubdivisionResponse.php` - Formato de fechas `Y-m-d\TH:i:s`.

## Código de Referencia

### Enum de access
```php
enum AccessCode: string
{
    case Deeds = 'ESC';
    case Payments = 'PAG';

    /** @return string[] in canonical order */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @param string[] $codes already validated */
    public static function toCsv(array $codes): string
    {
        return implode(',', array_values(array_intersect(self::values(), $codes)));
    }

    /** @return string[] */
    public static function fromCsv(?string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $csv)), fn ($c) => $c !== ''));
    }
}
```

### Errores de dominio
```php
enum UserError: string
{
    case UserNotFound = 'user_not_found';
    case UsernameTaken = 'username_taken';
    case CannotDeactivateSelf = 'cannot_deactivate_self';
    case InvalidUsername = 'invalid_username';

    public function message(): string
    {
        return match ($this) {
            self::UserNotFound => 'User not found',
            self::UsernameTaken => 'Username already exists',
            self::CannotDeactivateSelf => 'Cannot deactivate your own account',
            self::InvalidUsername => UserFieldRules::USERNAME_FORMAT_MESSAGE,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidUsername => 400,
            self::CannotDeactivateSelf => 403,
            self::UserNotFound => 404,
            self::UsernameTaken => 409,
        };
    }
}
```

### Estructura del Controller
```php
final class UserController
{
    private const NOT_FOUND = 'User not found';

    private UserService $service;

    public function __construct()
    {
        $this->service = ServiceLocator::get(UserService::class);
    }

    public function store(Request $request): Response
    {
        $dto = CreateUserRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $user = $this->service->create($dto, $request->getNotaryId());
        } catch (UserException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::created(UserResponse::fromEntity($user));
    }

    public function update(Request $request): Response
    {
        $id = $this->parseId($request);
        if ($id instanceof Response) {
            return $id;
        }

        $dto = UpdateUserRequest::fromArray($request->getBody());
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        try {
            $user = $this->service->update($id, $dto, $request->getNotaryId(), $request->getUserId());
        } catch (UserException $e) {
            return $this->errorResponse($e->error);
        }

        return Response::json(UserResponse::fromEntity($user));
    }

    private function errorResponse(UserError $error): Response
    {
        return match ($error) {
            UserError::InvalidUsername => Response::badRequest('Validation failed', ['username' => $error->message()]),
            UserError::CannotDeactivateSelf => Response::forbidden($error->message()),
            UserError::UserNotFound => Response::notFound($error->message()),
            UserError::UsernameTaken => Response::conflict($error->message()),
        };
    }

    // index(), show(), parseId(): igual que PaymentClientController
}
```

### Estructura del Service
```php
final class UserService
{
    private const BCRYPT_COST = 10;

    public function __construct(private readonly UserRepository $repository)
    {
    }

    public function create(CreateUserRequest $dto, int $notaryId): User
    {
        if ($this->repository->usernameExists($dto->username)) {
            throw new UserException(UserError::UsernameTaken);
        }

        $hash = password_hash($dto->password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        try {
            $id = $this->repository->create($notaryId, $dto, $hash);
        } catch (DuplicateUsernameException) {
            throw new UserException(UserError::UsernameTaken);
        }

        return $this->getById($id, $notaryId);
    }

    public function update(int $id, UpdateUserRequest $dto, int $notaryId, int $actorUserId): User
    {
        $existing = $this->getById($id, $notaryId);

        if ($id === $actorUserId && $dto->status === User::STATUS_INACTIVE) {
            throw new UserException(UserError::CannotDeactivateSelf);
        }

        // Legacy usernames may not match the current rule; only validate when it actually changes.
        if ($dto->username !== null && $dto->username !== $existing->username) {
            if (UserFieldRules::username($dto->username) !== null) {
                throw new UserException(UserError::InvalidUsername);
            }
            if ($this->repository->usernameExists($dto->username, $id)) {
                throw new UserException(UserError::UsernameTaken);
            }
        }

        try {
            $this->repository->update(new User(
                id: $id,
                notaryId: $notaryId,
                username: $dto->username ?? $existing->username,
                firstName: $dto->firstName ?? $existing->firstName,
                paternalLastName: $dto->paternalLastName ?? $existing->paternalLastName,
                maternalLastName: $dto->maternalLastName ?? $existing->maternalLastName,
                access: $dto->access ?? $existing->access,
                statusId: $dto->status ?? $existing->statusId,
                lastAccess: $existing->lastAccess,
            ));
        } catch (DuplicateUsernameException) {
            throw new UserException(UserError::UsernameTaken);
        }

        return $this->getById($id, $notaryId);
    }
}
```

> `$dto->access` en `UpdateUserRequest` es `?string[]` (ya validado y deduplicado). El Repository convierte con `AccessCode::toCsv()` al escribir. En `CreateUserRequest` puede guardarse igual como array; lo importante es que la conversión a CSV ocurra en un solo lugar (Repository).

## Base de Datos

### Tablas Involucradas
- `usuarios_notaria` - MyISAM, `utf8mb3_bin`. Ver mapeo completo en `po/technical_context.md`.
  - `apellido_materno` es `NOT NULL DEFAULT ''`: guardar `''`, nunca `NULL`.
  - `id_estatus` es nullable (`DEFAULT 1`); hay un `0` en local.
  - `user_access` es `NOT NULL DEFAULT 'ESC'`.

### Queries Sugeridas
```sql
-- Columnas de lectura (NUNCA contrasenia ni password_hash)
-- id_usuario, id_notaria, usuario, nombre, apellido_paterno, apellido_materno,
-- user_access, id_estatus, ultimo_acceso

-- Listado
SELECT <COLUMNS> FROM usuarios_notaria
WHERE id_notaria = :notaryId
  [AND id_estatus = 1]                              -- status=1
  [AND (id_estatus IS NULL OR id_estatus <> 1)]     -- status=2
ORDER BY CONVERT(apellido_paterno USING utf8mb4) COLLATE utf8mb4_unicode_ci,
         CONVERT(apellido_materno USING utf8mb4) COLLATE utf8mb4_unicode_ci,
         CONVERT(nombre USING utf8mb4) COLLATE utf8mb4_unicode_ci,
         id_usuario;

-- Unicidad global case-insensitive
SELECT 1 FROM usuarios_notaria
WHERE LOWER(usuario) = LOWER(:username)
  [AND id_usuario <> :excludeId]
LIMIT 1;

-- Alta
INSERT INTO usuarios_notaria
    (id_notaria, nombre, apellido_paterno, apellido_materno, usuario,
     contrasenia, password_hash, ultimo_acceso, id_estatus, user_access)
VALUES
    (:notaryId, :firstName, :paternalLastName, :maternalLastName, :username,
     NULL, :passwordHash, NULL, :status, :access);

-- Edición
UPDATE usuarios_notaria
SET usuario = :username, nombre = :firstName, apellido_paterno = :paternalLastName,
    apellido_materno = :maternalLastName, user_access = :access, id_estatus = :status
WHERE id_usuario = :id AND id_notaria = :notaryId;
```

### Migraciones Necesarias

`.db/pending/20261111_120000_add_unique_username_to_usuarios_notaria.sql`:

```sql
-- Migration: add_unique_username_to_usuarios_notaria
-- Description: Enforces globally unique usernames (login has no notary context) and adds a
--              (id_notaria, id_estatus) index for the users list.
--              Case-insensitive uniqueness is enforced by the application (spec 12, ADR-003);
--              this index only blocks exact duplicates and races.
-- Author: Roberto Trejo
-- Date: 2026-11-11

-- PRE-CHECK A (must return 0 rows; if not, STOP and escalate: business must pick which user to rename)
-- SELECT usuario, COUNT(*) c, GROUP_CONCAT(id_usuario) ids
-- FROM usuarios_notaria GROUP BY usuario HAVING c > 1;

-- PRE-CHECK B (informative; does not block the index, report to PO if any)
-- SELECT LOWER(usuario) u, COUNT(*) c, GROUP_CONCAT(id_usuario) ids
-- FROM usuarios_notaria GROUP BY LOWER(usuario) HAVING c > 1;

-- UP
ALTER TABLE usuarios_notaria
    ADD UNIQUE INDEX uq_usuarios_notaria_usuario (usuario),
    ADD INDEX idx_usuarios_notaria_notary_status (id_notaria, id_estatus);

-- DOWN (rollback)
-- ALTER TABLE usuarios_notaria
--     DROP INDEX idx_usuarios_notaria_notary_status,
--     DROP INDEX uq_usuarios_notaria_usuario;
```

Resultado en local (2026-11-11): pre-check A y B en 0 filas.

## Request/Response de Referencia

### POST /api/v3/users → 201
```json
{
  "id": 13,
  "username": "maria.lopez",
  "firstName": "María",
  "paternalLastName": "López",
  "maternalLastName": "García",
  "access": ["ESC", "PAG"],
  "status": 1,
  "lastAccess": null
}
```

### PUT /api/v3/users/7 con `{"firstName": "Fátima"}` → 200
El usuario 7 tiene `id_estatus = 0` en BD: responde `"status": 2` y en BD sigue `0`.

### Errores
```json
{ "error": "Validation failed", "errors": { "access": "At least one access code is required" } }
{ "error": "Cannot deactivate your own account" }
{ "error": "Username already exists" }
```

## Checklist Pre-PR
- [ ] Código sigue la arquitectura definida (Controller → Service → Repository)
- [ ] Tests escritos y pasando (`technical_acceptance_criteria.md`)
- [ ] Sin warnings de PHP
- [ ] Migración aplicada en local y movida según el flujo de `.db/README.md`
- [ ] `docs/openapi.yaml` actualizado
- [ ] Bloque `// Users` eliminado del grupo comentado en `routes.php`
- [ ] Verificado manualmente que ninguna respuesta (incluidos errores) contiene contraseñas
- [ ] Login funciona con un usuario creado por el nuevo endpoint

## Preguntas Frecuentes

**P: ¿Por qué no reutilizar `UserAuthRepository`?**
R: Lee contraseñas y está pensado para login (JOIN con `notarias`, sin filtro de notaría). El CRUD necesita lo contrario. Ver ADR-001.

**P: ¿El username se trimea o se pasa a minúsculas?**
R: No. Se guarda exactamente como llega; el regex ya rechaza espacios. La unicidad compara con `LOWER()`, pero el login sigue siendo exact-match: `Maria.Lopez` debe iniciar sesión como `Maria.Lopez`.

**P: ¿La unicidad es por notaría?**
R: No, global. El login solo recibe `username`, sin notaría.

**P: El usuario 6 (`FANNY RECEPCION`) no cumple el formato. ¿Se puede editar?**
R: Sí, mientras no cambie su username (comparación exacta). Si se envía otro username, debe cumplir la regla. Ver ADR-004.

**P: ¿Qué pasa si envío `password` en el PUT?**
R: 400 con `errors.password = "Password cannot be changed through this endpoint"`. Ver ADR-005.

**P: Desactivé a un usuario y sigue pudiendo usar el API. ¿Es un bug?**
R: No. Su access token es válido hasta que expire (≤ 2 h, `JWT_EXPIRATION`); el refresh ya falla porque `findActiveById` exige `id_estatus = 1`. Lo mismo aplica a cambios de `access`. La revocación inmediata está fuera de alcance.

**P: ¿`status: "1"` (string) es válido?**
R: No, igual que en Clients: `status` es int estricto. En el query string (`?status=1`) sí llega como string y lo valida el Controller.

**P: ¿Hay que pasar la tabla a InnoDB?**
R: No. Cada operación escribe una sola fila; no hay atomicidad que garantizar. Ver ADR-008.

**P: ¿Un usuario puede quitarse su propio acceso `ESC`/`PAG`?**
R: Sí; el PO solo prohíbe la auto-desactivación. Lo verá reflejado en su siguiente refresh.

## Contacto
Para dudas de negocio: Revisar `specs/12_users_crud/po/`
Para dudas de arquitectura: Revisar `specs/12_users_crud/arch/implementation_plan.md` (ADRs) o consultar con el arquitecto
