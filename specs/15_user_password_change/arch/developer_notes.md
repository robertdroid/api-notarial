# Notas para el Developer: Cambio de Contraseña

## Contexto Rápido

Dos `PUT` que reciben `{"password": "..."}` y responden `204`:
- `PUT /api/v3/me/password` → usuario del token
- `PUT /api/v3/users/{id}/password` → usuario de la ruta, misma notaría del token

Todo en el módulo `User`. Sin migraciones, sin errores nuevos. Lee `implementation_plan.md`; lo que más fácil se rompe:

1. **No toques `contrasenia`.** La v1 convive unos días y la lee (ADR-002). El `UPDATE` solo escribe `password_hash`.
2. **No uses `parseId()` en `changePassword()`.** Da `400` para ids no numéricos y el PO pide `404` (ADR-003).
3. **No uses `rowCount()` para detectar "no existe".** Verifica con `getById()` antes (ADR-004).
4. **No leas columnas de contraseña** y no agregues `password_hash` a `COLUMNS` (ADR-006 de la spec 12).
5. **`/me/password` toma el id solo del token.** El body y la ruta no influyen.
6. **No hay revisión de estatus del destino**: un usuario inactivo puede recibir contraseña nueva.
7. **El `UPDATE` también filtra por `id_notaria`**, aunque `getById()` ya haya validado.

## Archivos Clave

| Archivo | Tipo | Propósito |
|---------|------|-----------|
| `src/Module/User/DTO/ChangePasswordRequest.php` | Crear | Valida el body |
| `src/Module/User/Repository/UserRepository.php` | Modificar | `updatePassword()` + docblock |
| `src/Module/User/Service/UserService.php` | Modificar | `changePassword()`, `hashPassword()` |
| `src/Module/User/Controller/UserController.php` | Modificar | `changePassword()`, `changeOwnPassword()` |
| `src/Config/routes.php` | Modificar | 2 rutas `PUT` |
| `docs/openapi.yaml`, `README.md` | Modificar | Paths, tabla de endpoints, changelog |
| `tests/Module/User/DTO/ChangePasswordRequestTest.php` | Crear | Tests del DTO |
| `tests/Module/User/Service/UserServiceTest.php` | Modificar | Tests de `changePassword` |

## Patrones a Seguir

| Qué | Dónde mirarlo |
|-----|---------------|
| DTO de request con errores | `src/Module/User/DTO/CreateUserRequest.php` |
| Reglas de contraseña | `UserFieldRules::password()` |
| Verificación de existencia antes de escribir | `UserService::update()` |
| Mock de repositorio en tests | `tests/Module/User/Service/UserServiceTest.php` |
| Wiring | Nada: `UserService` ya está registrado en `src/bootstrap.php` |

## Código de Referencia

### `ChangePasswordRequest`
```php
final class ChangePasswordRequest
{
    /** @var array<string, string> */
    private array $errors = [];

    private function __construct(public readonly string $password)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $password = '';

        if (!array_key_exists('password', $data)) {
            $errors['password'] = 'Password is required';
        } elseif (($error = UserFieldRules::password($data['password'])) !== null) {
            $errors['password'] = $error;
        } else {
            $password = $data['password'];
        }

        $instance = new self($password);
        $instance->errors = $errors;

        return $instance;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

### `UserRepository::updatePassword()`
```php
/**
 * Writes password_hash only. contrasenia (legacy MD5) is left untouched while v1 coexists (spec 15, ADR-002).
 * No rowCount() check: the caller verifies existence first (ADR-004).
 */
public function updatePassword(int $id, int $notaryId, string $passwordHash): void
{
    $sql = 'UPDATE usuarios_notaria SET password_hash = :hash
            WHERE id_usuario = :id AND id_notaria = :notaryId';

    $this->db->prepare($sql)->execute([
        'hash' => $passwordHash,
        'id' => $id,
        'notaryId' => $notaryId,
    ]);
}
```

### `UserService`
```php
/**
 * @throws UserException UserNotFound
 */
public function changePassword(int $id, int $notaryId, string $password): void
{
    $this->getById($id, $notaryId);

    $this->repository->updatePassword($id, $notaryId, $this->hashPassword($password));
}

private function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
}
```
En `create()` reemplaza la línea de `password_hash(...)` por `$hash = $this->hashPassword($dto->password);`.

### `UserController`
```php
public function changePassword(Request $request): Response
{
    $id = filter_var($request->getRouteParam('id'), FILTER_VALIDATE_INT);

    if ($id === false || $id <= 0) {
        return Response::notFound(self::NOT_FOUND);
    }

    return $this->applyPasswordChange($id, $request);
}

public function changeOwnPassword(Request $request): Response
{
    return $this->applyPasswordChange($request->getUserId(), $request);
}

private function applyPasswordChange(int $id, Request $request): Response
{
    $dto = ChangePasswordRequest::fromArray($request->getBody());
    if (!$dto->isValid()) {
        return Response::badRequest('Validation failed', $dto->getErrors());
    }

    try {
        $this->service->changePassword($id, $request->getNotaryId(), $dto->password);
    } catch (UserException $e) {
        return $this->errorResponse($e->error);
    }

    return Response::noContent();
}
```

### Rutas
```php
// Users routes (protected)
$router->put('/api/v3/users/{id}/password', [UserController::class, 'changePassword'])->withMiddleware('auth');

// Me (protected): user + notary of the token
$router->put('/api/v3/me/password', [UserController::class, 'changeOwnPassword'])->withMiddleware('auth');
```

## Verificación rápida
```bash
TOKEN=$(curl -s -X POST localhost:8080/api/v3/auth/login -d '{"username":"...","password":"..."}' | jq -r .accessToken)
H="Authorization: Bearer $TOKEN"

# 204: cambio propio y luego login con la nueva
curl -si -X PUT localhost:8080/api/v3/me/password -H "$H" -d '{"password":"NuevaClave123"}' | head -1
# 204: reset de otro usuario de la notaría
curl -si -X PUT localhost:8080/api/v3/users/2/password -H "$H" -d '{"password":"NuevaClave123"}' | head -1
# 404: usuario de otra notaría, inexistente, no numérico y <= 0
curl -si -X PUT localhost:8080/api/v3/users/<id-otra-notaria>/password -H "$H" -d '{"password":"NuevaClave123"}' | head -1
curl -si -X PUT localhost:8080/api/v3/users/999999/password -H "$H" -d '{"password":"NuevaClave123"}' | head -1
curl -si -X PUT localhost:8080/api/v3/users/abc/password -H "$H" -d '{"password":"NuevaClave123"}' | head -1
curl -si -X PUT localhost:8080/api/v3/users/0/password -H "$H" -d '{"password":"NuevaClave123"}' | head -1
# 400: corta, ausente, tipo incorrecto, body vacío
curl -si -X PUT localhost:8080/api/v3/me/password -H "$H" -d '{"password":"corta"}' | head -1
curl -si -X PUT localhost:8080/api/v3/me/password -H "$H" -d '{}' | head -1
curl -si -X PUT localhost:8080/api/v3/me/password -H "$H" -d '{"password":12345678}' | head -1
# 401: sin token
curl -si -X PUT localhost:8080/api/v3/me/password -d '{"password":"NuevaClave123"}' | head -1
# 400 (sin cambios): PUT /users/{id} con password
curl -si -X PUT localhost:8080/api/v3/users/2 -H "$H" -d '{"password":"NuevaClave123"}' | head -1
```
Después de un `204`, en BD:
```sql
SELECT id_usuario, LEFT(password_hash, 7) AS hash_prefix, contrasenia, ultimo_acceso, id_estatus, user_access
FROM usuarios_notaria WHERE id_usuario = 2;
-- hash_prefix = '$2y$10$', contrasenia igual que antes, demás columnas sin cambios
```
Además: login en la v3 con la contraseña nueva (`200`) y con la anterior (`401`).

## Fuera de alcance
Contraseña actual, roles/permisos nuevos, invalidación de JWT o refresh tokens, bitácora, recuperación por correo, rate limiting, limpiar o sincronizar `contrasenia` (queda pendiente al retirar la v1), y cambios en login, `/users` (excepto las dos rutas nuevas) o `/me`.

Fuera de esta spec, pero a tener presente: un body JSON escalar (`"abc"`) puede provocar `500` por el tipo `array` de `Request::getBody()`. Afecta a todos los endpoints; QA lo reporta aparte.
