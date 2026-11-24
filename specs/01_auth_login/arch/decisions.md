# Decisiones de Implementación - Auth Login

## Fecha: 2026-11-09
## Implementado por: Developer (Claude)

---

## Decisiones Tomadas

### 1. Variables de Entorno JWT
**Decisión:** Usar las variables existentes en `.env.example`
- `JWT_EXPIRATION` (en lugar de `JWT_ACCESS_EXPIRY`)
- `JWT_REFRESH_EXPIRATION` (en lugar de `JWT_REFRESH_EXPIRY`)

**Razón:** El archivo `.env.example` ya existía con estas variables. Mantener consistencia con lo existente.

### 2. Router y Middlewares
**Decisión:** Implementar soporte mínimo pero extensible para middlewares.

**Implementación:**
- Método `addMiddleware(name, callable)` para registrar middlewares globales
- Método `withMiddleware(names)` para aplicar middleware(s) a la ruta anterior
- Ejecución en cadena (pipeline) antes del handler

**Uso:**
```php
$router->addMiddleware('auth', new AuthMiddleware());
$router->get('/api/v3/protected', [Controller::class, 'method'])->withMiddleware('auth');
```

**Razón:** Suficiente para el caso actual. Fácil de extender a groups después si se necesita.

### 3. Tests Unitarios
**Decisión:** Crear tests unitarios para JwtService y AuthService con mocks.

**Cobertura:**
- JwtServiceTest: 6 tests
- AuthServiceTest: 9 tests

**Pendiente para futuro:**
- Tests de integración con BD real
- Tests HTTP (feature tests) para endpoints

**Razón:** Los tests unitarios validan la lógica de negocio sin depender de la BD. Los tests de integración requieren setup adicional (BD de test, fixtures).

---

## Archivos Creados

### Infraestructura
- `src/Core/Config/Config.php` - Acceso tipado a variables de entorno
- `src/Core/Database/Connection.php` - Singleton PDO con conexión lazy
- `src/Core/Container/ServiceLocator.php` - Container simple de servicios
- `src/Core/Exception/AuthenticationException.php` - Excepción 401
- `src/Core/Middleware/AuthMiddleware.php` - Validación de JWT
- `src/bootstrap.php` - Inicialización de la aplicación

### Módulo Auth
- `src/Module/Auth/DTO/AuthUser.php`
- `src/Module/Auth/DTO/TokenResponse.php`
- `src/Module/Auth/DTO/LoginRequest.php`
- `src/Module/Auth/Service/JwtService.php`
- `src/Module/Auth/Service/AuthService.php`
- `src/Module/Auth/Repository/UserAuthRepository.php`
- `src/Module/Auth/Controller/AuthController.php`

### Tests
- `tests/Module/Auth/Service/JwtServiceTest.php`
- `tests/Module/Auth/Service/AuthServiceTest.php`

### Configuración
- `phpunit.xml.dist` - Configuración de PHPUnit

### Migraciones
- `.db/pending/20261109_112050_add_password_hash_to_usuarios.sql`

## Archivos Modificados
- `src/Core/Http/Request.php` - Agregado setAuthUser/getAuthUser/getUserId/getNotaryId/getFeatures
- `src/Core/Http/Router.php` - Agregado withMiddleware() y ejecución de middleware chain
- `src/Config/routes.php` - Rutas de auth y registro de middleware
- `public/index.php` - Inclusión de bootstrap
- `.env.example` - Variables JWT_ISSUER y JWT_AUDIENCE

---

## Próximos Pasos

1. **Crear tablas base** - La BD de Docker necesita el schema inicial
2. **Ejecutar migración** - Aplicar `.db/pending/20261109_112050_add_password_hash_to_usuarios.sql`
3. **Probar con datos reales** - Login con usuario MD5 existente
4. **Tests de integración** - Agregar tests con BD de test
