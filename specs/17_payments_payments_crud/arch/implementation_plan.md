# Plan de Implementación: Payments (Pagos) CRUD

## Resumen

CRUD parcial (POST, GET por id, GET por uuid, PUT) sobre `payments_payments`, con el mismo patrón que spec 11 (transacciones): UUID generado por el frontend, POST idempotente, UUID y cliente inmutables, aislamiento por notaría. A diferencia de spec 11, un pago **no tiene detalles**: cada escritura es una sola sentencia, así que no se usa `TransactionManager`.

Además se extrae la regla de formato de UUID a una utilidad compartida en `src/Shared/Uuid/` que usan Payments y Transactions.

## Arquitectura Propuesta

```
POST/GET/PUT /api/v3/payments
        │
        ▼
PaymentController ──► CreatePaymentRequest / UpdatePaymentRequest (valida formato)
        │                     │
        │                     └─► ClientUuid::validate() (Shared, reusado por Transactions)
        ▼
PaymentService ──► PaymentClientRepository (existe / activo / notaría)   [solo lectura]
        │
        ▼
PaymentRepository ──► payments_payments  ⟕ payments_clients (clientName)
        │
        ▼
Payment (Entity) ──► PaymentResponse (DTO)
```

Flujo del POST:

```
validar body (400)
  └─► findByUuidAndNotary ── existe ──► 200 con el existente (sin tocarlo)
        └─ no existe ─► cliente existe (404) y activo (422)
              └─► INSERT ── error 1062 ──► recargar por uuid ──► 200
                    └─ ok ─► recargar con clientName ──► 201
```

## Módulos Afectados

- [ ] `Shared/Uuid/` - **Nuevo.** Utilidad compartida de formato de UUID
- [ ] `Module/Payment/` - **Nuevo.** Módulo completo
- [ ] `Module/PaymentTransaction/` - **Modificar.** `TransactionFieldRules::uuid()` delega en la utilidad (sin cambio de comportamiento)
- [ ] `Config/routes.php`, `bootstrap.php` - Rutas y registro en el `ServiceLocator`
- [ ] `docs/openapi.yaml` - Documentación
- [ ] `.db/pending/` - Migración

## Tareas de Implementación

### Fase 0: Verificación de producción (bloqueante para la migración)
Estimación: 30 min

#### 0.1 Pre-checks sobre `payments_payments` y v1
- **Archivo(s):** ninguno (consultas en producción)
- **Tipo:** Verificar
- **Descripción:** La v1 sigue escribiendo en esta tabla durante unos días (periodo de validación de v3). Antes de aplicar el índice único, confirmar:
  - **A.** Duplicados por `(id_notary, uuid)` → debe retornar 0 filas.
  - **B.** UUID vacío (`uuid = ''`): en el índice único colisionarían entre sí → debe retornar 0 filas o limpiarse.
  - **C.** UUID fuera de `^[A-Za-z0-9_-]{1,20}$` → esas filas no se podrían consultar por uuid ni hacer PUT con uuid (400). Si existen, decidir antes de continuar.
  - **D.** Confirmar con quien opera v1 que sus INSERT generan `uuid` (si no lo hacen, un INSERT de v1 con `''` repetido fallará tras el índice único). Si v1 no genera uuid, aplicar la migración **después** de apagar v1 para escrituras.
- **Dependencias:** ninguna

### Fase 1: Base de datos
Estimación: 30 min

#### 1.1 Migración InnoDB + índices
- **Archivo(s):** `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql`
- **Tipo:** Crear
- **Descripción:** Mismo formato que `20261111_100100_...`, con pre-checks A–C comentados y bloque DOWN:
  ```sql
  -- UP
  ALTER TABLE payments_payments ENGINE=InnoDB;
  ALTER TABLE payments_payments
      ADD UNIQUE INDEX uq_payments_payments_notary_uuid (id_notary, uuid),
      ADD INDEX idx_payments_payments_notary_client (id_notary, id_client);
  -- DOWN
  -- ALTER TABLE payments_payments DROP INDEX idx_payments_payments_notary_client,
  --     DROP INDEX uq_payments_payments_notary_uuid;
  -- ALTER TABLE payments_payments ENGINE=MyISAM;
  ```
- **Dependencias:** 0.1 (en producción). En local no bloquea (0 duplicados).

### Fase 2: Utilidad compartida de UUID
Estimación: 45 min

#### 2.1 `ClientUuid`
- **Archivo(s):** `src/Shared/Uuid/ClientUuid.php`
- **Tipo:** Crear
- **Descripción:** Clase `final` con constantes `DEFAULT_LENGTH = 6` (lo que genera el frontend), `MAX_LENGTH = 20` (límite de la columna) y `ERROR_MESSAGE`. Método `validate(mixed $value): ?string` (mensaje de error o `null`) con la regex `^[A-Za-z0-9_-]{1,20}$/D`: sin `trim`, sin cambio de mayúsculas (columna `utf8mb3_bin`), rechaza no-strings (`?uuid[]=x`).
- **Dependencias:** ninguna

#### 2.2 `TransactionFieldRules::uuid()` delega
- **Archivo(s):** `src/Module/PaymentTransaction/DTO/TransactionFieldRules.php`
- **Tipo:** Modificar
- **Descripción:** `uuid()` retorna `ClientUuid::validate($value)`. `UUID_ERROR` se mantiene como alias de `ClientUuid::ERROR_MESSAGE` si algún test lo referencia. Los tests existentes de spec 11 deben pasar sin cambios.
- **Dependencias:** 2.1

### Fase 3: Entity, DTOs y errores
Estimación: 1 hora

#### 3.1 `Payment` (Entity)
- **Archivo(s):** `src/Module/Payment/Entity/Payment.php`
- **Tipo:** Crear
- **Descripción:** `final`, readonly: `id`, `uuid`, `notaryId`, `clientId`, `clientName`, `amount`, `?paymentDate` (`substr(...,0,10)` o `null` en legacy), `comments` (`null` → `""`). `fromRow()`.
- **Dependencias:** ninguna

#### 3.2 `PaymentFieldRules`
- **Archivo(s):** `src/Module/Payment/DTO/PaymentFieldRules.php`
- **Tipo:** Crear
- **Descripción:** `positiveInt`, `amount` (numérico finito, `> 0`, `<= MAX_MONEY = 999999999.99`), `date` (round-trip `!Y-m-d`), `text` (bytes, máx 65535). Mismo estilo que `TransactionFieldRules`. `uuid` se delega a `ClientUuid`.
- **Dependencias:** 2.1

#### 3.3 `CreatePaymentRequest` / `UpdatePaymentRequest`
- **Archivo(s):** `src/Module/Payment/DTO/CreatePaymentRequest.php`, `UpdatePaymentRequest.php`
- **Tipo:** Crear
- **Descripción:**
  - Create: `clientId`, `uuid`, `amount` requeridos; `paymentDate` (default = hoy en `America/Mexico_City`, resuelto en el DTO) y `comments` (default `""`) opcionales; `null` = omitido. `amount` no se redondea.
  - Update: `amount` requerido; `paymentDate` y `comments` opcionales **nullable** (`null` u omitido = conservar); `uuid` y `clientId` opcionales, la comparación con lo almacenado la hace el Service.
  - Ambos: `fromArray()`, `isValid()`, `getErrors()` como en spec 11.
- **Dependencias:** 3.2

#### 3.4 `PaymentResponse`
- **Archivo(s):** `src/Module/Payment/DTO/PaymentResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity()` → `paymentId, uuid, clientId, clientName, amount, paymentDate, comments`.
- **Dependencias:** 3.1

#### 3.5 Errores de dominio
- **Archivo(s):** `src/Module/Payment/Exception/PaymentError.php`, `PaymentException.php`, `DuplicatePaymentUuidException.php`
- **Tipo:** Crear
- **Descripción:** Enum `PaymentError` con mensaje y HTTP: `PaymentNotFound` (404, "Payment not found"), `ClientNotFound` (404, "Client not found"), `ClientInactive` (422, "Client is inactive"), `CannotChangeClient` (400, "Cannot change client"), `CannotChangeUuid` (400, "Cannot change UUID").
- **Dependencias:** ninguna

### Fase 4: Repository
Estimación: 1 hora

#### 4.1 `PaymentRepository`
- **Archivo(s):** `src/Module/Payment/Repository/PaymentRepository.php`
- **Tipo:** Crear
- **Descripción:**
  - `SELECT` base con `LEFT JOIN payments_clients c ON c.id_client = p.id_client AND c.id_notary = p.id_notary`.
  - `findByIdAndNotary`, `findByUuidAndNotary` (comparación de uuid sin transformar la columna para usar el índice).
  - `insert(CreatePaymentRequest, int $notaryId): int`; captura `errorInfo[1] === 1062` → `DuplicatePaymentUuidException`.
  - `update(int $id, int $notaryId, ...)`: no toca `uuid`, `id_client` ni `id_notary`; sin chequeo de `rowCount()` (0 si no cambia).
  - `payment_date` se guarda como `YYYY-MM-DD 00:00:00`.
- **Dependencias:** 3.1, 3.3, 3.5

### Fase 5: Service
Estimación: 1 hora

#### 5.1 `PaymentService` y `CreatePaymentResult`
- **Archivo(s):** `src/Module/Payment/Service/PaymentService.php`, `CreatePaymentResult.php`
- **Tipo:** Crear
- **Descripción:**
  - `getById`, `getByUuid` → `PaymentNotFound` si no existe en la notaría.
  - `create`: orden del PO (existente → 200; cliente 404/422; INSERT; carrera → 200; recarga → 201).
  - `update`: cargar actual (404), rechazar `clientId` distinto (`CannotChangeClient`) y `uuid` distinto (`CannotChangeUuid`), fusionar campos omitidos con los actuales, `UPDATE`, recargar. **No** valida cliente activo (escenario 18).
  - Sin `TransactionManager` (ADR-002).
- **Dependencias:** 4.1, `PaymentClientRepository` existente

### Fase 6: Controller, rutas y registro
Estimación: 45 min

#### 6.1 `PaymentController`
- **Archivo(s):** `src/Module/Payment/Controller/PaymentController.php`
- **Tipo:** Crear
- **Descripción:** `store`, `show`, `showByUuid`, `update`; `parseId` y `errorResponse` como en spec 11. `showByUuid`: sin query → 400 `uuid query parameter is required`; formato inválido → 400 `Invalid uuid format`.
- **Dependencias:** 5.1

#### 6.2 Rutas y bootstrap
- **Archivo(s):** `src/Config/routes.php`, `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Rutas protegidas con `auth`: `GET /api/v3/payments` (→ `showByUuid`), `GET /api/v3/payments/{id}`, `POST /api/v3/payments`, `PUT /api/v3/payments/{id}`. Registrar `PaymentRepository` y `PaymentService`.
- **Dependencias:** 6.1

### Fase 7: Tests
Estimación: 2 horas

#### 7.1 Tests unitarios
- **Archivo(s):** `tests/Shared/Uuid/ClientUuidTest.php`, `tests/Module/Payment/{DTO,Entity,Exception,Service}/...`
- **Tipo:** Crear
- **Descripción:** `ClientUuid` (6 y 20 válidos, 21 / vacío / espacios / array / caracteres inválidos rechazados, `abc` ≠ `ABC`); DTOs (defaults, `amount` 0/negativo/string/NaN, fecha inválida `2024-02-30`, `null` en PUT); Entity (legacy `null`); `PaymentError`; Service con mocks (idempotencia sin consultar cliente, carrera 1062 → existente, cliente 404/422, PUT sin validar cliente activo, `CannotChange*`, reenvío de uuid/clientId iguales → OK, fusión de campos omitidos).
- **Dependencias:** Fases 2–5

#### 7.2 Verificación de integración
- **Descripción:** Probar los 18 escenarios del PO contra Docker con dos notarías (aislamiento y mismo uuid en otra notaría). QA documenta resultados.
- **Dependencias:** 1.1, 6.2

### Fase 8: Documentación
Estimación: 30 min

#### 8.1 OpenAPI
- **Archivo(s):** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Paths `/payments` (GET con query `uuid`, POST con 201/200) y `/payments/{id}` (GET, PUT), schemas `Payment`, `CreatePaymentRequest`, `UpdatePaymentRequest`, errores 400/404/422.
- **Dependencias:** 6.2

## Orden de Implementación

1. 0.1 Pre-checks de producción (en paralelo con lo demás; bloquea solo el deploy de la migración)
2. 1.1 Migración (aplicar en local)
3. 2.1 `ClientUuid` → 2.2 delegar en `TransactionFieldRules` (correr tests de spec 11)
4. 3.1–3.5 Entity, reglas, DTOs, errores
5. 4.1 Repository
6. 5.1 Service
7. 6.1–6.2 Controller, rutas, bootstrap
8. 7.1 Tests (idealmente junto a cada capa)
9. 8.1 OpenAPI
10. 7.2 Verificación de los escenarios

**Total estimado: ~7 horas** (el PO estimaba 6–7 h; se suman 45 min de la utilidad compartida y 30 min de pre-checks).

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| v1 inserta filas con `uuid` vacío o repetido y el índice único falla | Media | Alto | Pre-checks A/B/D (tarea 0.1); aplicar la migración al apagar escrituras de v1 si v1 no genera uuid |
| UUID legacy con caracteres fuera de `[A-Za-z0-9_-]` | Baja | Medio | Pre-check C; esas filas darían 400 en GET por uuid y PUT con uuid |
| Refactor de `TransactionFieldRules::uuid()` rompe spec 11 | Baja | Medio | Cambio sin variación de comportamiento; correr la suite de `PaymentTransaction` antes de continuar |
| Migración a InnoDB con tabla en uso por v1 | Baja | Bajo | 16 filas locales; `ALTER` rápido. Aplicar en ventana de bajo uso |
| Dos POST simultáneos con el mismo uuid | Media | Bajo | Índice único + captura de 1062 → 200 con el existente |

## Decisiones de Arquitectura

### ADR-001: Módulo `Payment` y ruta `/api/v3/payments`
- **Contexto:** Los módulos `Payment*` actuales mapean a `payments_clients`, `payments_transactions`, etc. El PO deja el nombre a Arch.
- **Decisión:** Módulo `Payment` y ruta `/api/v3/payments` (sin colisión: las demás rutas son `payment-*`). Se descarta `PaymentPayment` por redundante.
- **Consecuencias:** `App\Module\Payment` convive con `PaymentClient`, etc.; en búsquedas, filtrar por ruta completa.

### ADR-002: Sin `TransactionManager`
- **Contexto:** Spec 11 necesitó transacciones por cabecera + detalles. Aquí cada escritura es una sola sentencia (atómica por sí misma).
- **Decisión:** Service sin `TransactionManager`; PUT sin `SELECT ... FOR UPDATE`.
- **Consecuencias:** Dos PUT concurrentes se resuelven por último-escritor-gana, aceptable porque el PUT es un reemplazo de campos y no hay estado derivado. `uuid` y `id_client` nunca se actualizan, por lo que no hay carrera sobre inmutabilidad.

### ADR-003: Idempotencia antes de validar cliente
- **Contexto:** Un reintento offline no debe fallar si el cliente se desactivó después del primer POST.
- **Decisión:** Buscar el uuid antes de validar el cliente; POST con uuid existente retorna 200 sin modificar, aunque el body difiera.
- **Consecuencias:** Igual que spec 11 (ADR-003 allí). Cambiar datos exige PUT explícito.

### ADR-004: Índice `(id_notary, id_client)` en lugar de `id_client`
- **Contexto:** El PO pide índice por `id_client`. Toda consulta filtra por `id_notary` (multi-tenant) y spec 11 usa el índice compuesto.
- **Decisión:** `idx_payments_payments_notary_client (id_notary, id_client)`. Se prepara para el listado por cliente (fuera de alcance hoy).
- **Consecuencias:** Desvío menor del PO; no afecta el contrato de la API.

### ADR-005: Utilidad compartida `ClientUuid`
- **Contexto:** Dos módulos validan el mismo UUID generado por el frontend (hoy de 6 caracteres; la columna admite 20). Duplicar la regla arriesga divergencia.
- **Decisión:** `src/Shared/Uuid/ClientUuid` con `DEFAULT_LENGTH = 6`, `MAX_LENGTH = 20` y `validate()`. El backend **valida 1–20** (PO) y no genera UUID; `DEFAULT_LENGTH` documenta lo que el frontend debe generar y queda disponible para tests y fixtures. Se descarta exigir exactamente 6 para no romper UUID legacy ni el límite de 20 que el PO prevé para el futuro.
- **Consecuencias:** `TransactionFieldRules::uuid()` delega. Si la longitud cambia, se modifica en un solo lugar.

### ADR-006: Formato de errores 400
- **Contexto:** El PO muestra `{"error": "Amount must be greater than zero"}`, pero el proyecto usa `{"error": "Validation failed", "errors": {campo: mensaje}}` para validación de DTO.
- **Decisión:** Mantener el formato del proyecto para validación de campos (`errors.amount = "Amount must be greater than zero"`). Los errores de dominio (`Cannot change UUID`, `Cannot change client`, `uuid query parameter is required`, `Payment not found`, etc.) salen como `{"error": "<mensaje>"}` tal como pide el PO.
- **Consecuencias:** El frontend ya consume este formato en spec 11. QA debe verificar el mensaje en `errors.<campo>` para validaciones de DTO, no en `error`.

### ADR-007: Reglas del monto y de campos nulos en PUT
- **Contexto:** La columna es `double` sin límite; el PO acepta más de 2 decimales y no define máximo ni `null` en PUT.
- **Decisión:** `amount` numérico (int/float, no string), finito, `> 0` y `<= 999999999.99` (mismo tope que spec 11); sin redondeo. En PUT, `paymentDate`/`comments` `null` u omitidos conservan el valor; para vaciar los comentarios se envía `""`.
- **Consecuencias:** Un registro legacy con `payment_date` `NULL` lo conserva si el PUT no manda fecha.
