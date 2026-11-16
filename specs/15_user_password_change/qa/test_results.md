# Resultados de Pruebas: Cambio de Contraseña (spec 15)

## Resumen
- **Estado:** APROBADO
- **Tests Ejecutados:** 733 unitarios (16 del feature) + ~90 casos de API manuales
- **Tests Pasados:** todos
- **Bugs Encontrados:** 0 atribuibles al feature (2 observaciones, ver Conclusión)

## Pruebas Unitarias
- **Resultado:** PASS (`OK, but there were issues!`: 733 tests, 1401 assertions, 1 PHPUnit Deprecation preexistente ajena al feature)
- **Cobertura:** no medida (sin driver de cobertura). El feature cubre `ChangePasswordRequest` (13 tests) y `UserService::changePassword` (3 tests); `create()` sigue en verde tras extraer `hashPassword()`.

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Cambio propio: `204` sin body; `password_hash` bcrypt; `contrasenia` intacta | PASS | `204`, body vacío; MD5 idéntico antes/después |
| 2 | Login con la contraseña nueva | PASS | `200` |
| 3 | Contraseña anterior deja de funcionar en v3 (usuario legacy) | PASS | `401` con la vieja, `200` con la nueva |
| 4 | Reset de otro usuario de la notaría | PASS | `204`; solo entra con la nueva (`200` / vieja `401`); `password_hash` pasó de NULL a `$2y$10$` |
| 5 | Reset propio por `/users/{id}/password` | PASS | `204`, login con la nueva `200` |
| 6 | Usuario de otra notaría | PASS | `404`; fila de la notaría 2 sin cambios en BD |
| 7 | Usuario inexistente | PASS | `404` `User not found` (error `user_not_found`) |
| 8 | Sin autenticación | PASS | `401` sin token y con token inválido, en ambos endpoints |
| 9 | `PUT/PATCH /users/{id}` con `password` | PASS | `PUT` → `400` con "Password cannot be changed through this endpoint"; `PATCH` → `405` (la ruta no existe, comportamiento previo) |
| 10 | JWT anterior sigue vigente | PASS | `GET /me` con el token previo al cambio → `200` |
| NF | bcrypt costo 10; contraseña nunca en respuestas/errores/logs | PASS | Prefijo `$2y$10$`; `grep` en logs de Docker y `/var/log`: 0 coincidencias |
| V1 | `password` obligatorio y string | PASS | ausente, `null`, entero, bool, arreglo → `400` |
| V2 | Mínimo 8 caracteres (`mb_strlen`), máximo 72 bytes (`strlen`) | PASS | 7 → `400`; 8 → `204`; 72 B → `204`; 73 B → `400` |
| V3 | Sin bytes NUL | PASS | `\u0000` al inicio y en medio → `400` |
| V4 | Sin `trim` | PASS | 8 espacios aceptados; `"  abcdef  "` entra solo con los espacios (`"abcdef"` → `401`) |
| V5 | Body vacío / JSON inválido / sin `password` | PASS | `400` `Password is required` |
| V6 | `{id}` no numérico o `< 1` → `404` | PASS | `abc`, `0`, `-1`, `1.5`, `1e2`, `99999999999999999999`, `%20`, `014` → `404` |
| V7 | Campos extra ignorados | PASS | `id`, `userId`, `notaryId` en el body no afectan al destino |
| E1 | 8 caracteres exactos y 72 bytes exactos | PASS | también 18 × 😀 (72 B) y 24 × € (72 B) |
| E2 | 8 caracteres multibyte que superan 72 bytes | PASS | 25 × € (75 B) y 19 × 😀 (76 B) → `400`; 8 × ñ (16 B) → `204` |
| E3 | Usuario inactivo como destino | PASS | `204`; `id_estatus` sigue en 2; puede recibir contraseña |
| E4 | Misma contraseña | PASS | `204` |
| E5 | Dos cambios consecutivos: gana el último | PASS | solo la segunda inicia sesión |
| R | Criterios de rechazo | PASS | Ninguno ocurre (ver Pruebas de BD) |

### Técnicos
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| T1 | Rutas con middleware `auth` → `changePassword` / `changeOwnPassword` | PASS | `routes.php`; `401` sin token |
| T2 | `changeOwnPassword` usa solo `getUserId()` | PASS | `?id=14&userId=14` en query y `id` en body ignorados; cambia solo al usuario del token |
| T3 | `changePassword` sin `parseId()`; id inválido → `404` | PASS | `filter_var(FILTER_VALIDATE_INT)` |
| T4 | `notaryId` siempre del token | PASS | `notaryId: 2` en el body ignorado |
| T5 | Orden: id inválido (404) → body (400) → servicio (404) | PASS | `/users/abc` + `{}` → `404`; `/users/999999` + `{}` → `400` |
| T6 | Errores de validación y `204` sin body | PASS | `Validation failed` + `errors.password`; el mensaje nunca contiene el valor |
| T7 | `UserException` vía `errorResponse()` existente | PASS | Sin ramas nuevas |
| T8 | Service: `getById` antes de escribir; `hashPassword()` compartido con `create()`; sin check de estatus; sin contraseña actual | PASS | Revisado en diff |
| T9 | Repository: SQL exacto con parámetros nombrados, sin `rowCount()`, `COLUMNS` intacto, docblock actualizado | PASS | Revisado en diff |
| T10 | DTO `final`, constructor privado, `readonly`, sin `trim`; `User` y `UpdateUserRequest` sin cambios | PASS | Revisado en diff |
| T11 | Tests del DTO y del servicio según lista del Arch | PASS | 16 tests en verde (ver `--testdox`) |
| T12 | `strict_types`, tipado, sin duplicación (`applyPasswordChange()`) | PASS | |
| T13 | Performance: 2 queries por PK, un solo `password_hash()` | PASS | `getById` + `UPDATE`; un hash por petición |
| T14 | BD tras el cambio: `password_hash` cambió y el resto sin cambios | PASS | Ver Pruebas de BD |
| T15 | OpenAPI y README | PASS | Paths `/users/{id}/password` y `/me/password`, tabla de endpoints y changelog presentes |

## Pruebas de API

### PUT /api/v3/me/password
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Happy path | `NuevaClave123` | 204 | 204 | PASS |
| Login nueva / vieja | — | 200 / 401 | 200 / 401 | PASS |
| Sin token / token inválido | — | 401 | 401 / 401 | PASS |
| Corta (`corta`, 7 chars) | — | 400 | 400 `Password must be at least 8 characters` | PASS |
| Body `{}` / vacío / `{bad` | — | 400 | 400 `Password is required` | PASS |
| Tipos `12345678`, `null`, `["a"]`, `true` | — | 400 | 400 `Password must be a string` | PASS |
| NUL | `\u0000abcdefgh` | 400 | 400 `Password contains invalid characters` | PASS |
| 72 bytes / 73 bytes | — | 204 / 400 | 204 / 400 `must not exceed 72 bytes` | PASS |
| Multibyte (ñ, €, 😀) | ver Criterios E1/E2 | según regla | según regla | PASS |
| Campos extra y query | `id`, `userId`, `notaryId` | ignorados | ignorados | PASS |
| `POST` / `GET` | — | 405 | 405 | PASS |
| Body JSON escalar (`"abc"`, `123`) | — | 400 | **500** | OBS (preexistente, ver Conclusión) |

### PUT /api/v3/users/{id}/password
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Otro usuario de la notaría | id 14 | 204 | 204 | PASS |
| Propio | id 13 | 204 | 204 | PASS |
| Otra notaría | id 16 | 404 | 404 | PASS |
| Inexistente | 999999 | 404 | 404 | PASS |
| Inactivo | id 15 (estatus 2) | 204 | 204 | PASS |
| Id inválido | `abc`, `0`, `-1`, `1.5`, `1e2`, `014`, desbordado, `%20` | 404 | 404 | PASS |
| Sin token / inválido | — | 401 | 401 | PASS |
| `PATCH` | — | 405 | 405 | PASS |

### Regresión
| Caso | Expected | Actual | Estado |
|------|----------|--------|--------|
| `PUT /users/{id}` con `password` | 400 | 400 `Password cannot be changed through this endpoint` | PASS |
| `GET /me` con JWT previo al cambio | 200 | 200 | PASS |

## Pruebas de BD (usuarios legacy: MD5 en `contrasenia`, `password_hash` NULL)
| Usuario | Antes | Después de `204` | Estado |
|---------|-------|------------------|--------|
| `qa_b` (n1, activo) | `password_hash` NULL, MD5 `c5b41e53…` | `$2y$10$…`, MD5 idéntico, `id_estatus`, `user_access`, `ultimo_acceso`, `id_notaria` sin cambios | PASS |
| `qa_inact` (n1, estatus 2) | NULL | `$2y$10$…`, `id_estatus = 2` sin cambios | PASS |
| `qa_other` (n2) tras intento desde n1 | NULL | NULL (sin cambios) | PASS |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Usuario de la notaría 2 desde token de la notaría 1 | PASS | `404`; fila intacta |
| `notaryId` en body o query no cambia el tenant | PASS | Ignorado |
| `/me/password` solo toca al usuario del token | PASS | Con `?userId=14` y `id` en body cambió solo al usuario 13 |
| Existencia no filtrable entre notarías | PASS | Otra notaría e inexistente dan el mismo `404 User not found` |

## Conclusión
**APROBADO.** Todos los escenarios del PO y los criterios técnicos pasan; la suite completa (733 tests) está en verde, no hay bugs abiertos atribuibles al feature y las pruebas se hicieron con usuarios temporales ya eliminados (los usuarios reales no se tocaron).

Observaciones (no bloquean):
1. **Body JSON escalar → 500** (`"abc"`, `123`): defecto preexistente de `Core\Http\Request::fromGlobals` (tipo `array`), ajeno a este feature y ya registrado en la spec 12 (con `APP_DEBUG=true` expone el mensaje del `TypeError`). Está declarado fuera de alcance en `developer_notes.md`. Se recomienda un ticket aparte para que `fromGlobals` acepte solo `array` y responda `400`.
2. **`{id}` con signo `+` (`/users/+14/password`) se acepta** como `14` porque `FILTER_VALIDATE_INT` admite el prefijo `+`; `014` sí da `404`. Es inocuo (apunta al mismo usuario y el filtro por notaría se mantiene), solo se deja constancia.
3. **Riesgos aceptados por el PO** (confirmados, no son defectos): cualquier usuario autenticado puede resetear la contraseña de cualquier usuario de su notaría; los JWT existentes siguen vigentes; sin bitácora ni rate limiting; la v1 sigue aceptando la contraseña anterior mientras `contrasenia` no se limpie.
4. Menor: el controller añade guardas `null` (`getUserId()` / `getNotaryId()` → `401`) no previstas en las notas del Arch; son defensivas y no cambian el comportamiento observable.

## Fecha
2026-11-16
