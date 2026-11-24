# Plan de Implementación: API de Autenticación

## Resumen

Implementar el sistema de autenticación JWT con login, refresh token, y migración transparente de contraseñas MD5 a bcrypt. Incluye la infraestructura base necesaria: conexión a BD, configuración de entorno, y Service Locator.

## Arquitectura Propuesta

### Diagrama de Flujo - Login

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              POST /api/v3/auth/login                         │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  AuthController::login()                                                     │
│  - Extrae usuario/contraseña del body                                        │
│  - Valida campos requeridos                                                  │
│  - Delega a AuthService                                                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  AuthService::authenticate()                                                 │
│  1. Buscar usuario por username                                              │
│  2. Validar status usuario y notaría                                         │
│  3. Validar contraseña (hash o MD5)                                          │
│  4. Si MD5 exitoso → migrar a bcrypt                                         │
│  5. Generar tokens via JwtService                                            │
└─────────────────────────────────────────────────────────────────────────────┘
                    │                                   │
                    ▼                                   ▼
┌───────────────────────────────┐     ┌───────────────────────────────────────┐
│  UserAuthRepository           │     │  JwtService                            │
│  - findByUsername()           │     │  - generateAccessToken()               │
│  - updatePasswordHash()       │     │  - generateRefreshToken()              │
│  - findWithNotary()           │     │  - validateToken()                     │
└───────────────────────────────┘     └───────────────────────────────────────┘
                    │
                    ▼
┌───────────────────────────────┐
│  Database (PDO)               │
│  - usuarios                   │
│  - notaria                    │
└───────────────────────────────┘
```

### Diagrama de Flujo - Refresh Token

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            POST /api/v3/auth/refresh                         │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  AuthController::refresh()                                                   │
│  - Extrae refreshToken del body                                              │
│  - Delega a AuthService                                                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  AuthService::refreshTokens()                                                │
│  1. Validar y decodificar refresh token                                      │
│  2. Verificar type === 'refresh'                                             │
│  3. Obtener usuario actualizado (status, features)                           │
│  4. Generar nuevos tokens                                                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Diagrama de Componentes

```
src/
├── Core/
│   ├── Database/
│   │   └── Connection.php              ← NUEVO
│   ├── Config/
│   │   └── Config.php                  ← NUEVO
│   ├── Container/
│   │   └── ServiceLocator.php          ← NUEVO
│   ├── Http/
│   │   ├── Request.php                 (existente)
│   │   ├── Response.php                (existente)
│   │   └── Router.php                  ← MODIFICAR (middleware support)
│   ├── Middleware/
│   │   └── AuthMiddleware.php          ← NUEVO
│   └── Exception/
│       ├── HttpException.php           (existente)
│       └── AuthenticationException.php ← NUEVO
│
├── Module/
│   └── Auth/
│       ├── Controller/
│       │   └── AuthController.php      ← NUEVO
│       ├── Service/
│       │   ├── AuthService.php         ← NUEVO
│       │   └── JwtService.php          ← NUEVO
│       ├── Repository/
│       │   └── UserAuthRepository.php  ← NUEVO
│       └── DTO/
│           ├── LoginRequest.php        ← NUEVO
│           ├── TokenResponse.php       ← NUEVO
│           └── AuthUser.php            ← NUEVO
│
├── Config/
│   └── routes.php                      ← MODIFICAR
│
└── bootstrap.php                       ← NUEVO
```

## Tareas de Implementación

### Fase 1: Infraestructura Base
**Estimación: 2-3 horas**

#### 1.1 Instalar phpdotenv
- **Comando:** `composer require vlucas/phpdotenv`
- **Tipo:** Dependencia
- **Dependencias:** Ninguna

#### 1.2 Crear archivo .env.example
- **Archivo:** `.env.example`
- **Tipo:** Crear
- **Descripción:** Template de variables de entorno

#### 1.3 Crear clase Config
- **Archivo:** `src/Core/Config/Config.php`
- **Tipo:** Crear
- **Descripción:** Clase para acceder a configuración tipada
- **Dependencias:** phpdotenv instalado

#### 1.4 Crear clase Connection (Database)
- **Archivo:** `src/Core/Database/Connection.php`
- **Tipo:** Crear
- **Descripción:** Singleton PDO con conexión lazy
- **Dependencias:** Config

#### 1.5 Crear ServiceLocator
- **Archivo:** `src/Core/Container/ServiceLocator.php`
- **Tipo:** Crear
- **Descripción:** Container simple para servicios
- **Dependencias:** Ninguna

#### 1.6 Crear bootstrap.php
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Crear
- **Descripción:** Inicialización de la aplicación (dotenv, servicios)
- **Dependencias:** Todas las anteriores

#### 1.7 Modificar public/index.php
- **Archivo:** `public/index.php`
- **Tipo:** Modificar
- **Descripción:** Incluir bootstrap
- **Dependencias:** bootstrap.php

---

### Fase 2: Módulo Auth - Core
**Estimación: 3-4 horas**

#### 2.1 Crear AuthenticationException
- **Archivo:** `src/Core/Exception/AuthenticationException.php`
- **Tipo:** Crear
- **Descripción:** Excepción específica para errores de auth (401)
- **Dependencias:** HttpException

#### 2.2 Crear DTOs
- **Archivos:**
  - `src/Module/Auth/DTO/LoginRequest.php`
  - `src/Module/Auth/DTO/TokenResponse.php`
  - `src/Module/Auth/DTO/AuthUser.php`
- **Tipo:** Crear
- **Descripción:** Objetos de transferencia de datos tipados
- **Dependencias:** Ninguna

#### 2.3 Crear JwtService
- **Archivo:** `src/Module/Auth/Service/JwtService.php`
- **Tipo:** Crear
- **Descripción:** Generación y validación de tokens JWT
- **Dependencias:** firebase/php-jwt, Config

#### 2.4 Crear UserAuthRepository
- **Archivo:** `src/Module/Auth/Repository/UserAuthRepository.php`
- **Tipo:** Crear
- **Descripción:** Queries para autenticación de usuarios
- **Dependencias:** Connection, AuthUser DTO

#### 2.5 Crear AuthService
- **Archivo:** `src/Module/Auth/Service/AuthService.php`
- **Tipo:** Crear
- **Descripción:** Lógica de negocio de autenticación
- **Dependencias:** UserAuthRepository, JwtService

#### 2.6 Crear AuthController
- **Archivo:** `src/Module/Auth/Controller/AuthController.php`
- **Tipo:** Crear
- **Descripción:** Endpoints login y refresh
- **Dependencias:** AuthService

---

### Fase 3: Middleware y Rutas
**Estimación: 1-2 horas**

#### 3.1 Modificar Router para middleware
- **Archivo:** `src/Core/Http/Router.php`
- **Tipo:** Modificar
- **Descripción:** Implementar ejecución de middlewares en cadena
- **Dependencias:** Ninguna

#### 3.2 Crear AuthMiddleware
- **Archivo:** `src/Core/Middleware/AuthMiddleware.php`
- **Tipo:** Crear
- **Descripción:** Validación de JWT en rutas protegidas
- **Dependencias:** JwtService

#### 3.3 Actualizar routes.php
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Agregar rutas de auth
- **Dependencias:** AuthController, ServiceLocator

---

### Fase 4: Base de Datos
**Estimación: 30 min**

#### 4.1 Crear migración
- **Archivo:** `database/migrations/001_add_password_hash.sql`
- **Tipo:** Crear
- **Descripción:** ALTER TABLE para agregar password_hash
- **Dependencias:** Ninguna

---

## Orden de Implementación

```
1. composer require vlucas/phpdotenv     ─┐
2. .env.example                          ─┤
3. Config.php                            ─┼── Fase 1: Infraestructura
4. Connection.php                        ─┤
5. ServiceLocator.php                    ─┤
6. bootstrap.php                         ─┤
7. index.php (modificar)                 ─┘

8. AuthenticationException.php           ─┐
9. DTOs (LoginRequest, TokenResponse,    ─┤
        AuthUser)                        ─┤
10. JwtService.php                       ─┼── Fase 2: Auth Core
11. UserAuthRepository.php               ─┤
12. AuthService.php                      ─┤
13. AuthController.php                   ─┘

14. Router.php (modificar)               ─┐
15. AuthMiddleware.php                   ─┼── Fase 3: Middleware
16. routes.php (modificar)               ─┘

17. 001_add_password_hash.sql            ─── Fase 4: Migración
```

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Contraseñas MD5 no coinciden | Baja | Alto | Probar con datos reales antes de deploy |
| JWT Secret expuesto | Baja | Crítico | Nunca commitear .env, usar .env.example |
| Migración rompe login legacy | Media | Alto | Campo password_hash es nullable, MD5 sigue funcionando |
| Performance en validación hash | Baja | Bajo | bcrypt es lento por diseño (seguridad) |

## Decisiones de Arquitectura

### ADR-001: Service Locator vs Dependency Injection Container
- **Contexto:** Necesitamos inyectar dependencias en controllers sin un framework
- **Decisión:** Usar Service Locator simple (array de factories)
- **Consecuencias:** 
  - Más simple que un DI container completo
  - Controllers obtienen servicios via `ServiceLocator::get()`
  - Fácil de migrar a un container real si crece el proyecto

### ADR-002: Migración de Contraseñas Transparente
- **Contexto:** Usuarios existentes tienen contraseñas en MD5
- **Decisión:** Nuevo campo `password_hash`, migración en login exitoso
- **Consecuencias:**
  - Cero fricción para usuarios
  - Gradual: usuarios que no hacen login siguen en MD5
  - Ambos métodos coexisten hasta que todos migren

### ADR-003: Refresh Token Stateless
- **Contexto:** ¿Guardar refresh tokens en BD o solo validar firma?
- **Decisión:** Stateless (solo firma JWT)
- **Consecuencias:**
  - Más simple, sin tabla adicional
  - No se puede invalidar un token específico (solo cambiando el secret)
  - Aceptable para MVP, puede agregarse blacklist después

### ADR-004: Un JWT Secret para ambos tokens
- **Contexto:** ¿Usar secrets diferentes para access y refresh?
- **Decisión:** Mismo secret, diferenciados por claim `type`
- **Consecuencias:**
  - Configuración más simple
  - El claim `type: "refresh"` previene usar refresh como access
  - Rotar secret invalida ambos tipos (comportamiento deseado)
