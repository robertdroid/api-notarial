# Notas para el Developer

## Contexto Rápido

Se construye `GET /api/v3/deeds`: listado paginado (100 por página) de las escrituras de la notaría del token, ordenado por número descendente, con búsqueda libre en número, acto y participantes (enajenantes y adquirientes). Es el primer endpoint del módulo `Deed`; el CRUD completo vendrá después.

**Antes de escribir código, aplica la migración de índices.** Sin ella, una búsqueda tarda más de 2 minutos (medido).

## ⚠️ Nombres reales de la BD (ignora los de `po/technical_context.md`)

| PO supuso | Real |
|-----------|------|
| `escrituras.id` | `escrituras.id_escritura` |
| `escrituras.fecha` | `escrituras.fecha_escritura` |
| `escrituras.id_tipo_escritura` | `escrituras.id_tipo_escitura` (typo legacy, sin "r") |
| `escrituras.status` | **No existe** (ver ADR-004) |
| `tipos_escritura` | `tipo_escritura` (PK `id_tipo_escitura`, nombre `nombre_tipo`) |
| `actos_notaria.nombre` | `actos_notaria.nombre_acto` (PK `id_acto`) |
| `participantes_escritura.nombre` | `nombre_participante` |
| `participantes_escritura.tipo_participante` | `id_tipo_participante` (1 = Enajenante, 2 = Adquiriente) |
| — | `participantes_escritura.id_estatus` (1 = activo, 0 = eliminado) |

Otros datos del esquema:
- Todas las tablas son **MyISAM** con collation `utf8mb3_bin`, que distingue mayúsculas y acentos. La conexión PDO es `utf8mb4` con `EMULATE_PREPARES=false`.
- `escrituras.terminada` es `int` (0/1). `num_escritura` es `varchar(50)`.
- Datos reales que el código debe tolerar: `"16024 BIS"`, `"18234 "` (con espacio final), `"21815|"`, `"CDXXIX"`, `"MARIA ANTONIA TOSCANO"`, `"00000000"` y números duplicados dentro de una notaría.
- La vista `view_deed_detail_main` tiene exactamente el SELECT que necesitamos, pero **no** se usa: no permite filtrar por participantes de forma eficiente y no queremos depender de ella. Se usan las tablas directamente.

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `.db/pending/…_add_search_indexes_to_escrituras.sql` | Índices obligatorios |
| `src/Shared/Pagination/PaginatedResult.php` | Resultado paginado genérico + serialización `{data, pagination}` |
| `src/Module/Deed/Entity/DeedListItem.php` | Read model de una fila del listado |
| `src/Module/Deed/DTO/DeedListQuery.php` | Validación de `page` y normalización de `search` en palabras |
| `src/Module/Deed/DTO/DeedListItemResponse.php` | Entity → array JSON |
| `src/Module/Deed/Repository/DeedRepository.php` | Conteo + página con el filtro compartido |
| `src/Module/Deed/Service/DeedService.php` | `PER_PAGE`, offset y orquestación |
| `src/Module/Deed/Controller/DeedController.php` | `index()` |
| `src/bootstrap.php`, `src/Config/routes.php`, `docs/openapi.yaml` | Wiring y documentación |

## Patrones a Seguir
- `src/Module/DeedType/`: estructura de controller (validación de query params → 400), service, `fromRow()` y `fromCollection()`
- `tests/Module/Act/`: estilo de tests (`final class …Test extends TestCase`, mocks con `createMock`)

## Base de Datos

### Migración (crear en `.db/pending/`, aplicar y mover a `.db/applied/`)

```sql
-- Migration: add_search_indexes_to_escrituras
-- Description: Indexes required by GET /api/v3/deeds (notary filter + participant EXISTS lookup)
-- Author: <dev>
-- Date: <YYYY-MM-DD>

-- UP
ALTER TABLE escrituras
    ADD INDEX idx_escrituras_notaria (id_notaria);

ALTER TABLE participantes_escritura
    ADD INDEX idx_participantes_escritura_tipo (id_escritura, id_tipo_participante, id_estatus);

-- DOWN (rollback)
-- ALTER TABLE escrituras DROP INDEX idx_escrituras_notaria;
-- ALTER TABLE participantes_escritura DROP INDEX idx_participantes_escritura_tipo;
```

Para aplicarla en local:
```bash
docker exec -i notarial-db mariadb -u<user> -p<pass> notarial_db < .db/pending/<archivo>.sql
```

### Query (validado contra la BD local)

```sql
-- Filtro compartido (el WHERE lo genera buildFilter)
FROM escrituras e
LEFT JOIN actos_notaria  a ON a.id_acto = e.id_acto
LEFT JOIN tipo_escritura t ON t.id_tipo_escitura = e.id_tipo_escitura
WHERE e.id_notaria = :notaryId
  -- solo si hay palabras:
  AND (
        ( <DEED_HAY> LIKE :d0 AND <DEED_HAY> LIKE :d1 ... )
     OR EXISTS (
          SELECT 1 FROM participantes_escritura p
          WHERE p.id_escritura = e.id_escritura
            AND p.id_estatus = 1
            AND p.id_tipo_participante IN (1, 2)
            AND <PART_HAY> LIKE :p0 AND <PART_HAY> LIKE :p1 ...
        )
  )

-- DEED_HAY = CONVERT(CONCAT_WS(' ', e.num_escritura, a.nombre_acto) USING utf8mb4) COLLATE utf8mb4_unicode_ci
-- PART_HAY = CONVERT(CONCAT_WS(' ', e.num_escritura, a.nombre_acto, p.nombre_participante) USING utf8mb4) COLLATE utf8mb4_unicode_ci

-- Conteo
SELECT COUNT(*) <FROM + WHERE>

-- Página
SELECT e.id_escritura, e.num_escritura, e.libro, e.fecha_escritura,
       a.nombre_acto, t.nombre_tipo, e.terminada
<FROM + WHERE>
ORDER BY CAST(e.num_escritura AS UNSIGNED) DESC, e.num_escritura DESC, e.id_escritura DESC
LIMIT :limit OFFSET :offset
```

## Código de Referencia

### Repository: construcción del filtro
```php
private const CI = 'CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci';

/** @param string[] $words  @return array{0: string, 1: array<string, mixed>} */
private function buildFilter(int $notaryId, array $words): array
{
    $where = 'e.id_notaria = :notaryId';
    $params = ['notaryId' => $notaryId];

    if ($words === []) {
        return [$where, $params];
    }

    $deedHay = sprintf(self::CI, "CONCAT_WS(' ', e.num_escritura, a.nombre_acto)");
    $partHay = sprintf(self::CI, "CONCAT_WS(' ', e.num_escritura, a.nombre_acto, p.nombre_participante)");

    $deedConds = [];
    $partConds = [];
    foreach ($words as $i => $word) {
        $like = '%' . addcslashes($word, '%_\\') . '%';
        // Unique names: native prepares (EMULATE_PREPARES=false) cannot reuse a placeholder
        $params["d{$i}"] = $like;
        $params["p{$i}"] = $like;
        $deedConds[] = "{$deedHay} LIKE :d{$i}";
        $partConds[] = "{$partHay} LIKE :p{$i}";
    }

    $where .= ' AND ((' . implode(' AND ', $deedConds) . ')'
        . ' OR EXISTS (SELECT 1 FROM participantes_escritura p'
        . ' WHERE p.id_escritura = e.id_escritura'
        . ' AND p.id_estatus = :activeStatus'
        . ' AND p.id_tipo_participante IN (:typeSeller, :typeBuyer)'
        . ' AND ' . implode(' AND ', $partConds) . '))';

    $params['activeStatus'] = self::PARTICIPANT_ACTIVE;   // 1
    $params['typeSeller']   = self::PARTICIPANT_SELLER;   // 1 = Enajenante
    $params['typeBuyer']    = self::PARTICIPANT_BUYER;    // 2 = Adquiriente

    return [$where, $params];
}
```

En `findPageByNotary`, enlaza los parámetros con `bindValue()` en un loop y después `LIMIT` y `OFFSET` con `PDO::PARAM_INT` (no los pases en `execute([...])`, porque llegarían como string).

### Service
```php
final class DeedService
{
    public const PER_PAGE = 100;

    public function __construct(private readonly DeedRepository $repository) {}

    public function list(int $notaryId, DeedListQuery $query): PaginatedResult
    {
        $offset = ($query->page - 1) * self::PER_PAGE;
        $total = $this->repository->countByNotary($notaryId, $query->words);

        $items = $offset < $total
            ? $this->repository->findPageByNotary($notaryId, $query->words, self::PER_PAGE, $offset)
            : [];

        return new PaginatedResult($items, $total, $query->page, self::PER_PAGE);
    }
}
```

### Controller
```php
public function index(Request $request): Response
{
    $query = DeedListQuery::fromQueryParams(
        $request->getQueryParam('page'),
        $request->getQueryParam('search'),
    );

    if (!$query->isValid()) {
        return Response::badRequest('Validation failed', $query->getErrors());
    }

    $result = $this->deedService->list($request->getNotaryId(), $query);

    return Response::json(
        $result->toArray(fn (DeedListItem $d) => DeedListItemResponse::fromEntity($d))
    );
}
```

### Validación de `page`
```php
// accepts "1", "25"; rejects "0", "-1", "1.5", "abc", "1e3"
if ($page !== null && !preg_match('/^[1-9]\d{0,8}$/', $page)) {
    $errors['page'] = 'Page must be a positive integer';
}
```

## Checklist de verificación manual (notaría 1 en la BD local)
Valores medidos durante el diseño. Sirven de referencia; cambian si cambian los datos.

| Request | Esperado |
|---------|----------|
| `GET /deeds` | `total = 13372`, `totalPages = 134`, primer `deedNumber = "39734"` |
| `?page=134` | 72 elementos; los últimos tienen números no numéricos (`CDXXIX`, `MARIA ANTONIA TOSCANO`, …) |
| `?page=135` | 200, `data: []`, `total = 13372` |
| `?search=perez juan` y `?search=juan perez` | Mismo resultado (22) |
| `?search=pérez` y `?search=PEREZ` | Mismo `total` |
| `?search=compraventa perez` | 232 |
| `?search=BIS` | 13 |
| `?search=39734` | 1 |
| `?search=100%` | 0 (el `%` no actúa como comodín) |
| `?page=0`, `?page=abc` | 400 |
| Sin token | 401 |
| Token de la notaría 3 | Ningún `id` de la notaría 1 |

## Checklist Pre-PR
- [ ] Migración aplicada en local y en `.db/applied/`
- [ ] El código sigue la arquitectura definida (Controller → Service → Repository)
- [ ] Tests escritos y pasando (`docker-compose exec api vendor/bin/phpunit`)
- [ ] Checklist manual completado
- [ ] Sin warnings de PHP
- [ ] `docs/openapi.yaml` actualizado
- [ ] Criterios de `technical_acceptance_criteria.md` revisados

## Preguntas Frecuentes

**P: ¿Por qué no usar `view_deed_detail_main`?**
R: Sirve para listar sin búsqueda, pero el filtro por participantes necesita `e.id_escritura` en un `EXISTS` y conviene un solo query builder para ambos casos. Además, la vista está definida con `DEFINER=root@localhost`, que puede no existir igual en producción.

**P: ¿Por qué `EXISTS` y no `JOIN participantes` + `DISTINCT`?**
R: El `JOIN` multiplica filas (una escritura tiene ~3 participantes), obliga a usar `DISTINCT` y complica el `COUNT`. `EXISTS` se detiene en la primera coincidencia y aprovecha el índice `(id_escritura, …)`.

**P: ¿Por qué hay dos ramas (número/acto y participante)?**
R: Para que las escrituras **sin** participantes también se encuentren por número o acto, y para que todas las palabras de un nombre tengan que estar en la **misma** persona (ADR-002).

**P: ¿Cambio el collation de las tablas?**
R: No. El sistema legacy comparte la BD. La comparación sin distinguir mayúsculas ni acentos se resuelve en el query (ADR-003).

**P: ¿Filtro por escrituras eliminadas?**
R: No, porque no existe la columna (ADR-004). Solo se filtran los **participantes** con `id_estatus = 1`.

## Contacto
Para dudas de negocio: revisar `specs/06_deeds_list/po/`
Para dudas de arquitectura: `specs/06_deeds_list/arch/implementation_plan.md` (ADRs)
