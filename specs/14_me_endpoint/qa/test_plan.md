# Plan de Pruebas: Endpoint Me (Usuario + Notaría)

## Información General
- **Feature:** 14_me_endpoint
- **Rama/PR:** feature/14-me-endpoint / PR #13 (base: develop)
- **QA:** Claude (QA)
- **Fecha:** 2026-11-13

## Alcance de Pruebas

### En Scope
- `GET /api/v3/me`: forma exacta del response (campos presentes y prohibidos)
- Autenticación: sin token, token malformado, refresh token usado como access token
- Identidad solo desde el token: `?id=`, `?userId=`, `?notaryId=` ignorados
- Métodos no permitidos (`POST/PUT/PATCH/DELETE`) → `405`
- Estados: usuario inactivo (2, 0, NULL), notaría inactiva (2, 0), usuario inexistente
- Multi-tenancy: `notaryId` del token distinto al de la BD → `403`
- Datos: sin apellido materno (vacío y solo espacios), sin logo, `lastAccess` NULL, `access` vacío, permisos distintos (`PAG`, `ESC,PAG`)
- Criterios de código (strict_types, `final`, controller sin lectura de params) y `docs/openapi.yaml`
- Regresión: suite phpunit completa

### Fuera de Scope
- Login real con credenciales (los tokens se emitieron con `JwtService` de la app, mismo secreto y claims que el login)
- Edición de perfil, descarga de logo, cambios en `/users` y `/notary` (excluidos por el PO)

## Tipos de Pruebas
1. **Unitarias:** `./vendor/bin/phpunit` completo dentro del contenedor `notarial-api`
2. **API funcional:** `curl` contra `localhost:8080` con la BD real (notarías 1-4, 12 usuarios)
3. **Estados / datos borde:** modificación temporal de filas de `usuarios_notaria` y `notarias` (usuario 3 / notaría 2), restauradas y verificadas al final
4. **Seguridad / multi-tenancy:** tokens con combinación usuario-notaría inconsistente
5. **Revisión estática:** criterios técnicos del arquitecto

## Entorno
- Docker: `notarial-api`, `notarial-db` (MariaDB 11.4.13)
- Tokens: generados con `JwtService::generateAccessToken` (HS256, `JWT_SECRET` del `.env`)
