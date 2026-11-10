# Plan de Implementación: Listado de Escrituras con Búsqueda

## Resumen

Nuevo módulo `Deed` con un único endpoint `GET /api/v3/deeds` (listado paginado + búsqueda). Se sigue el patrón de los módulos existentes (`DeedType`, `Act`): Controller → Service → Repository con PDO, DTOs de entrada/salida y registro en `ServiceLocator` vía `bootstrap.php`.

La búsqueda se hace en SQL (una consulta de conteo + una de página) con `LIKE` por palabra, comparando sin distinguir mayúsculas ni acentos. Requiere una **migración de índices** (sin ella la búsqueda tarda más de 2 minutos).

> Esta spec **reemplaza** los nombres de tablas y columnas de `po/technical_context.md`, que eran supuestos. Los nombres reales se validaron contra la BD local (ver `developer_notes.md`).

## Arquitectura Propuesta

### Diagrama de Componentes

```
GET /api/v3/deeds?page=&search=
        │
Router → AuthMiddleware (JWT → notaryId)
        │
        ▼
DeedController::index ──► DeedListQuery::fromQueryParams()   (valida page, normaliza search → words[])
        │
        ▼
DeedService::list(notaryId, DeedListQuery) ──► PaginatedResult<DeedListItem>
        │
        ▼
DeedRepository
   ├── countByNotary(notaryId, words): int
   └── findPageByNotary(notaryId, words, limit, offset): DeedListItem[]
        │
        ▼
escrituras  ⟕ actos_notaria  ⟕ tipo_escritura
     └─ EXISTS participantes_escritura (tipo 1,2; estatus 1)
        │
        ▼
DeedListItemResponse::fromCollection() + PaginatedResult::toArray() → JSON
```

### Módulos Afectados

- [ ] `src/Module/Deed/` – **Nuevo** módulo (Entity, DTO, Repository, Service, Controller)
- [ ] `src/Shared/Pagination/PaginatedResult.php` – **Nuevo**, reutilizable por futuros listados
- [ ] `src/bootstrap.php` – Registrar `DeedRepository` y `DeedService`
- [ ] `src/Config/routes.php` – Agregar `GET /api/v3/deeds` y quitar `/deeds` del bloque comentado
- [ ] `docs/openapi.yaml` – Documentar el endpoint
- [ ] `.db/pending/` → `.db/applied/` – Migración de índices
- [ ] `tests/Module/Deed/` – Tests unitarios

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 15 min

#### 0.1 Migración de índices
- **Archivo:** `.db/pending/YYYYMMDD_HHMMSS_add_search_indexes_to_escrituras.sql`
- **Tipo:** Crear
- **Descripción:** Agregar `idx_escrituras_notaria (id_notaria)` en `escrituras` e `idx_participantes_escritura_tipo (id_escritura, id_tipo_participante, id_estatus)` en `participantes_escritura`. Aplicarla en local y moverla a `.db/applied/`. SQL exacto en `developer_notes.md`.
- **Dependencias:** Ninguna. **Bloqueante:** sin esto la búsqueda no es usable.

### Fase 1: Shared + Entity + DTOs
Estimación: 45 min

#### 1.1 PaginatedResult
- **Archivo:** `src/Shared/Pagination/PaginatedResult.php`
- **Tipo:** Crear
- **Descripción:** Objeto inmutable con `items`, `total`, `page`, `perPage`; método `totalPages()` (`ceil(total/perPage)`, 0 si `total = 0`) y `toArray(callable $mapper)` que produce `{ data, pagination: { page, perPage, total, totalPages } }`.
- **Dependencias:** Ninguna

#### 1.2 Entity DeedListItem
- **Archivo:** `src/Module/Deed/Entity/DeedListItem.php`
- **Tipo:** Crear
- **Descripción:** Read model del listado (no es la entidad completa `Deed`, que llegará con el CRUD). Propiedades readonly: `id`, `deedNumber`, `book`, `deedDate (?DateTimeImmutable)`, `actName (?string)`, `deedTypeName (?string)`, `isFinished (bool)`. Incluye `fromRow()`; aplica `trim()` a `num_escritura` (hay datos con espacios o `|` al final).
- **Dependencias:** Ninguna

#### 1.3 DTO DeedListQuery
- **Archivo:** `src/Module/Deed/DTO/DeedListQuery.php`
- **Tipo:** Crear
- **Descripción:** `fromQueryParams(?string $page, ?string $search)`. Valida `page` (entero ≥ 1, default 1) y normaliza `search` en `words: string[]` (separa por espacios, quita vacíos, máximo 10 palabras de hasta 100 caracteres cada una). Expone `isValid()` y `getErrors()`, como los DTOs existentes.
- **Dependencias:** Ninguna

#### 1.4 DTO DeedListItemResponse
- **Archivo:** `src/Module/Deed/DTO/DeedListItemResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` y `fromCollection()` → `{ id, deedNumber, book, deedDate (Y-m-d|null), actName, deedTypeName, isFinished }`.
- **Dependencias:** 1.2

### Fase 2: Repository
Estimación: 1 h

#### 2.1 DeedRepository
- **Archivo:** `src/Module/Deed/Repository/DeedRepository.php`
- **Tipo:** Crear
- **Descripción:**
  - `countByNotary(int $notaryId, array $words): int`
  - `findPageByNotary(int $notaryId, array $words, int $limit, int $offset): DeedListItem[]`
  - `private buildFilter(int $notaryId, array $words): array{0: string, 1: array}` devuelve el `WHERE` y sus parámetros, compartido por ambos métodos (una sola fuente de verdad para el filtro).
  - Ordenamiento: `CAST(e.num_escritura AS UNSIGNED) DESC, e.num_escritura DESC, e.id_escritura DESC`.
- **Dependencias:** 0.1, 1.2

### Fase 3: Service
Estimación: 20 min

#### 3.1 DeedService
- **Archivo:** `src/Module/Deed/Service/DeedService.php`
- **Tipo:** Crear
- **Descripción:** Constante `public const PER_PAGE = 100;`. El método `list(int $notaryId, DeedListQuery $query): PaginatedResult` calcula el offset, ejecuta el conteo y, solo si `offset < total`, consulta la página (si la página está fuera de rango se evita la segunda consulta y se regresa `[]`).
- **Dependencias:** 1.1, 1.3, 2.1

### Fase 4: Controller, rutas y wiring
Estimación: 30 min

#### 4.1 DeedController
- **Archivo:** `src/Module/Deed/Controller/DeedController.php`
- **Tipo:** Crear
- **Descripción:** `index(Request)`: construye `DeedListQuery` a partir de `page` y `search`. Si no es válido responde `400 'Validation failed'` con los errores; si es válido llama al service y responde `200` con el resultado paginado.
- **Dependencias:** 3.1

#### 4.2 Registro en bootstrap
- **Archivo:** `src/bootstrap.php` – **Modificar.** Registrar `DeedRepository` y `DeedService`.

#### 4.3 Ruta
- **Archivo:** `src/Config/routes.php` – **Modificar.** `$router->get('/api/v3/deeds', [DeedController::class, 'index'])->withMiddleware('auth');`. Quitar la línea `/deeds` (index) del bloque comentado.

### Fase 5: Documentación y tests
Estimación: 1 h

#### 5.1 OpenAPI
- **Archivo:** `docs/openapi.yaml` – **Modificar.** Path `/api/v3/deeds`, parámetros `page` y `search`, schemas `DeedListItem` y `Pagination`, y respuestas 200, 400 y 401.

#### 5.2 Tests unitarios
- **Archivos:** `tests/Module/Deed/{DTO,Entity,Service}/…`, `tests/Shared/Pagination/PaginatedResultTest.php`
- **Tipo:** Crear. Ver `technical_acceptance_criteria.md`.

## Orden de Implementación

1. **Migración de índices**: bloqueante para probar la búsqueda con datos reales.
2. **PaginatedResult, DeedListItem y DTOs**: no tienen dependencias y se pueden testear primero.
3. **DeedRepository**: es el núcleo; se valida con queries manuales contra la BD local.
4. **DeedService**: orquesta conteo y página.
5. **Controller, bootstrap y routes**: activan el endpoint.
6. **OpenAPI y tests**.

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Búsqueda lenta sin índice (medido: >2 min por `EXISTS` correlacionado + `OR`) | Alta si se omite | Alto | Migración obligatoria (Fase 0). Medido con índice: 180–460 ms (conteo + página) |
| Collation `utf8mb3_bin`: `LIKE '%perez%'` no encuentra `PEREZ` (medido: 0 contra 1518 resultados) | Alta | Alto | `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci` en cada comparación |
| Reusar un placeholder con nombre falla con `EMULATE_PREPARES=false` | Alta | Medio | Nombres únicos por palabra y contexto (`:d0`, `:p0`, …) |
| El usuario envía `%` o `_` y se interpretan como comodines | Media | Bajo | Escapar con `addcslashes($w, '%_\\')` |
| Números duplicados (p. ej. `1050` aparece 2 veces) causan paginación inestable | Media | Medio | Desempate final `e.id_escritura DESC` |
| Las tablas MyISAM bloquean la tabla completa al crear índices en producción | Baja | Bajo | Ejecutar la migración fuera de horario (~60k filas, segundos) |
| El legacy v1 hace hard delete; no hay soft delete | — | — | Decisión del usuario: sin filtro por ahora (ADR-004) |

## Decisiones de Arquitectura

### ADR-001: Búsqueda en SQL con LIKE por palabra (sin FULLTEXT)
- **Contexto:** Se busca por subcadenas (p. ej. `"123"` debe encontrar `1234`) en número, acto y participantes. FULLTEXT no soporta subcadenas, requiere un mínimo de caracteres y no indexa columnas de varias tablas a la vez.
- **Decisión:** `LIKE '%palabra%'` por cada palabra, unidas con AND. Se limita a 10 palabras.
- **Consecuencias:** Siempre hay full scan dentro de la notaría (≤13k escrituras / 60k participantes en la notaría más grande): medido <0.5 s. Si el volumen crece un orden de magnitud, revisar una tabla desnormalizada de búsqueda.

### ADR-002: Todas las palabras deben coincidir dentro del mismo participante (decisión del usuario)
- **Contexto:** `"juan perez"` no debe traer una escritura con "Juan López" y "María Pérez".
- **Decisión:** Una escritura coincide si **(a)** todas las palabras están en `CONCAT_WS(' ', num_escritura, nombre_acto)`, **o (b)** existe **un** participante activo (tipo 1 o 2) donde todas las palabras están en `CONCAT_WS(' ', num_escritura, nombre_acto, nombre_participante)`.
- **Consecuencias:** Permite combinar acto o número con persona (`"compraventa perez"`). La rama (a) cubre escrituras sin participantes. El orden de las palabras es irrelevante.

### ADR-003: Comparación sin distinguir mayúsculas ni acentos en el query (sin alterar el schema)
- **Contexto:** Las columnas son `utf8mb3_bin` y la conexión es `utf8mb4`. Cambiar el collation de las tablas afectaría al sistema legacy, que comparte la BD.
- **Decisión:** `CONVERT(expr USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :param`. Así `pérez`, `Perez` y `PEREZ` son equivalentes.
- **Consecuencias:** Los índices no se usan en la comparación (con `%…%` tampoco se usarían). Resultado validado contra la BD.

### ADR-004: Sin filtro de soft delete (decisión del usuario)
- **Contexto:** `escrituras` no tiene columna de estatus. El PO pedía excluir las eliminadas.
- **Decisión:** Se listan todas las escrituras de la notaría. El soft delete se diseñará junto con `DELETE /deeds/{id}`.
- **Consecuencias:** Los criterios del PO se ajustaron (escenario 9). Los **participantes** sí tienen `id_estatus`: solo se busca en los que tienen `id_estatus = 1`.

### ADR-005: Ordenar primero los números y después el texto, en orden descendente
- **Contexto:** `num_escritura` es `varchar` (ej. `"16024 BIS"`, `"CDXXIX"`, `"MARIA ANTONIA TOSCANO"`).
- **Decisión:** `CAST(num AS UNSIGNED) DESC, num DESC, id DESC`. `"16024 BIS"` se ordena como 16024. Los valores sin número inicial (cast = 0) quedan al final.
- **Consecuencias:** Es determinista. El cast genera warnings de MariaDB que no son errores (PDO no lanza excepción).

### ADR-006: Tamaño de página fijo en una constante
- **Contexto:** El usuario pidió que fuera "configurable, en una constante".
- **Decisión:** `DeedService::PER_PAGE = 100`. El cliente **no** puede enviar `perPage`, pero el valor se devuelve en la respuesta.
- **Consecuencias:** Para cambiarlo basta editar una línea. Si en el futuro se expone al cliente, se agrega a `DeedListQuery` con un tope.

### ADR-007: `page` inválido responde 400
- **Contexto:** El PO define `page` como entero positivo con default 1. Los controllers existentes responden 400 cuando un query param es inválido (ej. `status` en DeedType).
- **Decisión:** Si falta `page`, se usa 1. Si es no numérico o menor a 1, se responde `400 Validation failed`. Una página mayor que la última responde `200` con `data: []`.
