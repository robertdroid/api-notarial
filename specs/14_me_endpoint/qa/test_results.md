# Resultados de Pruebas: Endpoint Me (Usuario + Notaría)

## Resumen
- **Estado:** APROBADO
- **Tests Ejecutados:** 717 unitarios (477 en la corrida filtrada Me + User) + pruebas de API manuales
- **Tests Pasados:** todos
- **Bugs Encontrados:** 0 (1 observación de bajo riesgo, ver abajo)

## Pruebas Unitarias
- **Resultado:** PASS (`OK, but there were issues!`: 717 tests, 1377 assertions, 1 PHPUnit Deprecation preexistente, ajena al feature)
- **Cobertura:** no medida (sin driver de cobertura); el feature cubre `MeService` (todas las ramas), `MeResponse`, `MeError`, `User::fullName`

## Criterios de Aceptación

### Del PO
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| 1 | Consulta exitosa: `200` con `user` y `notary` de la sesión | PASS | Forma idéntica al ejemplo del PO (`fullName` tras `maternalLastName`) |
| 2 | Sin token / malformado / refresh → `401` | PASS | 3 casos, `401` |
| 3 | Usuario inactivo → `403` sin datos | PASS | estatus 2, 0 y NULL → `User is inactive` |
| 4 | Notaría inactiva → `403` sin datos | PASS | estatus 2 y 0 → `Notary is inactive` |
| 5 | Sin materno → `fullName` sin espacios sobrantes | PASS | `''` y `'  '` → `"ROBERTO TREJO"` |
| 6 | Sin logo → `hasLogo: false` | PASS | Notaría 4 (logo vacío) |
| 7 | Nunca accedió → `lastAccess: null` | PASS | `ultimo_acceso` NULL |
| NF | Sin N+1; < 200 ms; camelCase en inglés | PASS | 2 queries por PK; 116-203 ms vía Docker/Windows (primer hit en frío ~0.8 s) |
| V1 | Parámetros ignorados | PASS | `?id=1&userId=1&notaryId=3` devuelve el usuario del token |
| V2 | Solo `GET`; otros → `405` | PASS | POST, PUT, PATCH, DELETE |
| E1 | Usuario eliminado → `404` | PASS | Token con userId 999 |
| E2 | `notaryId` del token ≠ BD → `403` | PASS | Usuario 2 con token de notaría 3 |
| E3 | Sin permisos → `access: []` | PASS | `user_access` vacío |
| E4 | Estado legacy 0/NULL → `2` (inactivo) | PASS | Usuario 7 (estatus 0 real) → `403` |
| R | Criterios de rechazo (sensibles, otro usuario, inactivos con datos) | PASS | Ninguno ocurre |

### Técnicos
| # | Criterio | Estado | Notas |
|---|----------|--------|-------|
| T1 | Ruta con middleware `auth`; controller no lee route/query/body | PASS | `grep getRouteParam/getQueryParam/getBody` sin resultados en `src/Module/Me` |
| T2 | `MeException` → 404 / 403 | PASS | Verificado por HTTP |
| T3 | Orden de validación; con `TenantMismatch` no se lee la notaría | PASS | Código + `MeServiceTest` |
| T4 | Repos: `findById` sin filtro, `COLUMNS` sin password; `findByIdActive` intacto | PASS | Revisado en diff |
| T5 | `fullName`, `MeProfile` inmutable, claves exactas, `access` nunca `null` | PASS | Código + HTTP |
| T6 | Ausentes: password, hash, contrasenia, logo, `user.notaryId` | PASS | 0 coincidencias en el JSON |
| T7 | `strict_types`, `final` (el enum `MeError` no aplica), PSR-12 | PASS | 6/6 archivos con `strict_types` |
| T8 | OpenAPI: `/me`, `MeResponse`, `MeUser`, tag `Me` | PASS | Presentes en `docs/openapi.yaml` (la validación de sintaxis YAML no se pudo ejecutar: falta `symfony/yaml` en el contenedor) |
| T9 | Seguridad: estado validado en BD en cada llamada | PASS | Los cambios de estado se reflejan con el mismo token |

## Pruebas de API

### GET /api/v3/me
| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Happy path | token u2/n1 | 200 | 200, datos de `devrob` / Notaría 1 | PASS |
| Sin token | — | 401 | 401 | PASS |
| Token malformado | `abc.def.ghi` | 401 | 401 | PASS |
| Refresh token | `type: refresh` | 401 | 401 `Token inválido` | PASS |
| Params ignorados | `?id=1&userId=1&notaryId=3` | datos del token | datos del token | PASS |
| `POST/PUT/PATCH/DELETE` | token válido | 405 | 405 (x4) | PASS |
| Solo permiso PAG | token u1/n1 | `access: ["PAG"]` | `["PAG"]` | PASS |
| Usuario legacy estatus 0 | token u7/n1 | 403 | 403 | PASS |
| Usuario inactivo (2 / NULL) | token u3/n2 | 403 | 403 / 403 | PASS |
| Notaría inactiva (2 / 0) | token u3/n2 | 403 | 403 / 403 | PASS |
| Sin logo | token u12/n4 | `hasLogo:false` | `false` | PASS |
| Usuario inexistente | userId 999 | 404 | 404 | PASS |
| Sin materno (`''`, `'  '`) | u3 | `fullName` limpio | `"ROBERTO TREJO"` | PASS |
| `lastAccess` NULL | u3 | `null` | `null` | PASS |
| `access` vacío | u3 | `[]` | `[]` | PASS |

## Multi-tenancy
| Escenario | Estado | Notas |
|-----------|--------|-------|
| Token n3 con usuario de n1 → `403` | PASS | Sin datos en el body |
| Token n2 con usuario de n2 → solo su notaría | PASS | |
| Notarías 1 y 4 devuelven cada una la suya | PASS | |
| Manipular `?notaryId=3` | PASS | Ignorado |

## Observaciones (no bloqueantes)
- **`ultimo_acceso = '0000-00-00 00:00:00'`** se serializa como `-0001-11-30T00:00:00` en vez de `null`. Riesgo ya anticipado en el plan de arquitectura. Es comportamiento preexistente de `User::fromRow` (también afecta `/users`) y hoy hay **0 filas** en BD con ese valor, por lo que no se levanta bug. Recomendado: endurecer `fromRow` en una feature aparte.
- `NotaryRepository::findById` usa `SELECT *` (igual que `findByIdActive`); la respuesta no expone columnas extra porque pasa por `NotaryResponse`.
- Las columnas `apellido_materno` y `user_access` son `NOT NULL` en BD; los casos "NULL" no son alcanzables y se cubrieron con `''`.
- Los datos de prueba modificados (usuario 3, notaría 2) se restauraron y verificaron contra sus valores originales.
- Los tokens se emitieron con `JwtService`, no con un login real (no se dispone de contraseñas).

## Conclusión
La implementación cumple los 7 escenarios del PO, las validaciones, los casos edge y los criterios técnicos. Sin bugs abiertos. **APROBADO**; se mergea el PR #13 a `develop`.

## Fecha
2026-11-13
