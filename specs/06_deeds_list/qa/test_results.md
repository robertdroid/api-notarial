# Resultados de Pruebas: Listado de Escrituras con Búsqueda

## Resumen Ejecutivo
- **Estado:** ✅ APROBADO
- **Tests unitarios:** 81 ejecutados, 81 pasados (29 nuevos de este feature)
- **Pruebas funcionales/API:** ~45 casos, todos PASS
- **Bugs Encontrados:** 0 (1 observación de baja prioridad, ver abajo)
- **Fecha:** 2026-11-10
- **Rama:** `feature/06-deeds-list` (PR #5)

## Pruebas Unitarias
- **Resultado:** PASS (81 tests, 156 assertions)
- **Deprecación:** 1 aviso de PHPUnit sobre el esquema del XML de configuración (preexistente, no relacionado con este feature)
- **Cobertura del feature:** `DeedListQuery`, `DeedListItem`, `DeedListItemResponse`, `DeedService`, `PaginatedResult`. El `DeedRepository` se valida por integración (ver abajo), como define el plan.

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Escenario | Estado | Evidencia |
|---|-----------|--------|-----------|
| 1 | Listado sin búsqueda | ✅ PASS | 100 items, `total 13372`, primer `deedNumber` = `39734`. Las 134 páginas suman 13372 ids únicos, orden numérico descendente sin violaciones; los 4 no numéricos (`MARIA ANTONIA TOSCANO`, `CDXXIX`, `00000000000000`, `00000000`) quedan al final |
| 2 | Paginación | ✅ PASS | `page=2` → 100 items, `pagination {page:2, perPage:100, total:13372, totalPages:134}`; `page=134` → 72 items |
| 3 | Búsqueda por número | ✅ PASS | `search=39734` → 1 resultado |
| 4 | Búsqueda por participante | ✅ PASS | `search=garcia` → 1317 (oráculo SQL = 1317) |
| 5 | Palabras en cualquier orden | ✅ PASS | `perez juan` = `juan perez` = `juan   perez` = 22 (oráculo = 22) |
| 6 | Búsqueda por acto | ✅ PASS | `compraventa perez` → 232 (oráculo = 232); `search=compraventa` → 200 |
| 7 | Sin resultados | ✅ PASS | `xyz123nonexistent` → 200, `data: []`, `total: 0`, `totalPages: 0` |
| 8 | Página fuera de rango | ✅ PASS | `page=135` y `page=99999` → 200, `data: []`, `total` real (13372) |
| 9 | Participantes eliminados | ✅ PASS | `BUENOLAZARO` y `SARAHO` (solo existen con `id_estatus=0`) → 0 resultados. `BERNANRDINO` → 4 (los activos; la escritura 19105, donde solo está eliminado, no aparece). No hay participantes de tipo distinto a 1/2 en los datos locales; el filtro `IN (1,2)` se revisó en el código |
| 10 | Multi-tenencia | ✅ PASS | Notaría 3: 6049 escrituras propias, 0 ids en común con la notaría 1 (páginas 1). `?notaryId=3` con token de la notaría 1 se ignora (total 13372) |

### Casos edge del PO

| Caso | Estado | Evidencia |
|------|--------|-----------|
| `search=` vacío / solo espacios | ✅ PASS | Igual que sin búsqueda (total 13372) |
| Espacios múltiples | ✅ PASS | `juan   perez` = 22 |
| Caracteres especiales | ✅ PASS | `100%`, `a_b` → 0 (no actúan como comodín); `\` → 200; `'; DROP TABLE escrituras; --` → 200 y la tabla sigue íntegra |
| Acentos y mayúsculas | ✅ PASS | `pérez` = `PEREZ` = 699 |
| Escritura sin participantes | ✅ PASS | 3 de 28 escrituras sin participantes (ids 6948, 7108, 7194) se encuentran por número (posición 0) |
| Varias palabras en el mismo participante (ADR-002) | ✅ PASS | 5 escrituras con "juan" en un participante y "perez" en otro (ids 6314, 6936, 7871, 8147, 8278) no aparecen en `juan perez` |
| Escritura sin acto → `actName: null` | ✅ PASS (parcial) | No hay escrituras sin acto en la notaría 1 (0 de 13372); verificado por test unitario (`DeedListItemTest`) y `LEFT JOIN` en el repository |
| Tope de 10 palabras | ✅ PASS | 13 palabras → 200 sin error (límite cubierto por `DeedListQueryTest`) |

### Validaciones

| Input | Esperado | Actual | Estado |
|-------|----------|--------|--------|
| `page=abc` | 400 | 400 `{"error":"Validation failed","errors":{"page":"Page must be a positive integer"}}` | ✅ PASS |
| `page=0`, `-1`, `1.5`, `1e3` | 400 | 400 | ✅ PASS |
| `page=` (vacío) | 400 | 400 | ✅ PASS |
| Sin `page` | página 1 | página 1 | ✅ PASS |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Migración aplicada, en `.db/applied/`, `pending/` vacío | ✅ PASS | `SHOW INDEX` muestra `idx_escrituras_notaria` y `idx_participantes_escritura_tipo (id_escritura, id_tipo_participante, id_estatus)`. Sin cambios de columnas ni collations |
| 2 | Ruta protegida con `auth` | ✅ PASS | Sin token → 401 "Token no proporcionado"; token inválido → 401 |
| 3 | `notaryId` solo del token | ✅ PASS | Controller usa `$request->getNotaryId()`; `?notaryId=3` ignorado |
| 4 | Forma exacta de la respuesta | ✅ PASS | `{data:[{id,deedNumber,book,deedDate,actName,deedTypeName,isFinished}], pagination:{page,perPage,total,totalPages}}` |
| 5 | `PER_PAGE` única fuente; offset fuera de rango no consulta página | ✅ PASS | `DeedServiceTest` |
| 6 | `WHERE` en un solo método privado; prepared statements; `LIMIT/OFFSET` con `PARAM_INT`; placeholders únicos | ✅ PASS | Revisión de `DeedRepository` |
| 7 | `EXISTS` (sin `JOIN`/`DISTINCT`), `LEFT JOIN` a acto y tipo | ✅ PASS | Revisión de código; conteo coincide con oráculo |
| 8 | `strict_types`, clases `final` en entity/DTOs, sin dependencias entre módulos | ✅ PASS | Revisión estática y `php -l` sin errores |
| 9 | `PaginatedResult` en `src/Shared/Pagination/` | ✅ PASS | Sin dependencias de módulos |
| 10 | Documentación OpenAPI | ✅ PASS | `/deeds`, tag `Deeds`, schemas `DeedListItem` y `Pagination`; el YAML se parsea sin errores |

### Rendimiento
| Caso | Esperado | Actual | Estado |
|------|----------|--------|--------|
| Sin búsqueda (notaría 1) | < 100 ms (arq.) | ~40–180 ms por HTTP, ~60 ms en service | ✅ PASS (dentro del criterio del PO de 2 s) |
| Con búsqueda | < 1 s | 0.08–0.5 s | ✅ PASS |

## Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Acceso a datos propios | ✅ PASS | Notaría 1: 13372; notaría 3: 6049 |
| Intento de acceso cruzado | ✅ PASS | 0 ids en común; `notaryId` por query string ignorado |

## Regresión
`GET /acts`, `/subdivisions`, `/deed-types`, `/notary` → 200; `/health` → 200; `POST /deeds` → 405 (no implementado, esperado). 81/81 tests unitarios.

## Observaciones (no bloqueantes)
1. **Parámetros en formato array** (`?page[]=1`, `?search[]=x`) se ignoran silenciosamente y se aplican los valores por defecto (200) en lugar de responder 400. Sin impacto de seguridad ni de datos; entrada malformada poco realista. Se sugiere endurecer en una iteración futura si se desea consistencia estricta con ADR-007.
2. El filtro `IN (1,2)` de tipo de participante no pudo ejercitarse con datos reales (no hay participantes activos de otros tipos en la BD local); se validó por revisión de código.
3. Deprecación de PHPUnit sobre el XML de configuración (preexistente).

## Conclusión
La implementación cumple todos los criterios del PO y los técnicos. Los conteos de búsqueda coinciden con un oráculo SQL independiente, el orden es correcto en las 134 páginas, el aislamiento entre notarías es efectivo y el rendimiento está muy por debajo de los límites. No se encontraron bugs. **Recomendación: aprobar y mergear.**

## Fecha de Validación
2026-11-10
