# Plan de Implementación: CRUD Impuestos de Pago (Payment Taxes)

## Resumen

Nuevo módulo `PaymentTax` con 4 endpoints protegidos por JWT (`GET` lista, `GET` detalle, `POST`, `PUT`) sobre la tabla legacy `payment_taxes`. Sigue la estructura del módulo `DeedType` (Controller → Service → Repository → Entity + DTOs, registro en `ServiceLocator`). Hay **tres diferencias deliberadas** respecto a DeedType:

1. **PUT parcial**: `taxName` y `status` son opcionales. Se combinan con el registro existente (DeedType exige todos los campos).
2. **Búsqueda y unicidad insensibles a acentos y mayúsculas**: la tabla usa `utf8mb3_bin`. El patrón `LOWER() LIKE` no encuentra "Pago de Subdivisión" al buscar `subdivision` (verificado en BD local). Se usa `CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci`.
3. **Validación estricta de tipos y charset en DTOs**: se rechazan tipos JSON incorrectos (los DTOs actuales lanzan un `TypeError` y responden 500) y caracteres de 4 bytes (emoji) que la columna `utf8mb3` no admite.

Además se agrega una migración con el índice `(id_notary, id_status)`.

## Arquitectura Propuesta

### Diagrama de Componentes

```
HTTP Request
    │
    ▼
Router ──► AuthMiddleware (JWT → notaryId, userId)
               │ 401 si falta, es inválido o expiró
               ▼
        PaymentTaxController
          │  - parseId(): 400 "Invalid ID format" / 404 si <= 0
          │  - DTO::fromArray() → 400 "Validation failed" + errors
          ▼
        PaymentTaxService
          │  - trim, unicidad, merge en PUT parcial
          │  - retorna: true | string (error negocio) | null (no existe)
          ▼
        PaymentTaxRepository  ──(WHERE id_notary = :notaryId SIEMPRE)──►  payment_taxes (MyISAM, utf8mb3_bin)
          │
          ▼
        PaymentTax (Entity) ──► PaymentTaxResponse { taxId, taxName, status }
```

### Módulos Afectados

- [ ] `src/Module/PaymentTax/` - **Nuevo** módulo completo (Entity, DTO×3, Repository, Service, Controller)
- [ ] `src/bootstrap.php` - Registrar `PaymentTaxRepository` y `PaymentTaxService` en `ServiceLocator`
- [ ] `src/Config/routes.php` - Agregar 4 rutas protegidas
- [ ] `.db/pending/` - Migración con el índice `idx_payment_taxes_notary_status`
- [ ] `docs/openapi.yaml` - Documentar `/payment-taxes` y `/payment-taxes/{id}` y el schema `PaymentTax`
- [ ] `tests/Module/PaymentTax/` - Tests unitarios (Entity, DTOs, Response, Service)

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 15 min

#### 0.1 Migración de índice
- **Archivo:** `.db/pending/YYYYMMDD_HHMMSS_add_notary_status_index_to_payment_taxes.sql`
- **Tipo:** Crear
- **Descripción:** `ALTER TABLE payment_taxes ADD INDEX idx_payment_taxes_notary_status (id_notary, id_status);` Aplicar en local y mover a `.db/applied/` (ver `.db/README.md`). **No** se agrega índice UNIQUE (ver ADR-003).
- **Dependencias:** Ninguna

### Fase 1: Entity y DTOs
Estimación: 1 h

#### 1.1 Entity `PaymentTax`
- **Archivo:** `src/Module/PaymentTax/Entity/PaymentTax.php`
- **Tipo:** Crear
- **Descripción:** `final class` con propiedades `readonly`: `id`, `notaryId`, `name`, `statusId`. `fromRow()` mapea `id_tax`, `id_notary`, `tax_name` y `id_status`. `tax_name` admite NULL en BD → `(string) ($row['tax_name'] ?? '')`. `id_status` admite NULL → default `1`. No se mapean `created_at`/`modified_at` (no se exponen ni se usan).
- **Dependencias:** Ninguna

#### 1.2 DTO `CreatePaymentTaxRequest`
- **Archivo:** `src/Module/PaymentTax/DTO/CreatePaymentTaxRequest.php`
- **Tipo:** Crear
- **Descripción:** `taxName` requerido: string, no vacío tras `trim`, ≤ 500 caracteres (`mb_strlen`) y sin caracteres de 4 bytes. `status` opcional: default `1`, debe ser `int` 1 o 2. Guarda `taxName` ya recortado.
- **Dependencias:** 1.4 (validador compartido)

#### 1.3 DTO `UpdatePaymentTaxRequest`
- **Archivo:** `src/Module/PaymentTax/DTO/UpdatePaymentTaxRequest.php`
- **Tipo:** Crear
- **Descripción:** `?string $taxName` y `?int $status`. Distingue "campo ausente" (`null`, no cambia) de "campo presente con valor inválido" con `array_key_exists`. Si ninguno de los dos campos viene → error `body: "At least one field (taxName, status) is required"`.
- **Dependencias:** 1.4

#### 1.4 Validador `TaxNameRule` (compartido por Create/Update)
- **Archivo:** `src/Module/PaymentTax/DTO/TaxNameRule.php`
- **Tipo:** Crear
- **Descripción:** Clase `final` con un método estático `validate(mixed $value): ?string` que devuelve el mensaje de error o `null`. Evita duplicar las 4 reglas de nombre en dos DTOs (SRP/DRY).
- **Dependencias:** Ninguna

#### 1.5 DTO `PaymentTaxResponse`
- **Archivo:** `src/Module/PaymentTax/DTO/PaymentTaxResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` → `{ taxId, taxName, status }`. `fromCollection()`. **No** incluye `notaryId` (criterio de rechazo del PO).
- **Dependencias:** 1.1

### Fase 2: Repository
Estimación: 45 min

#### 2.1 `PaymentTaxRepository`
- **Archivo:** `src/Module/PaymentTax/Repository/PaymentTaxRepository.php`
- **Tipo:** Crear
- **Descripción:** Métodos:
  - `findAllByNotary(int $notaryId, ?int $status, ?string $name): PaymentTax[]` → `ORDER BY tax_name ASC`. Escapa los comodines del término de búsqueda (`ESCAPE '!'`).
  - `findByIdAndNotary(int $id, int $notaryId): ?PaymentTax`
  - `existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool`, insensible a acentos y mayúsculas
  - `create(PaymentTax $tax): int`
  - `update(PaymentTax $tax): void` → el `WHERE` incluye `id_notary`. `modified_at` lo actualiza la BD (`ON UPDATE`).
- **Dependencias:** 1.1, PDO

### Fase 3: Service
Estimación: 30 min

#### 3.1 `PaymentTaxService`
- **Archivo:** `src/Module/PaymentTax/Service/PaymentTaxService.php`
- **Tipo:** Crear
- **Descripción:**
  - `getAll(int $notaryId, ?int $status, ?string $name): array`
  - `getById(int $id, int $notaryId): ?PaymentTax`
  - `create(CreatePaymentTaxRequest $req, int $notaryId): true|string` → `'Tax name already exists'` si hay duplicado
  - `update(int $id, UpdatePaymentTaxRequest $req, int $notaryId): true|string|null`. Devuelve `null` si no existe en la notaría. Combina los valores: `name = req->taxName ?? existing->name` y `status = req->status ?? existing->statusId`. Valida unicidad **solo si `taxName` viene en el request** (y excluye el propio id, lo que cubre el Escenario 20).
- **Dependencias:** 2.1, 1.2, 1.3

### Fase 4: Controller, rutas y wiring
Estimación: 30 min

#### 4.1 `PaymentTaxController`
- **Archivo:** `src/Module/PaymentTax/Controller/PaymentTaxController.php`
- **Tipo:** Crear
- **Descripción:** `index`, `show`, `store` y `update`. Valida el query param `status` (`'1'`/`'2'`; otro valor → 400 `Invalid status value`). El helper privado `parseId()` usa `filter_var(FILTER_VALIDATE_INT)`: un formato inválido → 400 `Invalid ID format`, y `<= 0` → 404 `Tax not found`.
- **Dependencias:** 3.1

#### 4.2 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `PaymentTaxRepository` (con `Connection::getInstance()`) y `PaymentTaxService`.
- **Dependencias:** 2.1, 3.1

#### 4.3 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** 4 rutas `/api/v3/payment-taxes[/{id}]` con `->withMiddleware('auth')`. **Sin DELETE**: el router responde 405 por sí solo.
- **Dependencias:** 4.1

### Fase 5: Tests y documentación
Estimación: 1.5 h

#### 5.1 Tests unitarios
- **Archivos:** `tests/Module/PaymentTax/{Entity,DTO,Service}/...Test.php`
- **Tipo:** Crear
- **Descripción:** Mismo estilo que `tests/Module/Act/` (PHPUnit con mocks del repository). Casos mínimos en `developer_notes.md`.
- **Dependencias:** Fases 1–3

#### 5.2 OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Tag `PaymentTaxes`, los 2 paths, los schemas `PaymentTax`, `CreatePaymentTaxRequest` y `UpdatePaymentTaxRequest`, y las respuestas 200/201/204/400/401/404. Copiar la estructura de `/deed-types`.
- **Dependencias:** 4.3

## Orden de Implementación

1. **Migración (0.1)**: independiente. Aplicarla primero permite probar con el índice real.
2. **Entity (1.1)**: es la base de Repository, Service y Response.
3. **TaxNameRule → DTOs (1.4, 1.2, 1.3, 1.5)**: se pueden testear aislados.
4. **Repository (2.1)**: probar las queries de acentos contra la BD local antes de seguir.
5. **Service (3.1)** + sus tests con mocks.
6. **bootstrap.php (4.2)**: wiring.
7. **Controller (4.1) + rutas (4.3)**: activar endpoints y probar con curl/Postman.
8. **OpenAPI (5.2)**.

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| `utf8mb3_bin` hace la búsqueda sensible a acentos (el Escenario 3 falla con `LOWER`) | **Confirmado** | Alto | `CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci` en búsqueda y unicidad (ADR-001) |
| Emoji o caracteres de 4 bytes → error SQL 1366 → 500 (la conexión es `utf8mb4` y la columna `utf8mb3`) | Media | Medio | `TaxNameRule` los rechaza con 400 |
| Tipos JSON inesperados (`"taxName": 123`, `"status": "1"`) → `TypeError` con `strict_types` → 500 | Media | Medio | DTOs verifican `is_string`/`is_int` antes de asignar |
| Condición de carrera: dos POST simultáneos con el mismo nombre (MyISAM sin transacciones y sin UNIQUE) | Baja | Bajo | Se acepta: es un catálogo de baja concurrencia (ADR-003) |
| `CONVERT(...)` impide usar un índice sobre `tax_name` | Baja | Bajo | El filtro por `id_notary` sí usa el índice nuevo. Pocos registros por notaría (~10) |
| Comodines `%`/`_` en `?name=` devuelven resultados inesperados | Media | Bajo | `LIKE :name ESCAPE '!'` + escapar `!`, `%` y `_` con `!` (verificado: `?name=%` → `[]`) |
| `tax_name` NULL en datos legacy | Baja | Bajo | `fromRow()` lo convierte a `''` |
| Los mensajes 401 del `AuthMiddleware` están en español; el PO espera "Unauthorized"/"Token expired" (Esc. 21–23) | **Confirmado** | Bajo | Fuera de alcance: es transversal a todos los módulos. Se valida el **código 401** (ver ADR-005) |

## Decisiones de Arquitectura

### ADR-001: Comparación insensible a acentos y mayúsculas vía `CONVERT ... COLLATE`
- **Contexto:** `payment_taxes` es `utf8mb3_bin`. En la BD local, `LOWER(tax_name) LIKE '%subdivision%'` devuelve 0 filas para "Pago de Subdivisión"; con `CONVERT(tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE '%subdivision%'` devuelve 2.
- **Decisión:** Usar esa expresión en la búsqueda (`LIKE`) y en la unicidad (`=`). Así "Subdivision" se considera duplicado de "Subdivisión", y "isr" de "ISR". Decisión confirmada con el usuario.
- **Consecuencias:** Difiere de DeedType/Acts (que siguen sensibles a acentos). No se modifica la collation de la tabla para no afectar la API legacy v1, que comparte la BD. Si se quiere homogeneizar en otros módulos, se hará en un ticket aparte.

### ADR-002: PUT con semántica parcial (merge)
- **Contexto:** El PO define `taxName` y `status` como opcionales en PUT (Escenario 16: `{"status": 2}` sin nombre).
- **Decisión:** El DTO usa propiedades nullables (`null` = no enviado). El Service combina los valores con la entidad existente. Un body sin ningún campo reconocido → 400.
- **Consecuencias:** Técnicamente se comporta como PATCH sobre el verbo PUT. Se mantiene PUT porque así lo especifica el PO y por consistencia con el resto de la API v3.

### ADR-003: Unicidad a nivel aplicación, sin índice UNIQUE
- **Contexto:** El módulo Act agregó un UNIQUE `(id_notaria, nombre_acto)`. Aquí la regla es insensible a acentos y mayúsculas, y la columna es `_bin`: un UNIQUE solo bloquearía duplicados exactos byte a byte.
- **Decisión:** Validar en el Service con `existsByNameAndNotary()`. La migración solo agrega un índice no-único `(id_notary, id_status)`.
- **Consecuencias:** Ventana de carrera mínima, aceptable para un catálogo administrativo. Si hiciera falta una garantía fuerte, la vía sería una columna normalizada (`tax_name_normalized`) con UNIQUE, en un ticket futuro.

### ADR-004: Mantener el contrato de retorno `true|string|null` del Service
- **Contexto:** Los módulos existentes devuelven `bool|string|null` en lugar de lanzar excepciones de dominio. No es lo ideal: usa strings como canal de error.
- **Decisión:** Mantenerlo por consistencia con Act, Subdivision y DeedType. Tipar como `true|string|null` (PHP 8.2+) en vez de `bool|string|null`, porque `false` nunca se devuelve.
- **Consecuencias:** El controller puede copiarse casi 1:1 de DeedType. La migración a excepciones de dominio (`DuplicateResourceException`, etc.) queda como deuda técnica transversal.

### ADR-005: Mensajes 401 fuera de alcance
- **Contexto:** Los Escenarios 21–23 esperan "Unauthorized" / "Token expired". `AuthMiddleware` devuelve "Token no proporcionado" / "Token inválido o expirado". Features anteriores (02, 03, 04) tuvieron el mismo criterio y QA lo aprobó por el código 401.
- **Decisión:** No modificar `AuthMiddleware` en este feature. Para QA, el criterio es **status 401**.
- **Consecuencias:** Se recomienda un ticket de deuda técnica para traducir los mensajes del middleware al inglés (CLAUDE.md lo exige) y distinguir "Token expired" capturando `ExpiredException`. Afecta a todos los endpoints y al frontend.

### ADR-006: Repository concreto, sin interfaz
- **Contexto:** CLAUDE.md menciona interfaces de repositorio, pero ningún módulo existente las usa. Los tests mockean la clase concreta.
- **Decisión:** Seguir el patrón existente (clase concreta).
- **Consecuencias:** Consistencia inmediata. La introducción de interfaces debe hacerse de forma transversal, no módulo a módulo.
