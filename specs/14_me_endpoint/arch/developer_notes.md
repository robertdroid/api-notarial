# Notas para el Developer: Endpoint Me

## Contexto Rápido

`GET /api/v3/me` → `{user, notary}` de la sesión, en una llamada. Módulo nuevo `Me`, solo lectura, 2 queries por PK, sin migraciones. Lee `implementation_plan.md`; lo que más fácil se rompe:

1. **El id viene solo del token.** El controller no toca route params, query ni body.
2. **No uses `findByIdAndNotary` ni `findByIdActive`.** Dan `404` donde el PO pide `403`. Usa los nuevos `UserRepository::findById` y `NotaryRepository::findById` (ADR-002).
3. **Orden de validación importa:** usuario → comparar `notaryId` → estado usuario → notaría → estado notaría. Si el `notaryId` no coincide, **no leas la notaría**.
4. **Inactivo = `status() !== 1`** para usuario (cubre `0`/`NULL`). Notaría: `status !== 1`.
5. **`fullName` va en `User`, no en `UserResponse`.** `/users` no debe cambiar.
6. **`user` no lleva `notaryId`** ni password/hash; `notary` no lleva el nombre del logo (`hasLogo` ya lo resuelve `NotaryResponse`).
7. La documentación del repo dice `usuarios`/`notaria`, pero las tablas reales son **`usuarios_notaria`** y **`notarias`**. Guíate por el código.

## Archivos Clave

| Archivo | Tipo | Propósito |
|---------|------|-----------|
| `src/Module/User/Entity/User.php` | Modificar | `fullName()` |
| `src/Module/User/Repository/UserRepository.php` | Modificar | `findById()` |
| `src/Module/Notary/Repository/NotaryRepository.php` | Modificar | `findById()` |
| `src/Module/Me/Exception/MeError.php` | Crear | Enum con `message()` + `httpStatus()` |
| `src/Module/Me/Exception/MeException.php` | Crear | Lleva el `MeError` |
| `src/Module/Me/DTO/MeProfile.php` | Crear | VO `User` + `Notary` |
| `src/Module/Me/DTO/MeResponse.php` | Crear | Mapper de salida |
| `src/Module/Me/Service/MeService.php` | Crear | `getProfile()` |
| `src/Module/Me/Controller/MeController.php` | Crear | `show()` |
| `src/bootstrap.php` | Modificar | Registrar `MeService` |
| `src/Config/routes.php` | Modificar | Ruta `/api/v3/me` |
| `docs/openapi.yaml` | Modificar | Path + schemas |
| `tests/Module/Me/...`, `tests/Module/User/Entity/UserTest.php` | Crear/Modificar | Unit tests |

## Patrones a Seguir

| Qué | Dónde mirarlo |
|-----|---------------|
| Enum de errores + `errorResponse()` con `match` | `src/Module/User/Exception/UserError.php`, `UserController::errorResponse` |
| Wiring en `ServiceLocator` | `src/bootstrap.php` (bloque User/Notary, líneas ~152-170) |
| Mock de repositorios en tests | `tests/Module/User/Service/UserServiceTest.php` |
| Mapper de salida | `UserResponse`, `NotaryResponse` |

## Código de Referencia

### `User::fullName()`
```php
public function fullName(): string
{
    $parts = array_filter(
        array_map('trim', [$this->firstName, $this->paternalLastName, $this->maternalLastName]),
        static fn (string $part): bool => $part !== '',
    );

    return implode(' ', $parts);
}
```

### Repositories
```php
// UserRepository — NO filtra tenant: el llamador debe comparar notaryId.
public function findById(int $id): ?User
{
    $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM usuarios_notaria WHERE id_usuario = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? User::fromRow($row) : null;
}

// NotaryRepository — sin filtro de estatus (findByIdActive sigue igual)
public function findById(int $id): ?Notary
{
    $stmt = $this->db->prepare('SELECT * FROM notarias WHERE id_notaria = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? Notary::fromRow($row) : null;
}
```

### `MeService`
```php
final class MeService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NotaryRepository $notaries,
    ) {
    }

    /** @throws MeException */
    public function getProfile(int $userId, int $notaryId): MeProfile
    {
        $user = $this->users->findById($userId)
            ?? throw new MeException(MeError::UserNotFound);

        if ($user->notaryId !== $notaryId) {
            throw new MeException(MeError::TenantMismatch);
        }

        if ($user->status() !== User::STATUS_ACTIVE) {
            throw new MeException(MeError::UserInactive);
        }

        $notary = $this->notaries->findById($notaryId);
        if ($notary === null || $notary->status !== 1) {
            throw new MeException(MeError::NotaryInactive);
        }

        return new MeProfile($user, $notary);
    }
}
```

### `MeResponse`
```php
public static function fromProfile(MeProfile $profile): array
{
    $user = UserResponse::fromEntity($profile->user);
    // fullName justo después de maternalLastName
    $pos = array_search('maternalLastName', array_keys($user), true) + 1;
    $user = array_slice($user, 0, $pos, true)
        + ['fullName' => $profile->user->fullName()]
        + array_slice($user, $pos, null, true);

    return [
        'user' => $user,
        'notary' => NotaryResponse::fromEntity($profile->notary),
    ];
}
```

### Controller
```php
public function show(Request $request): Response
{
    try {
        $profile = $this->service->getProfile($request->getUserId(), $request->getNotaryId());
    } catch (MeException $e) {
        return match ($e->error->httpStatus()) {
            404 => Response::notFound($e->error->message()),
            default => Response::forbidden($e->error->message()),
        };
    }

    return Response::json(MeResponse::fromProfile($profile));
}
```

### Ruta
```php
// Me (protected)
$router->get('/api/v3/me', [MeController::class, 'show'])->withMiddleware('auth');
```

## Verificación rápida
```bash
TOKEN=$(curl -s -X POST localhost:8080/api/v3/auth/login -d '{"username":"...","password":"..."}' | jq -r .accessToken)
curl -s localhost:8080/api/v3/me -H "Authorization: Bearer $TOKEN"        # 200
curl -s "localhost:8080/api/v3/me?id=1" -H "Authorization: Bearer $TOKEN" # igual que arriba
curl -si -X POST localhost:8080/api/v3/me -H "Authorization: Bearer $TOKEN" | head -1  # 405
curl -si localhost:8080/api/v3/me | head -1                                # 401
```
Para los `403` desactiva el usuario/notaría en BD con el token ya emitido.

## Fuera de alcance
Cambios en login/JWT, `/users`, `/notary`; edición de perfil; descarga de logo; campo `code` en errores.
