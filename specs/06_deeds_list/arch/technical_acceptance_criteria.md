# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
`specs/06_deeds_list/po/acceptance_criteria.md` (ajustado por las ADR-004 y ADR-007 de `implementation_plan.md`)

## Criterios por Capa

### Base de Datos
- [ ] Migración creada con el formato de `.db/README.md` (incluye el bloque DOWN comentado), aplicada en local y movida a `.db/applied/`
- [ ] `SHOW INDEX FROM escrituras` muestra `idx_escrituras_notaria`
- [ ] `SHOW INDEX FROM participantes_escritura` muestra `idx_participantes_escritura_tipo (id_escritura, id_tipo_participante, id_estatus)`
- [ ] No se modifican columnas, tipos ni collations de tablas existentes

### Controller Layer
- [ ] Endpoint `GET /api/v3/deeds` protegido con middleware `auth` (sin token → 401)
- [ ] `notaryId` se toma **solo** de `$request->getNotaryId()`; se ignora cualquier `notaryId` en el query string
- [ ] Sin `page` → página 1
- [ ] `page` no numérico (`abc`), con decimales (`1.5`), `0` o negativo → `400 {"error":"Validation failed","errors":{"page":"..."}}`
- [ ] `search` vacío o solo con espacios → se comporta igual que sin búsqueda
- [ ] Respuesta 200 con la forma exacta:
  ```json
  { "data": [ { "id": 1, "deedNumber": "39734", "book": "750", "deedDate": "2026-10-30",
               "actName": "Compraventa", "deedTypeName": "Actuales", "isFinished": false } ],
    "pagination": { "page": 1, "perPage": 100, "total": 13372, "totalPages": 134 } }
  ```

### Service Layer
- [ ] `DeedService::PER_PAGE = 100` es la única fuente del tamaño de página
- [ ] `offset = (page - 1) * PER_PAGE`
- [ ] Si `offset >= total`, **no** se llama a `findPageByNotary` y se regresa `data: []` con `total` real
- [ ] `totalPages = ceil(total / perPage)`; `0` cuando `total = 0`

### Repository Layer
- [ ] Todas las consultas filtran por `e.id_notaria = :notaryId`
- [ ] El `WHERE` se construye en **un solo** método privado que comparten el conteo y la página
- [ ] Solo prepared statements; los valores del usuario nunca se concatenan al SQL
- [ ] Placeholders únicos por palabra y rama (no se reutiliza ningún nombre)
- [ ] `LIMIT` y `OFFSET` se enlazan con `PDO::PARAM_INT`
- [ ] En cada palabra se escapan `%`, `_` y `\`
- [ ] La comparación usa `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci`
- [ ] Participantes: solo `id_tipo_participante IN (1, 2)` y `id_estatus = 1`
- [ ] Coincidencia por palabra según la ADR-002 (todas las palabras en número + acto, **o** todas en el mismo participante + número + acto)
- [ ] `ORDER BY CAST(e.num_escritura AS UNSIGNED) DESC, e.num_escritura DESC, e.id_escritura DESC`
- [ ] Se usa `EXISTS` (no `JOIN` + `DISTINCT`) para participantes, para no duplicar filas ni inflar el conteo
- [ ] `LEFT JOIN` a `actos_notaria` y `tipo_escritura`: una escritura sin acto o sin tipo aparece con `null`

### Entity/DTO
- [ ] `DeedListItem` readonly y tipado; `fromRow()` mapea `id_escritura`, `num_escritura` (con trim), `libro`, `fecha_escritura`, `nombre_acto`, `nombre_tipo`, `terminada`
- [ ] `isFinished` es `bool` (`terminada = 1` → `true`; `0` o `NULL` → `false`)
- [ ] `deedDate` es `null` si `fecha_escritura` es `NULL`
- [ ] `DeedListQuery` limita a 10 palabras (descarta el resto) y recorta cada palabra a 100 caracteres
- [ ] `PaginatedResult` está en `src/Shared/Pagination/` y no depende de ningún módulo

## Tests Requeridos (PHPUnit, estilo de `tests/Module/Act/`)
- [ ] `DeedListQueryTest`: page default, page inválido (`abc`, `0`, `-1`, `1.5`), split de palabras con espacios múltiples, search vacío → `[]`, tope de 10 palabras
- [ ] `DeedListItemTest`: mapeo de una fila completa, `fecha_escritura` null, `nombre_acto` y `nombre_tipo` null, trim del número, `terminada` 0/1/null
- [ ] `DeedListItemResponseTest`: forma exacta del JSON y formato de fecha `Y-m-d`
- [ ] `PaginatedResultTest`: `totalPages` con total 0, 100, 101 y 13372
- [ ] `DeedServiceTest` (con mock del repository): offset correcto en página 1 y 3; página fuera de rango no llama a `findPageByNotary`; se pasa `notaryId` y `words` al repository
- [ ] Verificación manual del repository contra la BD local (ver checklist en `developer_notes.md`)

## Criterios de Código
- [ ] `declare(strict_types=1)`, PSR-12, typed properties y return types
- [ ] Clases `final` donde aplique (entities y DTOs)
- [ ] Sin dependencias del módulo `Deed` hacia otros módulos (los nombres de acto y tipo vienen por JOIN, no por servicios)
- [ ] Sin warnings ni notices de PHP

## Performance
- [ ] Dos queries por request como máximo (conteo + página); una sola si la página está fuera de rango
- [ ] Sin N+1 (acto y tipo vienen por JOIN)
- [ ] Con la migración aplicada, en la notaría 1 (13k escrituras): sin búsqueda < 100 ms; con búsqueda < 1 s

## Seguridad
- [ ] JWT requerido
- [ ] Aislamiento multi-tenant: con un token de la notaría 3 no aparece ningún `id` de la notaría 1
- [ ] Input `'; DROP TABLE escrituras; --` → 200 con lista vacía y sin errores
- [ ] Mensajes de error en inglés y sin detalles de SQL
