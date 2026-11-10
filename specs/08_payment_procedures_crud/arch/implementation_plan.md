# Plan de Implementación: CRUD Procedimientos de Pago (Payment Procedures)

## Resumen

Nuevo módulo `PaymentProcedure` con 4 endpoints protegidos por JWT (`GET` lista, `GET` detalle, `POST`, `PUT`) sobre la tabla legacy `payment_procedures`. Sigue la estructura del módulo `PaymentTax` (Controller → Service → Repository → Entity + DTOs, registro en `ServiceLocator`). Diferencias clave respecto a PaymentTax:

1. **Columna NOT NULL**: `procedure_name` no admite nulos en BD, simplificando la conversión en `fromRow()`.
2. **Unicidad condicional**: Se valida solo si el nombre cambia (para tolerar el duplicado legacy "RATIFICACION" ids 11/12).
3. **Documentación de errores**: Se listan los códigos y mensajes de error en el plan para que el frontend pueda identificarlos.

Se agrega una migración con el índice `(id_notary, id_status)`.

## Arquitectura Propuesta

### Diagrama de Componentes

```
HTTP Request
    │
    ▼
Router ──► AuthMiddleware (JWT → notaryId, userId)
               │ 401 si falta, es inválido o expiró
               ▼
        PaymentProcedureController
          │  - parseId(): 400 "Invalid ID format" / 404 si <= 0
          │  - DTO::fromArray() → 400 "Validation failed" + errors
          ▼
        PaymentProcedureService
          │  - trim, unicidad condicional, merge en PUT parcial
          │  - retorna: true | string (error negocio) | null (no existe)
          ▼
        PaymentProcedureRepository  ──(WHERE id_notary = :notaryId SIEMPRE)──►  payment_procedures (MyISAM, utf8mb3_bin)
          │
          ▼
        PaymentProcedure (Entity) ──► PaymentProcedureResponse { procedureId, procedureName, status }
```

### Módulos Afectados

- [ ] `src/Module/PaymentProcedure/` - **Nuevo** módulo completo (Entity, DTO×3, Repository, Service, Controller)
- [ ] `src/bootstrap.php` - Registrar `PaymentProcedureRepository` y `PaymentProcedureService` en `ServiceLocator`
- [ ] `src/Config/routes.php` - Agregar 4 rutas protegidas
- [ ] `.db/pending/` - Migración con el índice `idx_payment_procedures_notary_status`
- [ ] `docs/openapi.yaml` - Documentar `/payment-procedures` y `/payment-procedures/{id}` y schemas
- [ ] `tests/Module/PaymentProcedure/` - Tests unitarios (Entity, DTOs, Response, Service)

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 15 min

#### 0.1 Migración de índice
- **Archivo:** `.db/pending/YYYYMMDD_HHMMSS_add_notary_status_index_to_payment_procedures.sql`
- **Tipo:** Crear
- **Descripción:** `ALTER TABLE payment_procedures ADD INDEX idx_payment_procedures_notary_status (id_notary, id_status);` Aplicar en local y mover a `.db/applied/`. **No** se agrega índice UNIQUE (ver ADR-002).
- **Dependencias:** Ninguna

### Fase 1: Entity y DTOs
Estimación: 1 h

#### 1.1 Entity `PaymentProcedure`
- **Archivo:** `src/Module/PaymentProcedure/Entity/PaymentProcedure.php`
- **Tipo:** Crear
- **Descripción:** `final class` con propiedades `readonly`: `id`, `notaryId`, `name`, `statusId`. `fromRow()` mapea `id_procedure`, `id_notary`, `procedure_name` y `id_status`. Como `procedure_name` es NOT NULL, se usa `(string) $row['procedure_name']` directamente. `id_status` admite NULL → default `1`. No se mapean `created_at`/`modified_at` (no se exponen ni se usan).
- **Dependencias:** Ninguna

#### 1.2 DTO `CreatePaymentProcedureRequest`
- **Archivo:** `src/Module/PaymentProcedure/DTO/CreatePaymentProcedureRequest.php`
- **Tipo:** Crear
- **Descripción:** `procedureName` requerido: string, no vacío tras `trim`, ≤ 500 caracteres (`mb_strlen`) y sin caracteres de 4 bytes. `status` opcional: default `1`, debe ser `int` 1 o 2. Guarda `procedureName` ya recortado.
- **Dependencias:** 1.4 (validador compartido)

#### 1.3 DTO `UpdatePaymentProcedureRequest`
- **Archivo:** `src/Module/PaymentProcedure/DTO/UpdatePaymentProcedureRequest.php`
- **Tipo:** Crear
- **Descripción:** `?string $procedureName` y `?int $status`. Distingue "campo ausente" (`null`, no cambia) de "campo presente con valor inválido" con `array_key_exists`. Si ninguno de los dos campos viene → error `body: "At least one field (procedureName, status) is required"`.
- **Dependencias:** 1.4

#### 1.4 Validador `ProcedureNameRule` (compartido por Create/Update)
- **Archivo:** `src/Module/PaymentProcedure/DTO/ProcedureNameRule.php`
- **Tipo:** Crear
- **Descripción:** Clase `final` con un método estático `validate(mixed $value): ?string` que devuelve el mensaje de error o `null`. Evita duplicar las 4 reglas de nombre en dos DTOs. Misma lógica que `TaxNameRule` pero con mensajes "Procedure name ...".
- **Dependencias:** Ninguna

#### 1.5 DTO `PaymentProcedureResponse`
- **Archivo:** `src/Module/PaymentProcedure/DTO/PaymentProcedureResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` → `{ procedureId, procedureName, status }`. `fromCollection()`. **No** incluye `notaryId` (criterio de rechazo del PO).
- **Dependencias:** 1.1

### Fase 2: Repository
Estimación: 45 min

#### 2.1 `PaymentProcedureRepository`
- **Archivo:** `src/Module/PaymentProcedure/Repository/PaymentProcedureRepository.php`
- **Tipo:** Crear
- **Descripción:** Métodos:
  - `findAllByNotary(int $notaryId, ?int $status, ?string $name): PaymentProcedure[]` → `ORDER BY procedure_name ASC`. Escapa los comodines del término de búsqueda (`ESCAPE '!'`).
  - `findByIdAndNotary(int $id, int $notaryId): ?PaymentProcedure`
  - `existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool`, insensible a acentos y mayúsculas vía `CONVERT(...) COLLATE utf8mb4_unicode_ci`
  - `create(PaymentProcedure $procedure): int`
  - `update(PaymentProcedure $procedure): void` → el `WHERE` incluye `id_notary`. `modified_at` lo actualiza la BD (`ON UPDATE`).
- **Dependencias:** 1.1, PDO

### Fase 3: Service
Estimación: 30 min

#### 3.1 `PaymentProcedureService`
- **Archivo:** `src/Module/PaymentProcedure/Service/PaymentProcedureService.php`
- **Tipo:** Crear
- **Descripción:**
  - `getAll(int $notaryId, ?int $status, ?string $name): array`
  - `getById(int $id, int $notaryId): ?PaymentProcedure`
  - `create(CreatePaymentProcedureRequest $req, int $notaryId): true|string` → `'Procedure name already exists'` si hay duplicado
  - `update(int $id, UpdatePaymentProcedureRequest $req, int $notaryId): true|string|null`. Devuelve `null` si no existe en la notaría. **Unicidad condicional**: valida solo si `$req->procedureName !== null && strtolower(trim($req->procedureName)) !== strtolower($existing->name)` (tolera el duplicado legacy y el Escenario "PUT mismo nombre").
- **Dependencias:** 2.1, 1.2, 1.3

### Fase 4: Controller, rutas y wiring
Estimación: 30 min

#### 4.1 `PaymentProcedureController`
- **Archivo:** `src/Module/PaymentProcedure/Controller/PaymentProcedureController.php`
- **Tipo:** Crear
- **Descripción:** `index`, `show`, `store` y `update`. Valida el query param `status` (`'1'`/`'2'`; otro valor → 400 `Invalid status value`). El helper privado `parseId()` usa `filter_var(FILTER_VALIDATE_INT)`: un formato inválido → 400 `Invalid ID format`, y `<= 0` → 404 `Procedure not found`.
- **Dependencias:** 3.1

#### 4.2 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `PaymentProcedureRepository` (con `Connection::getInstance()`) y `PaymentProcedureService`.
- **Dependencias:** 2.1, 3.1

#### 4.3 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** 4 rutas `/api/v3/payment-procedures[/{id}]` con `->withMiddleware('auth')`. **Sin DELETE**: el router responde 405 por sí solo.
- **Dependencias:** 4.1

### Fase 5: Tests y documentación
Estimación: 1.5 h

#### 5.1 Tests unitarios
- **Archivos:** `tests/Module/PaymentProcedure/{Entity,DTO,Service}/...Test.php`
- **Tipo:** Crear
- **Descripción:** Mismo estilo que `tests/Module/PaymentTax/`. Casos mínimos en `developer_notes.md`.
- **Dependencias:** Fases 1–3

#### 5.2 OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Tag `PaymentProcedures`, los 2 paths, los schemas `PaymentProcedure`, `CreatePaymentProcedureRequest` y `UpdatePaymentProcedureRequest`, y las respuestas 200/201/204/400/401/404. Copiar la estructura de `/payment-taxes`.
- **Dependencias:** 4.3

## Orden de Implementación

1. **Migración (0.1)**: independiente. Aplicarla primero permite probar con el índice real.
2. **Entity (1.1)**: es la base de Repository, Service y Response.
3. **ProcedureNameRule → DTOs (1.4, 1.2, 1.3, 1.5)**: se pueden testear aislados.
4. **Repository (2.1)**: probar las queries de acentos contra la BD local antes de seguir.
5. **Service (3.1)** + sus tests con mocks.
6. **bootstrap.php (4.2)**: wiring.
7. **Controller (4.1) + rutas (4.3)**: activar endpoints y probar con curl/Postman.
8. **OpenAPI (5.2)**.

## Catálogo de Errores (para frontend)

| HTTP | Campo en body        | Mensaje                                              | Causa                                      |
|------|----------------------|------------------------------------------------------|--------------------------------------------|
| 400  | `error`              | `"Invalid ID format"`                                | ID en ruta no es entero válido             |
| 400  | `error`              | `"Invalid status value"`                             | Query param `status` ≠ 1, 2                |
| 400  | `error`              | `"Validation failed"`                                | Body inválido; ver `errors`                |
| 400  | `errors.procedureName` | `"Procedure name is required"`                     | Campo ausente o vacío tras trim            |
| 400  | `errors.procedureName` | `"Procedure name must be a string"`                | Tipo incorrecto                            |
| 400  | `errors.procedureName` | `"Procedure name must not exceed 500 characters"`  | Más de 500 chars                           |
| 400  | `errors.procedureName` | `"Procedure name contains unsupported characters"` | Emoji / 4-byte UTF-8                       |
| 400  | `errors.status`      | `"Status must be 1 or 2"`                            | Valor o tipo incorrecto                    |
| 400  | `errors.body`        | `"At least one field (procedureName, status) is required"` | PUT sin campos reconocidos        |
| 400  | `error`              | `"Procedure name already exists"`                    | Duplicado en la misma notaría              |
| 401  | `error`              | (mensaje del AuthMiddleware, actualmente en español) | Token ausente, inválido o expirado         |
| 404  | `error`              | `"Procedure not found"`                              | ID no existe o pertenece a otra notaría    |
| 405  | -                    | (respuesta del router)                               | Método no permitido (ej. DELETE)           |

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| `utf8mb3_bin` hace la búsqueda sensible a acentos (el Escenario 3 falla con `LOWER`) | **Confirmado** | Alto | `CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci` en búsqueda y unicidad (ADR-001) |
| Emoji o caracteres de 4 bytes → error SQL 1366 → 500 | Media | Medio | `ProcedureNameRule` los rechaza con 400 |
| Tipos JSON inesperados (`"procedureName": 123`, `"status": "1"`) → `TypeError` con `strict_types` → 500 | Media | Medio | DTOs verifican `is_string`/`is_int` antes de asignar |
| Duplicado legacy "RATIFICACION" (ids 11/12) rompe PUT si valida unicidad siempre | **Confirmado** | Medio | Validar solo si el nombre cambia (ADR-003) |
| Condición de carrera: dos POST simultáneos con el mismo nombre (MyISAM sin transacciones y sin UNIQUE) | Baja | Bajo | Se acepta: es un catálogo de baja concurrencia |
| `CONVERT(...)` impide usar un índice sobre `procedure_name` | Baja | Bajo | El filtro por `id_notary` sí usa el índice nuevo. Pocos registros por notaría (~20) |
| Comodines `%`/`_` en `?name=` devuelven resultados inesperados | Media | Bajo | `LIKE :name ESCAPE '!'` + escapar `!`, `%` y `_` con `!` |
| Los mensajes 401 del `AuthMiddleware` están en español | **Conocido** | Bajo | Fuera de alcance. Frontend valida por código 401 (ver ADR-004) |

## Decisiones de Arquitectura

### ADR-001: Comparación insensible a acentos y mayúsculas vía `CONVERT ... COLLATE`
- **Contexto:** `payment_procedures` es `utf8mb3_bin`. `LOWER()` no normaliza acentos.
- **Decisión:** Usar `CONVERT(procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci` en la búsqueda (`LIKE`) y en la unicidad (`=`).
- **Consecuencias:** Consistente con PaymentTax. No se modifica la collation de la tabla para no afectar la API legacy v1.

### ADR-002: Unicidad a nivel aplicación, sin índice UNIQUE
- **Contexto:** La regla es insensible a acentos y mayúsculas; un UNIQUE solo bloquearía duplicados byte a byte. Además existe un duplicado legacy.
- **Decisión:** Validar en el Service con `existsByNameAndNotary()`. La migración solo agrega un índice no-único `(id_notary, id_status)`.
- **Consecuencias:** Ventana de carrera mínima, aceptable para un catálogo administrativo.

### ADR-003: Unicidad condicional en UPDATE
- **Contexto:** El duplicado "RATIFICACION" (ids 11 inactivo, 12 activo) existe en producción. Un PUT al id 12 que reenvíe el nombre daría 400 por culpa del 11.
- **Decisión:** Validar unicidad en UPDATE **solo si el nombre cambia** (comparación case-insensitive con el valor actual). Si `newName.lower() == existingName.lower()`, no se valida.
- **Consecuencias:** El duplicado legacy queda tolerable. Si se desea limpiarlo, se hará en un ticket de datos separado.

### ADR-004: Mensajes 401 fuera de alcance
- **Contexto:** `AuthMiddleware` devuelve mensajes en español. Afecta a todos los módulos.
- **Decisión:** No modificar en este feature. El frontend valida por código 401.
- **Consecuencias:** Deuda técnica transversal documentada.

### ADR-005: Respuestas 201/204 sin body (consistencia)
- **Contexto:** El PO pedía 201 con body y 200 con body en PUT. Los módulos existentes (Taxes, Acts, DeedTypes) usan 201 sin body y 204 sin body.
- **Decisión:** Seguir el patrón existente para no romper la consistencia de la API.
- **Consecuencias:** Se ajusta el criterio del PO. El frontend que necesite el id del recurso creado puede obtenerlo con un GET posterior (patrón actual).
