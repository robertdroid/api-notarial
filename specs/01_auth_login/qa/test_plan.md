# Plan de Pruebas: API de Autenticación (Login)

## Información General
- **Feature:** 01_auth_login
- **Rama/PR:** feature/01-auth-login / PR #1
- **QA:** Claude (Automated)
- **Fecha:** 2026-11-09

## Alcance de Pruebas

### En Scope
- Endpoint POST /api/v3/auth/login
- Endpoint POST /api/v3/auth/refresh
- Validación de credenciales
- Generación de JWT tokens
- Migración de contraseñas MD5 a bcrypt
- Validación de usuario/notaría activos
- Claims del token JWT

### Fuera de Scope
- Logout (no implementado)
- Rate limiting (futuro feature)
- Recuperación de contraseña (futuro feature)
- Rutas protegidas con middleware (se probará estructura, no flujo completo)

## Tipos de Pruebas
- [x] Unitarias (PHPUnit)
- [x] Funcionales (API)
- [x] Integración (BD real)
- [ ] Regresión (N/A - feature nuevo)
- [ ] Multi-tenancy (parcial - estructura validada)

## Ambiente de Pruebas
- PHP: 8.3.36
- MariaDB: 11.4
- Docker: Running
- OS: Windows 11

## Casos de Prueba

### Login
| ID | Caso | Prioridad |
|----|------|-----------|
| L01 | Login exitoso con contraseña MD5 | Alta |
| L02 | Login exitoso con contraseña bcrypt | Alta |
| L03 | Login fallido - password incorrecto | Alta |
| L04 | Login fallido - usuario inexistente | Alta |
| L05 | Login fallido - campos vacíos | Media |
| L06 | Login fallido - usuario inactivo | Alta |
| L07 | Login fallido - notaría inactiva | Alta |

### Refresh Token
| ID | Caso | Prioridad |
|----|------|-----------|
| R01 | Refresh exitoso | Alta |
| R02 | Refresh fallido - token inválido | Alta |
| R03 | Refresh fallido - usar accessToken | Alta |
| R04 | Refresh fallido - sin token | Media |

### JWT Claims
| ID | Caso | Prioridad |
|----|------|-----------|
| J01 | AccessToken tiene claims correctos | Alta |
| J02 | RefreshToken tiene type=refresh | Alta |
| J03 | Tiempos de expiración correctos | Media |

### Performance
| ID | Caso | Prioridad |
|----|------|-----------|
| P01 | Login responde < 500ms | Media |
