# Plan de Implementación: CRUD Clientes de Pago (Payment Clients)

## Resumen

Nuevo módulo `PaymentClient` con 4 endpoints protegidos por JWT (`GET` lista, `GET` detalle, `POST`, `PUT`) sobre la tabla legacy `payments_clients`. Sigue la estructura de `PaymentProcedure` (Controller → Service → Repository → Entity + DTOs, registro en `ServiceLocator`). Diferencias clave respecto a PaymentProcedure:

1. **Sin unicidad**: dos clientes pueden llamarse igual → no hay `existsByNameAndNotary()`, el Service queda delgado y sin errores de negocio.
2. **Más campos**: `phone` (≤ 20) y `email` (≤ 250, formato válido si no está vacío), ambos opcionales y vaciables.
3. **PUT parcial con limpieza**: enviar `"phone": ""` o `"email": ""` borra el valor; omitir el campo lo conserva.
4. **Validación por campo centralizada** en una sola clase `ClientFieldRules` (en vez de una `*NameRule` por campo).

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
        PaymentClientController
          │  - index(): valida ?status (1|2) → 400 "Invalid status value"
          │  - parseId(): 400 "Invalid ID format" / 404 si <= 0
          │  - DTO::fromArray() → 400 "Validation failed" + errors
          ▼
        PaymentClientService
          │  - create(): void
          │  - update(): bool  (false = no existe en la notaría → 404)
          │  - merge de PUT parcial con el registro existente
          ▼
        PaymentClientRepository ──(WHERE id_notary = :notaryId SIEMPRE)──► payments_clients (MyISAM, utf8mb3_bin)
          │
          ▼
        PaymentClient (Entity) ──► PaymentClientResponse { clientId, fullName, phone, email, status }

        DTOs Create/Update ──usan──► ClientFieldRules (fullName, phone, email, status)
```

### Módulos Afectados

- [ ] `src/Module/PaymentClient/` - **Nuevo** módulo completo (Entity, DTO×4, Repository, Service, Controller)
- [ ] `src/bootstrap.php` - Registrar `PaymentClientRepository` y `PaymentClientService` en `ServiceLocator`
- [ ] `src/Config/routes.php` - Agregar 4 rutas protegidas
- [ ] `.db/pending/` - Migración con el índice `idx_payments_clients_notary_status`
- [ ] `docs/openapi.yaml` - Documentar `/payment-clients` y `/payment-clients/{id}` y schemas
- [ ] `tests/Module/PaymentClient/` - Tests unitarios (Entity, Rules, DTOs, Response, Service)

## Tareas de Implementación

### Fase 0: Base de datos
Estimación: 15 min

#### 0.1 Migración de índice
- **Archivo:** `.db/pending/20261110_HHMMSS_add_notary_status_index_to_payments_clients.sql`
- **Tipo:** Crear
- **Descripción:** `ALTER TABLE payments_clients ADD INDEX idx_payments_clients_notary_status (id_notary, id_status);` Aplicar en local y mover a `.db/applied/`. Mismo formato que la migración de `payment_procedures`.
- **Dependencias:** Ninguna

### Fase 1: Entity, reglas y DTOs
Estimación: 1.5 h

#### 1.1 Entity `PaymentClient`
- **Archivo:** `src/Module/PaymentClient/Entity/PaymentClient.php`
- **Tipo:** Crear
- **Descripción:** `final class` con propiedades `readonly`: `id`, `notaryId`, `fullName`, `phone`, `email`, `statusId`. `fromRow()` mapea `id_client`, `id_notary`, `full_name`, `phone`, `email` e `id_status`. `full_name`/`phone`/`email` son NOT NULL → cast `(string)` directo. `id_status` admite NULL → default `1`. No se mapean `created_at`/`modified_at`.
- **Dependencias:** Ninguna

#### 1.2 Reglas `ClientFieldRules` (compartidas por Create/Update)
- **Archivo:** `src/Module/PaymentClient/DTO/ClientFieldRules.php`
- **Tipo:** Crear
- **Descripción:** Clase `final` con métodos estáticos que devuelven el mensaje de error o `null`:
  - `fullName(mixed $value): ?string` → string, no vacío tras `trim`, ≤ 250 (`mb_strlen`), sin caracteres de 4 bytes.
  - `phone(mixed $value): ?string` → string (o `null`, ver ADR-003), ≤ 20 tras `trim`, sin 4 bytes. Sin validación de formato.
  - `email(mixed $value): ?string` → string (o `null`), ≤ 250 tras `trim`, sin 4 bytes, y si no está vacío debe pasar `filter_var(FILTER_VALIDATE_EMAIL)`.
  - `status(mixed $value): ?string` → `int` 1 o 2.
  - Helper privado `hasUnsupportedChars(string): bool` (regex `[\x{10000}-\x{10FFFF}]`).
  - Helper público `normalizeOptional(mixed $value): string` → `null` → `''`, string → `trim()`.
- **Dependencias:** Ninguna

#### 1.3 DTO `CreatePaymentClientRequest`
- **Archivo:** `src/Module/PaymentClient/DTO/CreatePaymentClientRequest.php`
- **Tipo:** Crear
- **Descripción:** `fullName` requerido (ausente → `"Full name is required"`). `phone`/`email` opcionales, default `''`. `status` opcional, default `1`. Guarda todos los strings ya recortados. Mismo patrón `fromArray()` / `isValid()` / `getErrors()`.
- **Dependencias:** 1.2

#### 1.4 DTO `UpdatePaymentClientRequest`
- **Archivo:** `src/Module/PaymentClient/DTO/UpdatePaymentClientRequest.php`
- **Tipo:** Crear
- **Descripción:** `?string $fullName`, `?string $phone`, `?string $email`, `?int $status`. `null` = "campo ausente, no cambiar". Se usa `array_key_exists` para distinguir ausente de presente. Un `phone`/`email` presente con `""` o `null` se normaliza a `''` (= limpiar). Si no viene ninguno de los 4 campos → `body: "At least one field (fullName, phone, email, status) is required"`.
- **Dependencias:** 1.2

#### 1.5 DTO `PaymentClientResponse`
- **Archivo:** `src/Module/PaymentClient/DTO/PaymentClientResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` → `{ clientId, fullName, phone, email, status }`. `fromCollection()`. **No** incluye `notaryId`, `created_at` ni `modified_at` (criterio de rechazo del PO).
- **Dependencias:** 1.1

### Fase 2: Repository
Estimación: 30 min

#### 2.1 `PaymentClientRepository`
- **Archivo:** `src/Module/PaymentClient/Repository/PaymentClientRepository.php`
- **Tipo:** Crear
- **Descripción:** Métodos:
  - `findAllByNotary(int $notaryId, ?int $status, ?string $name): PaymentClient[]` → búsqueda con `NAME_EXPR LIKE :name ESCAPE '!'` (comodines escapados), `ORDER BY NAME_EXPR ASC, id_client ASC` (ver ADR-005).
  - `findByIdAndNotary(int $id, int $notaryId): ?PaymentClient`
  - `create(PaymentClient $client): int`
  - `update(PaymentClient $client): void` → `WHERE id_client = :id AND id_notary = :notaryId`. `modified_at` lo actualiza la BD.
  - **No** existe `existsByNameAndNotary()` (ADR-002).
- **Dependencias:** 1.1, PDO

### Fase 3: Service
Estimación: 20 min

#### 3.1 `PaymentClientService`
- **Archivo:** `src/Module/PaymentClient/Service/PaymentClientService.php`
- **Tipo:** Crear
- **Descripción:**
  - `getAll(int $notaryId, ?int $status, ?string $name): array`
  - `getById(int $id, int $notaryId): ?PaymentClient`
  - `create(CreatePaymentClientRequest $req, int $notaryId): void`
  - `update(int $id, UpdatePaymentClientRequest $req, int $notaryId): bool` → `false` si no existe en la notaría. Merge: `$req->campo ?? $existing->campo` para cada uno de los 4 campos (funciona porque `''` no es `null`).
- **Dependencias:** 2.1, 1.3, 1.4

### Fase 4: Controller, rutas y wiring
Estimación: 30 min

#### 4.1 `PaymentClientController`
- **Archivo:** `src/Module/PaymentClient/Controller/PaymentClientController.php`
- **Tipo:** Crear
- **Descripción:** `index`, `show`, `store` y `update`, copiando `PaymentProcedureController`. `NOT_FOUND = 'Client not found'`. `store` → `Response::created()` (sin body). `update` → `false` ⇒ 404, `true` ⇒ `Response::noContent()`.
- **Dependencias:** 3.1

#### 4.2 Registro en contenedor
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `PaymentClientRepository` (con `Connection::getInstance()`) y `PaymentClientService`, debajo de PaymentProcedure.
- **Dependencias:** 2.1, 3.1

#### 4.3 Rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** 4 rutas `/api/v3/payment-clients[/{id}]` con `->withMiddleware('auth')`. **Sin DELETE**: el router responde 405.
- **Dependencias:** 4.1

### Fase 5: Tests y documentación
Estimación: 1.5 h

#### 5.1 Tests unitarios
- **Archivos:** `tests/Module/PaymentClient/{Entity,DTO,Service}/...Test.php`
- **Tipo:** Crear
- **Descripción:** Mismo estilo que `tests/Module/PaymentProcedure/` + `ClientFieldRulesTest`. Casos mínimos en `developer_notes.md`.
- **Dependencias:** Fases 1–3

#### 5.2 OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Tag `PaymentClients`, los 2 paths, los schemas `PaymentClient`, `CreatePaymentClientRequest` y `UpdatePaymentClientRequest`, y las respuestas 200/201/204/400/401/404. Copiar la estructura de `/payment-procedures`.
- **Dependencias:** 4.3

## Orden de Implementación

1. **Migración (0.1)**: independiente; permite probar con el índice real.
2. **Entity (1.1)**: base de Repository, Service y Response.
3. **ClientFieldRules (1.2)**: concentra toda la validación. Testearla a fondo primero reduce los tests de los DTOs.
4. **DTOs (1.3, 1.4, 1.5)**.
5. **Repository (2.1)**: probar la búsqueda con acentos contra la BD local.
6. **Service (3.1)** + tests con mocks.
7. **bootstrap.php (4.2)**: wiring.
8. **Controller (4.1) + rutas (4.3)**: activar endpoints y probar con curl/Postman.
9. **OpenAPI (5.2)**.

## Catálogo de Errores (para frontend)

| HTTP | Campo en body     | Mensaje                                                          | Causa                                   |
|------|-------------------|------------------------------------------------------------------|-----------------------------------------|
| 400  | `error`           | `"Invalid ID format"`                                            | ID en ruta no es entero válido          |
| 400  | `error`           | `"Invalid status value"`                                         | Query param `status` ≠ 1, 2             |
| 400  | `error`           | `"Validation failed"`                                            | Body inválido; ver `errors`             |
| 400  | `errors.fullName` | `"Full name is required"`                                        | Ausente (POST) o vacío tras trim        |
| 400  | `errors.fullName` | `"Full name must be a string"`                                   | Tipo incorrecto (incluye `null`)        |
| 400  | `errors.fullName` | `"Full name must not exceed 250 characters"`                     | Más de 250 chars                        |
| 400  | `errors.fullName` | `"Full name contains unsupported characters"`                    | Emoji / 4-byte UTF-8                    |
| 400  | `errors.phone`    | `"Phone must be a string"`                                       | Tipo incorrecto (ej. número)            |
| 400  | `errors.phone`    | `"Phone must not exceed 20 characters"`                          | Más de 20 chars tras trim               |
| 400  | `errors.phone`    | `"Phone contains unsupported characters"`                        | Emoji / 4-byte UTF-8                    |
| 400  | `errors.email`    | `"Email must be a string"`                                       | Tipo incorrecto                         |
| 400  | `errors.email`    | `"Email must not exceed 250 characters"`                         | Más de 250 chars tras trim              |
| 400  | `errors.email`    | `"Email format is invalid"`                                      | No vacío y no pasa `FILTER_VALIDATE_EMAIL` |
| 400  | `errors.status`   | `"Status must be 1 or 2"`                                        | Valor o tipo incorrecto                 |
| 400  | `errors.body`     | `"At least one field (fullName, phone, email, status) is required"` | PUT sin campos reconocidos           |
| 401  | `error`           | (mensaje del AuthMiddleware, actualmente en español)             | Token ausente, inválido o expirado      |
| 404  | `error`           | `"Client not found"`                                             | ID no existe o pertenece a otra notaría |
| 405  | -                 | (respuesta del router)                                           | Método no permitido (ej. DELETE)        |

> El email con 4 bytes no tiene mensaje propio: `FILTER_VALIDATE_EMAIL` ya rechaza cualquier carácter no ASCII → `"Email format is invalid"`.

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| `utf8mb3_bin`: `LOWER()` (sugerido por el PO) no encuentra "López" buscando "lopez" | **Confirmado** | Alto | `CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci` (ADR-001) |
| Emoji / 4 bytes en cualquier campo → error SQL 1366 → 500 | Media | Medio | `ClientFieldRules` los rechaza con 400 en los 3 campos de texto |
| Tipos JSON inesperados (`"phone": 5551234`, `"status": "1"`) → `TypeError` con `strict_types` → 500 | Media | Medio | Reglas verifican `is_string`/`is_int` antes de asignar |
| Frontend no puede obtener el `clientId` recién creado (201 sin body + nombres no únicos) | **Confirmado** | Medio | Decisión aceptada (ADR-004). Revisar cuando se implemente Transactions |
| `FILTER_VALIDATE_EMAIL` rechaza emails con acentos (`josé@dominio.mx`) | Baja | Bajo | Aceptado: la columna y los sistemas de envío son ASCII en la práctica |
| PUT con `"email": ""` confundido con "no cambiar" | Media | Medio | Contrato explícito: ausente = no cambia; `""`/`null` = limpiar. Documentado en OpenAPI |
| `ORDER BY` con `CONVERT` no usa índice | Baja | Bajo | ~20 registros por notaría; el filtro por `id_notary` sí usa el índice nuevo |
| Comodines `%`/`_` en `?name=` devuelven resultados inesperados | Media | Bajo | `LIKE :name ESCAPE '!'` + escapar `!`, `%`, `_` |
| Desactivar (status=2) un cliente con transacciones activas | Baja | Bajo | Fuera de alcance: no hay FK; Transactions deberá tolerar clientes inactivos |
| Mensajes 401 del `AuthMiddleware` en español | **Conocido** | Bajo | Fuera de alcance (ADR-004 de feature 08) |

## Decisiones de Arquitectura

### ADR-001: Búsqueda insensible a acentos y mayúsculas vía `CONVERT ... COLLATE`
- **Contexto:** El PO sugiere `LOWER(full_name) LIKE LOWER(?)`. La tabla es `utf8mb3_bin`; `LOWER` no normaliza acentos, y los nombres de clientes son de personas (López, Pérez, García...), así que el escenario 3 fallaría en la práctica.
- **Decisión:** `CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :name ESCAPE '!'`, igual que PaymentTax/PaymentProcedure.
- **Consecuencias:** Consistencia entre catálogos. No se cambia la collation de la tabla (afectaría a la API v1).

### ADR-002: Sin validación de unicidad
- **Contexto:** Requerimiento explícito del PO: dos personas distintas pueden llamarse igual.
- **Decisión:** No hay `existsByNameAndNotary()` ni índice UNIQUE. `create()` devuelve `void` y `update()` devuelve `bool`; ya no se necesita el retorno `true|string` de los otros catálogos.
- **Consecuencias:** El Service es más simple. Si en el futuro se quiere detectar duplicados, sería un *warning* en el frontend, no un error del API.

### ADR-003: Campos opcionales: ausente vs vacío vs `null`
- **Contexto:** `phone`/`email` son NOT NULL DEFAULT `''` en BD. En PUT parcial hay que poder distinguir "no tocar" de "borrar".
- **Decisión:**
  - Campo **ausente** en el body → no se modifica (PUT) / `''` (POST).
  - Campo con `""`, solo espacios o `null` → se guarda `''` (borra el valor).
  - `fullName` con `null` → 400 `"Full name must be a string"` (no puede borrarse).
- **Consecuencias:** El frontend puede mandar el formulario completo con `null` en campos vacíos sin recibir 400. Contrato documentado en OpenAPI.

### ADR-004: POST 201 y PUT 204 sin body (consistencia con ADR-005 de feature 08)
- **Contexto:** El PO pide 201 con el cliente creado y 200 en PUT. A diferencia de los otros catálogos, aquí los nombres **no son únicos**, así que el frontend no puede localizar con certeza el `clientId` recién creado con un GET posterior.
- **Decisión (confirmada con el equipo):** Mantener el patrón existente: `POST` → 201 sin body, `PUT` → 204 sin body.
- **Consecuencias:** Se ajustan los escenarios 6, 7, 10 y 11 del PO. Limitación conocida: un flujo "crear cliente y asignarlo a una transacción" no tiene forma fiable de obtener el ID. **Revisar esta decisión al diseñar el feature de Transactions**; el cambio sería trivial (`Response::created(PaymentClientResponse::fromEntity(...))`, ya soportado por `Response::created(mixed $data)`).

### ADR-005: Orden alfabético natural
- **Contexto:** `ORDER BY full_name` con `utf8mb3_bin` ordena por bytes: mayúsculas antes que minúsculas y "Álvarez" después de "Zúñiga".
- **Decisión:** `ORDER BY CONVERT(full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC, id_client ASC`. El segundo criterio hace estable el orden entre homónimos.
- **Consecuencias:** No se usa índice para ordenar; irrelevante con ~20 filas por notaría.

### ADR-006: Status inválido en query → 400
- **Contexto:** El PO deja abierto "ignorar filtro o 400" para `?status=3`.
- **Decisión:** 400 `"Invalid status value"`, igual que Taxes/Procedures.
- **Consecuencias:** Errores del cliente visibles en vez de resultados silenciosamente sin filtrar.
