# Contexto Técnico

## Entidades Involucradas
- `usuarios` → entidad `User` (módulo User)
- `notaria` → entidad `Notary` (módulo Notary)

## Endpoints Sugeridos
- GET /api/v3/me (middleware `auth`)

## Dependencias
- Módulo Auth: middleware `auth` y `Request::getUserId()` / `getNotaryId()`
- Módulo User: repositorio/servicio para leer el usuario
- Módulo Notary: repositorio/servicio para leer la notaría
- Reutilizar `UserResponse` y `NotaryResponse` donde sea posible

## Impacto en Sistema Existente
- Nueva ruta en `src/Config/routes.php`
- Sin cambios en login, JWT ni endpoints `/users` y `/notary`
- Sin cambios en base de datos

## Consideraciones de Datos
- Sin migraciones
- Filtrar siempre por `notary_id` del token (multi-tenant)
- Estados: 1 = activo, 2 = inactivo; legacy 0/NULL se trata como inactivo
- `lastAccess` en formato `Y-m-d\TH:i:s`, zona `America/Mexico_City`

## Notas para Implementación
- `fullName` = nombre + apellido paterno + apellido materno, sin espacios sobrantes si falta alguno
- `notary.hasLogo` se calcula en backend; no exponer el nombre del archivo
- `user` no incluye `notaryId`; ya viene en `notary.id`
- Decisión de Arquitectura pendiente: módulo propio (`Me`/`Profile`) o dentro de `Auth`; el controlador solo orquesta, la lógica va en un Service
- Documentar el endpoint en OpenAPI
