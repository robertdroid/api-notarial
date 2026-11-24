# Plan de Pruebas: Users CRUD

## Información General
- **Feature:** 12_users_crud
- **Rama/PR:** feature/12-users-crud / PR #11 (base: develop)
- **QA:** Claude (QA)
- **Fecha:** 2026-11-11

## Alcance de Pruebas

### En Scope
- `GET /api/v3/users` (filtro `status`, orden, aislamiento por notaría)
- `GET /api/v3/users/{id}`
- `POST /api/v3/users` (validaciones, bcrypt, unicidad global case-insensitive, defaults)
- `PUT /api/v3/users/{id}` (actualización parcial, auto-desactivación, username legacy, `password` rechazado, `status` crudo `0`)
- Multi-tenancy (notarías 1, 2 y 3), autenticación JWT
- Concurrencia (carrera por username en POST y PUT)
- Ausencia de contraseñas en respuestas, errores y logs
- Migración `add_unique_username_to_usuarios_notaria` (UP / DOWN / índices)
- `docs/openapi.yaml` contra el comportamiento real
- Regresión de los demás módulos (suite completa + smoke de endpoints)

### Fuera de Scope
- Cambio / recuperación de contraseña, roles de administrador, superadmin cross-notaría (excluidos por el PO)
- Revocación inmediata de access tokens de usuarios desactivados (documentado en el plan, riesgo aceptado)
- Interoperabilidad con la app v1 (no disponible en el ambiente local)
- Reporte de cobertura de código (el contenedor no tiene Xdebug ni PCOV)

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API)
- [x] Integración (BD, bcrypt, login con usuario creado, concurrencia, migración)
- [x] Regresión (suite completa + smoke)
- [x] Multi-tenancy
- [x] Seguridad básica (SQLi, fuga de contraseñas, auth)
- [x] Rendimiento (medición local con línea base)

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- MariaDB: 11.4 (contenedor `notarial-db`), `usuarios_notaria` MyISAM / `utf8mb3_bin`
- Docker: corriendo; migración aplicada en local (`uq_usuarios_notaria_usuario`, `idx_usuarios_notaria_notary_status`)
- Datos: 12 usuarios en 4 notarías (usuario 6 `FANNY RECEPCION` con username legacy inválido; usuario 7 con `id_estatus = 0`)
- Tokens JWT firmados con el secreto local: usuario 2 / notaría 1 (actor principal), usuario 4 / notaría 3, usuario 3 / notaría 2
- La tabla `usuarios_notaria` se respaldó antes de las pruebas y se **restauró al final** (verificado con `diff` contra el dump previo)

## Estrategia
1. Revisión de código del módulo contra `technical_acceptance_criteria.md` e ADR-001 a ADR-008.
2. Suite PHPUnit completa.
3. Pruebas de API con `curl` contra Apache/MariaDB reales (scripts y logs en `test_evidence/`).
4. Verificación directa en BD de lo escrito (columnas de contraseña, `id_notaria`, `id_estatus` crudo).
5. Pruebas destructivas controladas (carreras, DOWN/UP de la migración) con respaldo previo.
