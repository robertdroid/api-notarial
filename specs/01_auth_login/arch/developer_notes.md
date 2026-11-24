# Notas para el Developer

## Contexto Rápido

Implementar autenticación JWT con dos endpoints (`login` y `refresh`). Incluye infraestructura base (DB, Config, ServiceLocator) porque el proyecto está en fase inicial. Las contraseñas legacy están en MD5; al hacer login exitoso con MD5, se migra automáticamente a bcrypt.

---

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/bootstrap.php` | Inicializa dotenv y registra servicios |
| `src/Core/Config/Config.php` | Acceso tipado a variables de entorno |
| `src/Core/Database/Connection.php` | Singleton PDO |
| `src/Core/Container/ServiceLocator.php` | Container simple de servicios |
| `src/Module/Auth/Controller/AuthController.php` | Endpoints login/refresh |
| `src/Module/Auth/Service/AuthService.php` | Lógica de autenticación |
| `src/Module/Auth/Service/JwtService.php` | Generación/validación JWT |
| `src/Module/Auth/Repository/UserAuthRepository.php` | Queries de usuario |
| `src/Core/Middleware/AuthMiddleware.php` | Validación de token en rutas protegidas |

---

## Código de Referencia

### .env.example
```env
# Application
APP_DEBUG=true
APP_TIMEZONE=America/Mexico_City

# Database
DB_HOST=db
DB_PORT=3306
DB_DATABASE=notarial_db
DB_USERNAME=notarial_user
DB_PASSWORD=your_password_here

# JWT
JWT_SECRET=your-super-secret-key-min-32-chars-long
JWT_ACCESS_EXPIRY=7200
JWT_REFRESH_EXPIRY=86400
JWT_ISSUER=security@devrob
JWT_AUDIENCE=apps@devrob
```

### Config.php
```php
<?php

declare(strict_types=1);

namespace App\Core\Config;

class Config
{
    private static array $config = [];

    public static function load(): void
    {
        // Cargar desde $_ENV después de que dotenv procese
        self::$config = $_ENV;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required config: {$key}");
        }
        return (string) $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
```

### Connection.php
```php
<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\Config;
use PDO;

class Connection
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = Config::getRequired('DB_HOST');
            $port = Config::get('DB_PORT', '3306');
            $database = Config::getRequired('DB_DATABASE');
            $username = Config::getRequired('DB_USERNAME');
            $password = Config::get('DB_PASSWORD', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

            self::$instance = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
```

### ServiceLocator.php
```php
<?php

declare(strict_types=1);

namespace App\Core\Container;

class ServiceLocator
{
    /** @var array<string, callable> */
    private static array $factories = [];

    /** @var array<string, object> */
    private static array $instances = [];

    public static function register(string $id, callable $factory): void
    {
        self::$factories[$id] = $factory;
    }

    public static function get(string $id): object
    {
        if (!isset(self::$instances[$id])) {
            if (!isset(self::$factories[$id])) {
                throw new \RuntimeException("Service not registered: {$id}");
            }
            self::$instances[$id] = (self::$factories[$id])();
        }

        return self::$instances[$id];
    }

    public static function has(string $id): bool
    {
        return isset(self::$factories[$id]);
    }

    public static function reset(): void
    {
        self::$instances = [];
    }
}
```

### bootstrap.php
```php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Container\ServiceLocator;
use App\Module\Auth\Service\JwtService;
use App\Module\Auth\Service\AuthService;
use App\Module\Auth\Repository\UserAuthRepository;

// Load environment variables
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

// Initialize config
Config::load();

// Register services
ServiceLocator::register(JwtService::class, function () {
    return new JwtService();
});

ServiceLocator::register(UserAuthRepository::class, function () {
    return new UserAuthRepository(Connection::getInstance());
});

ServiceLocator::register(AuthService::class, function () {
    return new AuthService(
        ServiceLocator::get(UserAuthRepository::class),
        ServiceLocator::get(JwtService::class)
    );
});
```

### JwtService.php
```php
<?php

declare(strict_types=1);

namespace App\Module\Auth\Service;

use App\Core\Config\Config;
use App\Module\Auth\DTO\AuthUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JwtService
{
    private string $secret;
    private string $issuer;
    private string $audience;
    private int $accessExpiry;
    private int $refreshExpiry;

    public function __construct()
    {
        $this->secret = Config::getRequired('JWT_SECRET');
        $this->issuer = Config::get('JWT_ISSUER', 'security@devrob');
        $this->audience = Config::get('JWT_AUDIENCE', 'apps@devrob');
        $this->accessExpiry = Config::getInt('JWT_ACCESS_EXPIRY', 7200);
        $this->refreshExpiry = Config::getInt('JWT_REFRESH_EXPIRY', 86400);
    }

    public function generateAccessToken(AuthUser $user): string
    {
        $now = time();

        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->accessExpiry,
            'userId' => $user->id,
            'notaryId' => $user->notaryId,
            'features' => $user->getFeatures(),
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function generateRefreshToken(int $userId, int $notaryId): string
    {
        $now = time();

        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->refreshExpiry,
            'userId' => $userId,
            'notaryId' => $notaryId,
            'type' => 'refresh',
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function validateToken(string $token): object
    {
        return JWT::decode($token, new Key($this->secret, 'HS256'));
    }

    public function validateRefreshToken(string $token): object
    {
        $payload = $this->validateToken($token);

        if (!isset($payload->type) || $payload->type !== 'refresh') {
            throw new \InvalidArgumentException('Invalid token type');
        }

        return $payload;
    }

    public function getAccessExpiry(): int
    {
        return $this->accessExpiry;
    }
}
```

### AuthUser DTO
```php
<?php

declare(strict_types=1);

namespace App\Module\Auth\DTO;

class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly ?string $passwordHash,
        public readonly ?string $passwordMd5,
        public readonly int $notaryId,
        public readonly int $userStatus,
        public readonly int $notaryStatus,
        public readonly ?string $userAccess,
    ) {}

    public function isActive(): bool
    {
        return $this->userStatus === 1 && $this->notaryStatus === 1;
    }

    public function getFeatures(): array
    {
        if (empty($this->userAccess)) {
            return [];
        }

        return array_map(
            'trim',
            explode(',', $this->userAccess)
        );
    }

    public function hasPasswordHash(): bool
    {
        return !empty($this->passwordHash);
    }
}
```

### UserAuthRepository.php
```php
<?php

declare(strict_types=1);

namespace App\Module\Auth\Repository;

use App\Module\Auth\DTO\AuthUser;
use PDO;

class UserAuthRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findByUsername(string $username): ?AuthUser
    {
        $sql = "
            SELECT 
                u.id,
                u.usuario as username,
                u.password_hash,
                u.contrasenia as password_md5,
                u.notaria_id as notary_id,
                u.status as user_status,
                u.user_access,
                n.status as notary_status
            FROM usuarios u
            INNER JOIN notaria n ON n.id = u.notaria_id
            WHERE u.usuario = :username
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['username' => $username]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return new AuthUser(
            id: (int) $row['id'],
            username: $row['username'],
            passwordHash: $row['password_hash'],
            passwordMd5: $row['password_md5'],
            notaryId: (int) $row['notary_id'],
            userStatus: (int) $row['user_status'],
            notaryStatus: (int) $row['notary_status'],
            userAccess: $row['user_access'],
        );
    }

    public function findActiveById(int $userId): ?AuthUser
    {
        $sql = "
            SELECT 
                u.id,
                u.usuario as username,
                u.password_hash,
                u.contrasenia as password_md5,
                u.notaria_id as notary_id,
                u.status as user_status,
                u.user_access,
                n.status as notary_status
            FROM usuarios u
            INNER JOIN notaria n ON n.id = u.notaria_id
            WHERE u.id = :userId
              AND u.status = 1
              AND n.status = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['userId' => $userId]);

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return new AuthUser(
            id: (int) $row['id'],
            username: $row['username'],
            passwordHash: $row['password_hash'],
            passwordMd5: $row['password_md5'],
            notaryId: (int) $row['notary_id'],
            userStatus: (int) $row['user_status'],
            notaryStatus: (int) $row['notary_status'],
            userAccess: $row['user_access'],
        );
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $sql = "UPDATE usuarios SET password_hash = :hash WHERE id = :userId";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'hash' => $hash,
            'userId' => $userId,
        ]);
    }
}
```

### AuthService.php
```php
<?php

declare(strict_types=1);

namespace App\Module\Auth\Service;

use App\Core\Exception\AuthenticationException;
use App\Module\Auth\DTO\AuthUser;
use App\Module\Auth\DTO\TokenResponse;
use App\Module\Auth\Repository\UserAuthRepository;

class AuthService
{
    public function __construct(
        private readonly UserAuthRepository $userRepository,
        private readonly JwtService $jwtService,
    ) {}

    public function authenticate(string $username, string $password): TokenResponse
    {
        $user = $this->userRepository->findByUsername($username);

        if ($user === null) {
            throw new AuthenticationException();
        }

        if (!$user->isActive()) {
            throw new AuthenticationException();
        }

        if (!$this->verifyPassword($user, $password)) {
            throw new AuthenticationException();
        }

        return $this->generateTokens($user);
    }

    public function refreshTokens(string $refreshToken): TokenResponse
    {
        try {
            $payload = $this->jwtService->validateRefreshToken($refreshToken);
        } catch (\Exception $e) {
            throw new AuthenticationException('Token inválido o expirado');
        }

        $user = $this->userRepository->findActiveById((int) $payload->userId);

        if ($user === null) {
            throw new AuthenticationException();
        }

        return $this->generateTokens($user);
    }

    private function verifyPassword(AuthUser $user, string $password): bool
    {
        // Primero intentar con el nuevo hash (bcrypt)
        if ($user->hasPasswordHash()) {
            return password_verify($password, $user->passwordHash);
        }

        // Fallback a MD5 (legacy)
        if ($user->passwordMd5 !== null && md5($password) === $user->passwordMd5) {
            // Migrar a bcrypt
            $this->migratePassword($user->id, $password);
            return true;
        }

        return false;
    }

    private function migratePassword(int $userId, string $plainPassword): void
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $this->userRepository->updatePasswordHash($userId, $hash);
    }

    private function generateTokens(AuthUser $user): TokenResponse
    {
        return new TokenResponse(
            accessToken: $this->jwtService->generateAccessToken($user),
            refreshToken: $this->jwtService->generateRefreshToken($user->id, $user->notaryId),
            expiresIn: $this->jwtService->getAccessExpiry(),
        );
    }
}
```

### AuthController.php
```php
<?php

declare(strict_types=1);

namespace App\Module\Auth\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Exception\AuthenticationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Auth\Service\AuthService;

class AuthController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = ServiceLocator::get(AuthService::class);
    }

    public function login(Request $request): Response
    {
        $username = $request->getBodyParam('usuario');
        $password = $request->getBodyParam('contrasenia');

        if (empty($username) || empty($password)) {
            return Response::badRequest('Usuario y contraseña son requeridos');
        }

        try {
            $tokens = $this->authService->authenticate($username, $password);
            return Response::json($tokens->toArray());
        } catch (AuthenticationException $e) {
            return Response::unauthorized('Credenciales inválidas');
        }
    }

    public function refresh(Request $request): Response
    {
        $refreshToken = $request->getBodyParam('refreshToken');

        if (empty($refreshToken)) {
            return Response::badRequest('Refresh token es requerido');
        }

        try {
            $tokens = $this->authService->refreshTokens($refreshToken);
            return Response::json($tokens->toArray());
        } catch (AuthenticationException $e) {
            return Response::unauthorized($e->getMessage() ?: 'Token inválido o expirado');
        }
    }
}
```

### AuthMiddleware.php
```php
<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Auth\Service\JwtService;

class AuthMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->getBearerToken();

        if ($token === null) {
            return Response::unauthorized('Token no proporcionado');
        }

        try {
            $jwtService = ServiceLocator::get(JwtService::class);
            $payload = $jwtService->validateToken($token);

            // Verificar que no sea un refresh token
            if (isset($payload->type) && $payload->type === 'refresh') {
                return Response::unauthorized('Token inválido');
            }

            // Inyectar datos del usuario en el request
            $request->setAuthUser([
                'userId' => $payload->userId,
                'notaryId' => $payload->notaryId,
                'features' => $payload->features ?? [],
            ]);

        } catch (\Exception $e) {
            return Response::unauthorized('Token inválido o expirado');
        }

        return $next($request);
    }
}
```

---

## Base de Datos

### Tablas Involucradas

#### usuarios
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int | PK |
| usuario | varchar | Username para login |
| contrasenia | varchar | Contraseña MD5 (legacy) |
| password_hash | varchar(255) | **NUEVO** - Contraseña bcrypt |
| notaria_id | int | FK a notaria |
| status | int | 1 = activo |
| user_access | text | Features separados por coma |

#### notaria
| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int | PK |
| status | int | 1 = activo |

### Migración SQL

```sql
-- 001_add_password_hash.sql
-- Agregar campo para contraseña hasheada (bcrypt)

ALTER TABLE usuarios 
ADD COLUMN password_hash VARCHAR(255) NULL 
AFTER contrasenia;

-- Índice en usuario para búsqueda rápida (si no existe)
-- CREATE INDEX idx_usuarios_usuario ON usuarios(usuario);
```

### Rollback

```sql
-- Rollback 001_add_password_hash.sql
ALTER TABLE usuarios DROP COLUMN password_hash;
```

---

## Rutas a Agregar

```php
// src/Config/routes.php

use App\Module\Auth\Controller\AuthController;

// Auth routes (públicas)
$router->post('/api/v3/auth/login', [AuthController::class, 'login']);
$router->post('/api/v3/auth/refresh', [AuthController::class, 'refresh']);
```

---

## Modificaciones al Request

Agregar método para almacenar datos del usuario autenticado:

```php
// En Request.php, agregar:

private ?array $authUser = null;

public function setAuthUser(array $user): void
{
    $this->authUser = $user;
}

public function getAuthUser(): ?array
{
    return $this->authUser;
}

public function getUserId(): ?int
{
    return $this->authUser['userId'] ?? null;
}

public function getNotaryId(): ?int
{
    return $this->authUser['notaryId'] ?? null;
}

public function getFeatures(): array
{
    return $this->authUser['features'] ?? [];
}
```

---

## Checklist Pre-PR

- [ ] `composer require vlucas/phpdotenv` ejecutado
- [ ] `.env.example` creado con todas las variables
- [ ] `.env` agregado a `.gitignore`
- [ ] Migración SQL ejecutada en BD de desarrollo
- [ ] Todos los archivos creados según el plan
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] Tipos correctos en propiedades y retornos
- [ ] Tests manuales ejecutados:
  - [ ] Login con usuario MD5 → tokens + migración
  - [ ] Login con usuario hasheado → tokens
  - [ ] Login fallido → 401 genérico
  - [ ] Refresh exitoso → nuevos tokens
  - [ ] Refresh fallido → 401
- [ ] Sin warnings de PHP
- [ ] Sin credenciales en el código

---

## Preguntas Frecuentes

**P: ¿Qué pasa si el usuario tiene tanto MD5 como hash?**
R: Se usa el hash (bcrypt). El MD5 se ignora.

**P: ¿Cómo pruebo la migración de contraseña?**
R: Crea un usuario solo con MD5, haz login exitoso, verifica que `password_hash` se llenó.

**P: ¿El refresh token renueva el access token con datos actualizados?**
R: Sí, se vuelve a consultar el usuario para obtener features actuales.

**P: ¿Puedo usar el refresh token como access token?**
R: No, el middleware rechaza tokens con `type: "refresh"`.

**P: ¿Cómo genero un JWT_SECRET seguro?**
R: `openssl rand -base64 32` en terminal, o cualquier string aleatorio de 32+ caracteres.

---

## Contacto

- **Dudas de negocio:** Revisar `specs/01_auth_login/po/`
- **Dudas de arquitectura:** Revisar `specs/01_auth_login/arch/implementation_plan.md`
