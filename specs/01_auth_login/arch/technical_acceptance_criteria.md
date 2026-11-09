# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
Referencia: `specs/01_auth_login/po/acceptance_criteria.md`

---

## Criterios por Capa

### Infrastructure Layer

#### Database Connection
- [ ] `Connection::getInstance()` retorna singleton PDO
- [ ] Conexión lazy (no conecta hasta primera query)
- [ ] Charset UTF-8 configurado
- [ ] PDO en modo excepciones (`ERRMODE_EXCEPTION`)
- [ ] Prepared statements usados en todas las queries

#### Config
- [ ] Variables cargadas desde `.env` via phpdotenv
- [ ] Método `Config::get(key, default)` tipado
- [ ] Lanza excepción si variable requerida no existe

#### Service Locator
- [ ] Registro de servicios como factories (lazy instantiation)
- [ ] `ServiceLocator::get(ServiceClass::class)` retorna instancia
- [ ] Servicios son singleton por defecto

---

### Controller Layer

#### AuthController
- [ ] Endpoint `POST /api/v3/auth/login` implementado
- [ ] Endpoint `POST /api/v3/auth/refresh` implementado
- [ ] Validación de campos requeridos (usuario, contrasenia)
- [ ] Respuesta 200 con tokens en login exitoso
- [ ] Respuesta 401 con mensaje genérico en cualquier error de auth
- [ ] No exponer detalles del error (usuario no existe vs contraseña incorrecta)

---

### Service Layer

#### AuthService
- [ ] Método `authenticate(string $username, string $password): TokenResponse`
- [ ] Método `refreshTokens(string $refreshToken): TokenResponse`
- [ ] Validación de usuario activo (status = 1)
- [ ] Validación de notaría activa (status = 1)
- [ ] Lógica de contraseña dual (hash primero, luego MD5)
- [ ] Migración automática de MD5 a bcrypt en login exitoso
- [ ] Lanza `AuthenticationException` en cualquier fallo

#### JwtService
- [ ] Método `generateAccessToken(AuthUser $user): string`
- [ ] Método `generateRefreshToken(int $userId, int $notaryId): string`
- [ ] Método `validateToken(string $token): object`
- [ ] Método `validateRefreshToken(string $token): object`
- [ ] AccessToken expira en 2 horas (7200 segundos)
- [ ] RefreshToken expira en 24 horas (86400 segundos)
- [ ] Claims correctos: iss, aud, iat, exp, userId, notaryId, features
- [ ] RefreshToken incluye `type: "refresh"`
- [ ] Algoritmo HS256

---

### Repository Layer

#### UserAuthRepository
- [ ] Método `findByUsername(string $username): ?AuthUser`
- [ ] Método `findActiveWithNotary(int $userId): ?AuthUser`
- [ ] Método `updatePasswordHash(int $userId, string $hash): void`
- [ ] JOIN con tabla `notaria` para validar status
- [ ] Retorna null si usuario no existe o está inactivo
- [ ] Prepared statements (sin SQL injection)

---

### DTO Layer

#### LoginRequest
- [ ] Propiedades: `string $usuario`, `string $contrasenia`
- [ ] Constructor que valida campos no vacíos
- [ ] Factory method `fromArray(array $data): self`

#### TokenResponse
- [ ] Propiedades: `string $accessToken`, `string $refreshToken`, `int $expiresIn`
- [ ] Método `toArray(): array` para serialización

#### AuthUser
- [ ] Propiedades tipadas: id, username, passwordHash, passwordMd5, notaryId, notaryStatus, userStatus, features (array)
- [ ] Método `isActive(): bool`
- [ ] Método `getFeatures(): array` (parsea user_access)

---

### Middleware Layer

#### AuthMiddleware
- [ ] Extrae token de header `Authorization: Bearer <token>`
- [ ] Valida token via JwtService
- [ ] Inyecta datos del usuario en Request (userId, notaryId, features)
- [ ] Retorna 401 si token ausente, inválido o expirado
- [ ] No aplica a rutas de auth (login, refresh)

---

### Exception Layer

#### AuthenticationException
- [ ] Extiende HttpException
- [ ] Status code 401 por defecto
- [ ] Mensaje genérico configurable

---

## Tests Requeridos

### Unit Tests
- [ ] `JwtServiceTest` - Generación y validación de tokens
- [ ] `AuthServiceTest` - Lógica de autenticación (con mocks)
- [ ] `UserAuthRepositoryTest` - Queries (con BD de test)

### Integration Tests
- [ ] Login exitoso con MD5 → migra a hash
- [ ] Login exitoso con hash
- [ ] Login fallido - credenciales incorrectas
- [ ] Login fallido - usuario inactivo
- [ ] Login fallido - notaría inactiva
- [ ] Refresh exitoso
- [ ] Refresh fallido - token expirado
- [ ] Refresh fallido - token inválido

### Feature Tests (HTTP)
- [ ] `POST /api/v3/auth/login` - todos los escenarios
- [ ] `POST /api/v3/auth/refresh` - todos los escenarios
- [ ] Ruta protegida sin token → 401
- [ ] Ruta protegida con token válido → éxito

---

## Criterios de Código

- [ ] PSR-12 coding style
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] Typed properties en todas las clases
- [ ] Return types en todos los métodos
- [ ] Sin dependencias circulares entre módulos
- [ ] Principios SOLID aplicados
- [ ] Namespace correcto: `App\Module\Auth\*`

---

## Performance

- [ ] Login responde en < 500ms
- [ ] Conexión a BD lazy (no conecta si no hay query)
- [ ] Una sola query para obtener usuario + notaría (JOIN)

---

## Seguridad

- [ ] Contraseñas nunca logueadas
- [ ] JWT_SECRET no hardcodeado (viene de .env)
- [ ] Mensajes de error genéricos ("Credenciales inválidas")
- [ ] Prepared statements en todas las queries
- [ ] password_hash con PASSWORD_DEFAULT (bcrypt)
- [ ] Token firmado con HS256 mínimo
- [ ] .env en .gitignore

---

## Checklist de Migración BD

- [ ] Campo `password_hash VARCHAR(255) NULL` agregado
- [ ] Índice en campo `usuario` (si no existe)
- [ ] Migración probada en ambiente de desarrollo
- [ ] Rollback documentado
