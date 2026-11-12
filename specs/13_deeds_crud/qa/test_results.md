# Resultados de Pruebas: CRUD Completo de Escrituras

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests Ejecutados:** 701 unitarios (174 del módulo Deed) + 321 aserciones de API/BD
- **Tests Pasados:** 701 / 701 unitarios · 321 / 321 aserciones de API/BD
- **Tests Fallidos:** 0
- **Bugs Encontrados:** 0 (ver observaciones no bloqueantes al final)

Los fallos intermedios que aparecieron durante la ejecución fueron todos de los scripts de QA o de mis fixtures (acentos corrompidos por curl en Windows, fixtures sin `fecha_modificacion`, columna mal escrita en un SQL de verificación, concatenación de códigos en la prueba de concurrencia). Se corrigieron en los scripts, se limpió la BD y se re-ejecutó todo desde cero; la evidencia adjunta es de la ejecución final.

## Pruebas Unitarias
- **Resultado:** PASS
- **Comando:** `docker exec notarial-api ./vendor/bin/phpunit` → `Tests: 701, Assertions: 1339`; `tests/Module/Deed` → `Tests: 174, Assertions: 392`
- **Cobertura:** no medida (el contenedor no tiene Xdebug ni PCOV). Los 16 archivos de test del módulo cubren Entity, DTO, Exception y Service según la lista de `technical_acceptance_criteria.md`
- **Detalles:** el único aviso de PHPUnit (`XML configuration validates against a deprecated schema`) es de `phpunit.xml.dist`, preexistente y ajeno al feature

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Escenario | Estado | Evidencia |
|---|-----------|--------|-----------|
| 1 | GET existente: detalle con acto, tipo, fraccionamiento, participantes activos, inscripciones, usuario | PASS | `01_get_post.log` GET 19478 |
| 2 | GET inexistente → 404 | PASS | `deed_not_found` |
| 3 | GET de otra notaría → 404 (no revela existencia) | PASS | token n1 sobre 8137 (n2) y token n2 sobre 19478 (n1) → 404 |
| 4 | POST completo → 201 con relaciones | PASS | 2 participantes + 1 inscripción; BD: `terminada=0`, `id_fraccionamiento=-1`, `id_usuario=2`, `id_notaria=1` |
| 5 | POST mínimo → 201 | PASS | `participants: []`, `registrations: []` |
| 6 | Número duplicado → 409 | N/A | **Eliminado por ADR-002**; se verificó lo contrario: POST con número repetido → 201 |
| 7 | Acto inactivo / de otra notaría → 422 `Act not found or inactive` | PASS | acto 169 (inactivo), 58 (n2), 99999 |
| 8 | Tipo inactivo → 422 `Deed type not found or inactive` | PASS | tipo 23 (inactivo), 6 (n2) |
| 9 | Fraccionamiento inactivo → 422 `Subdivision not found or inactive` | PASS | 67 (inactivo), 32 (n2) |
| 10 | Campos obligatorios faltantes → 400 | PASS | `code: validation_failed` + `errors` por campo |
| 11 | PUT modifica y actualiza `id_usuario` / `fecha_modificacion` | PASS | usuario 8 y fecha posterior verificados en BD |
| 12 | PUT no toca participantes ni inscripciones | PASS | siguen 2 y 1 tras PUT con `participants: [...]` y `registrations: []` |
| 13 | PUT con `isFinished: true` no cambia `terminada` | PASS | BD `terminada=0`; también `terminada:1` y `isFinished:false` sobre escritura terminada |
| 14 | PUT en escritura terminada → 200 y sigue terminada | PASS | BD `terminada=1` tras dos PUT |
| 15 | PUT con número existente → 409 | N/A | **Eliminado por ADR-002**; PUT con número de otra escritura → 200 |
| 16 | finish → 200, `terminada=1`, auditoría | PASS | BD `terminada=1, id_usuario=8` |
| 17 | finish de terminada → 409 `Deed is already finished` | PASS | `code: deed_already_finished` |
| 18 | GET participants solo activos | PASS | con filas `id_estatus` 0 y 2 sembradas, no aparecen |
| 19 | POST participant → 201 | PASS | `type: Enajenante`, auditoría de la escritura actualizada |
| 20 | `typeId` inválido → 400 `Invalid participant type` | PASS | `errors.typeId` (formato ADR-005) |
| 21 | PUT participant → 200 | PASS | nombre y tipo cambiados |
| 22 | PUT participant de otra escritura → 404 | PASS | `participant_not_found`; el participante ajeno no se modificó |
| 23 | DELETE participant → 204, `id_estatus=2` | PASS | BD `id_estatus=2` + auditoría |
| 24 | DELETE participant ya inactivo → 409 | PASS | para `id_estatus` 2 y 0 (legacy) |
| 25 | GET registrations | PASS | forma `{id, number, section, volume, date}` |
| 26 | POST registration → 201 | PASS | |
| 27 | PUT registration → 200 | PASS | reemplazo completo (omitidos/null → `null`) |
| 28 | DELETE registration → 204, borrado físico | PASS | `COUNT(*)=0` en BD |
| NF1 | Escrituras atómicas | PASS | rollback forzado: no queda escritura, participantes ni inscripciones |
| NF2 | < 500 ms | PASS | GET detalle p50 0.077 s (máx 0.22 s); PUT p50 0.097 s (máx 0.26 s) |
| NF3 | GET por id sin N+1 | PASS | exactamente 3 SELECT |
| Rechazo | PUT que modifique `terminada`; finish que reabra; operaciones sin auditoría; N+1; no atómicas | PASS | ninguno ocurre |
| Edge | Sin participantes/inscripciones; fecha de inscripción futura (2099); acto de otra notaría en el GET | PASS | escritura 3904 → `act: {id: 1, name: null}` |
| Edge | Nombre de participante > 250 → "truncar" | CAMBIADO | **ADR-007**: 400 (no se trunca). 250 caracteres `ñ` → 201; 251 → 400 |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| DB-1 | 3 tablas en InnoDB | PASS | `information_schema.TABLES` → InnoDB las tres |
| DB-2 | `idx_inscripciones_escritura` creado | PASS | `STATISTICS` = 1 |
| DB-3 | Sin UNIQUE sobre `(id_notaria, num_escritura)` | PASS | 0 índices únicos distintos de PRIMARY en `escrituras` |
| DB-4 | Migraciones con UP/DOWN en `.db/` | PASS | aplicadas en `.db/applied/` |
| DB-5 | `id_estatus = 0` normalizado a 2 | PASS | distribución local: `1` → 59,740, `2` → 610, sin `0` |
| C-1 | 12 rutas con `auth`, sin token → 401 | PASS | 12 rutas sin token + token basura + token `alg: none` → 401 |
| C-2 | Ids no enteros → 400 `invalid_id`; `≤ 0` → 404 del recurso | PASS | `abc`, `1.5`, `zz`, `x1`, `1;DROP`; `0` → `deed_not_found` / `participant_not_found` |
| C-3 | `notaryId`/`userId` solo del token | PASS | body con `notaryId: 3, userId: 99` → BD `id_notaria=1, id_usuario=2` |
| C-4 | Errores `{error, code}` (+`details`) | PASS | `details.maxRegistrations/currentRegistrations`, `details.deedTypeId` |
| C-5 | 404 sin filtrar existencia, nunca 403 | PASS | 12/12 endpoints con token de otra notaría → 404 |
| D-1 | Fechas inválidas (`2024-02-30`, `15/01/2024`, `20240115`, `2024-1-5`, `""`, `null`) → 400 | PASS | |
| D-2 | `"5"` en `actId` → 400; `0`, `1.5` → 400 | PASS | sin cast |
| D-3 | Opcionales `""`/espacios → NULL; `trim` en número y libro | PASS | BD `IS NULL` = 1; `"  QA13-5  "` → `QA13-5` |
| D-4 | Límites 50/51, 100/101, 250/251 con multibyte; listas 50/51 y 20/21 | PASS | `ñ` ×50 → 201, ×51 → 400 |
| D-5 | Errores por item `participants.N.campo` | PASS | `participants.1.typeId` |
| D-6 | Inscripción vacía → 400 | PASS | en POST /deeds la clave es `registrations.0`; en POST /registrations es `errors.registration` |
| D-7 | PUT ignora `participants`, `registrations`, `isFinished`, claves desconocidas | PASS | incluye `id`, `modifiedBy`, `foo` |
| D-8 | `modifiedAt` ISO 8601 con offset `America/Mexico_City` | PASS | `2026-11-12T09:53:13-06:00` |
| D-9 | `0000-00-00` → `null` (incl. listado) | PASS | detalle `deedDate: null`; listado sin `-0001-11-30` |
| S-1 | Validación de referencias antes de abrir la transacción | PASS | rechazo con 422 sin escribir nada (`COUNT=0`) |
| S-2 | PUT: reglas "solo si cambia" | PASS | escritura con acto inactivo se edita sin cambiar acto (200); cambiarlo a inactivo → 422; cambiar tipo con 2 inscripciones a tipo max 1 → 422; cambiar solo fraccionamiento sin soporte → 422 |
| S-3 | `finish` nunca pone `terminada = 0`; `terminada` NULL → finish OK | PASS | |
| S-4 | Subrecursos con lock de la escritura; 404 si es de otra notaría; hijo de otra escritura → 404 | PASS | |
| S-5 | Conteo de inscripciones tras el lock | PASS | 5 rondas × 3 POST simultáneos: siempre 1×201 + 2×422 y 1 fila |
| S-6 | Auditoría en toda operación de escritura, incluidos subrecursos | PASS | POST/PUT/DELETE de participantes e inscripciones cambian `id_usuario` y `fecha_modificacion` |
| R-1 | `UPDATE` sin columna `terminada` | PASS | `grep terminada` en `DeedRepository`: solo SELECT (×3), INSERT y `markFinished` |
| R-2 | Repositorios sin `beginTransaction/commit/rollBack` | PASS | `grep` sin resultados |
| R-3 | `id_fraccionamiento = -1` al guardar `null`; lectura `≤ 0` → `null` | PASS | PUT con `subdivisionId: null` → BD `-1`; escrituras con `-1` y `0` → `subdivision: null` |
| R-4 | Sin `SELECT *`; `strict_types` en todos los archivos | PASS | `grep` limpio |
| R-5 | Repos y `DeedReferenceValidator` no `final` | PASS | |
| P-1 | `GET /deeds/{id}`: 3 queries | PASS | delta de `Com_select` = 3 |
| P-2 | `POST /deeds`: ≤ 3 lecturas de validación + 3 INSERT + 3 relecturas | PASS | 5 SELECT (2 de validación sin fraccionamiento + 3 de relectura) |
| P-3 | Índices usados | PASS | `EXPLAIN`: participantes → `idx_participantes_escritura_tipo`; inscripciones → `idx_inscripciones_escritura`; escrituras → PRIMARY |
| Sec | Prepared statements, sin trazas ni mensajes de PDO | PASS | `' ; DROP TABLE escrituras;--` se guarda como texto; 404 sin detalles internos |
| Doc | OpenAPI con las 12 operaciones y las notas de ADR | PASS | 7 paths bajo `/deeds`; `deedNumber` no único, PUT reemplazo completo, `finish` irreversible |
| Doc | Rutas comentadas de deeds eliminadas | PASS | `routes.php` sin comentarios de deeds |

## Pruebas de API

### Resumen por endpoint (ver logs completos en `test_evidence/`)

| Endpoint | Casos ejecutados | Resultado |
|----------|------------------|-----------|
| `GET /deeds` (regresión) | listado, búsqueda por escritura con fecha cero | PASS |
| `GET /deeds/{id}` | feliz, 404, cross-tenant ×2, ids inválidos, `-1`/`0`/`>0` en fraccionamiento, fecha cero, acto de otra notaría, sin auth | PASS |
| `POST /deeds` | completo, con fraccionamiento, mínimo, número duplicado, inyección de `notaryId`/`userId`/`isFinished`, trim/NULL, 9 casos de referencias, ~35 de validación, sin auth | PASS |
| `PUT /deeds/{id}` | reemplazo, campos ignorados, escritura terminada, `subdivisionId` null, solo-si-cambia (acto/tipo/fraccionamiento), límite de inscripciones, 404, cross-tenant, ids, precedencia 400 antes que 404 | PASS |
| `POST /deeds/{id}/finish` | feliz, repetido (409), terminada NULL, 404, cross-tenant, id inválido, 3 simultáneos ×5 rondas | PASS |
| `participants` (GET/POST/PUT/DELETE) | activos solamente, tipos, longitudes, hijo de otra escritura, inactivo 0 y 2, cross-tenant, auditoría, 6 POST simultáneos | PASS |
| `registrations` (GET/POST/PUT/DELETE) | CRUD, límite por tipo, legacy sobre el límite, borrado físico, hijo de otra escritura, cross-tenant, 3 POST simultáneos ×5 rondas | PASS |
| `DELETE /deeds/{id}` | no expuesto → 405 | PASS |

### Prueba de rollback
`throw` temporal tras el INSERT de inscripciones en `RegistrationRepository::insertMany` → `POST /deeds` con 2 participantes y 1 inscripción → 500 → no existe la escritura, ni los participantes `RB1/RB2`, ni la inscripción. Código restaurado (`git status src/` limpio). Evidencia: `03_rollback.log`.

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Acceso a datos propios (notarías 1 y 3) | PASS | 200 |
| Token n1 sobre escritura de n2, token n2 sobre escritura de n1 | PASS | 404 `deed_not_found` en GET, PUT, finish, y los 8 endpoints de subrecursos; la escritura no se modificó (BD verificada) |
| Participante / inscripción de otra escritura de la misma notaría | PASS | 404 `participant_not_found` / `registration_not_found`; el registro ajeno intacto |
| JOIN a catálogos con otra notaría (acto id 1 en la escritura 3904 de n3) | PASS | `act.name: null`, no se filtra el nombre del catálogo ajeno |
| Catálogos de otra notaría al crear/editar | PASS | 422 `*_unavailable` |

## Observaciones (no bloqueantes, sin bug)

1. **JSON malformado en el body** responde 400 con errores de campos requeridos, no un "invalid JSON" explícito. Es comportamiento de `Core\Http\Request`, común a todos los módulos (mismo origen que el 500 de body escalar del spec 12).
2. **Catálogos con `fecha_modificacion` NULL** harían fallar con 500 las entidades de Act/DeedType/Subdivision (preexistente, fuera del feature). Los datos reales no tienen NULL (0 filas); solo lo disparaban mis fixtures iniciales.
3. **`/deeds/{id}/participants` hace `filesort`** en el plan de ejecución (usa el índice `idx_participantes_escritura_tipo`, ~2 filas por escritura): impacto nulo.
4. **Pendiente previo al deploy (no es del código):** tarea 0.1 del plan (confirmar con la v1 que no usa `LOCK TABLES`/`INSERT DELAYED` sobre estas tablas, y cómo escribe `id_estatus` y `id_fraccionamiento`), respaldo de las 3 tablas y ventana de bajo uso para el `ALTER ... ENGINE=InnoDB`. Debe responderse en el PR según `developer_notes.md`.
5. **Interoperabilidad con la v1** no verificada (no hay v1 en el ambiente local); se simularon sus centinelas (`id_estatus 0`, `id_fraccionamiento -1/0`, `0000-00-00`, `terminada` NULL) con filas sembradas.

## Conclusión
La implementación cumple todos los criterios del PO (con los cambios documentados en los ADR-002, 003, 005, 006 y 007), los criterios técnicos del arquitecto, los de seguridad y los de rendimiento. Se verificó con datos reales en InnoDB que el PUT no toca `terminada` (corrección del bug legacy), que las escrituras son atómicas, que el `FOR UPDATE` serializa el conteo de inscripciones y el doble `finish`, y que no hay acceso cruzado entre notarías. No quedaron bugs en `open/`, `in_progress/` ni `resolved/`. **Recomendación: APROBAR y mergear el PR #12 a develop.**

## Fecha de Validación
2026-11-12
