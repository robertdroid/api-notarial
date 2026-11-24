# Plan de Implementación: Estado de Cuenta de Cliente (Payments)

## Resumen

Feature **solo lectura**: `GET /api/v3/payment-clients/{id}/statement?from=&to=`. Sin migraciones, sin dependencias nuevas. Módulo nuevo `src/Module/PaymentStatement/` con su propio repository de lectura (modelo de lectura liviano), que consume `PaymentClientRepository` para validar el cliente.

**Decisiones del equipo (ya cerradas):**
- Los 4 defaults del PO quedan **aprobados**: (1) cobro sin fecha solo aparece y suma cuando no hay `from`/`to`, (2) sin paginación ni límite, (3) cualquier usuario autenticado de la notaría puede consultar, (4) orden cronológico ascendente.
- **Sin migración de índices**: se usan `(id_notary, id_client)` existentes. Se verifica con EXPLAIN en QA.
- Rango filtrado **en SQL**, con límites datetime (`>= from 00:00:00` y `< to + 1 día`).
- Ubicación: módulo nuevo `PaymentStatement` (ADR-001).

**Correcciones al `technical_context.md` del PO** (no cambian el alcance):
1. El PO dice que ya existe un listado con `LEFT JOIN` en `PaymentTransactionRepository` y en `PaymentRepository`. **No existe**: ambos solo tienen `findByIdAndNotary`, `findByUuidAndNotary`, `insert`, `update`. Las consultas por rango son nuevas y viven en el módulo nuevo (ADR-002).
2. El índice `(id_notary, id_client)` de `payments_payments` está en `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql` (spec 17), **sin aplicar**. Ver riesgo R1.

## Arquitectura Propuesta

```
GET /api/v3/payment-clients/{id}/statement?from=&to=
        │  AuthMiddleware (JWT → notaryId)
        ▼
PaymentStatementController::show
  1. parseId  → 400 "Invalid ID format" | 404 "Client not found" (id <= 0)
  2. StatementQuery::fromArray(queryParams) → 400 "Validation failed" + errors
        ▼
PaymentStatementService::build(clientId, notaryId, StatementQuery): ?ClientStatement
  3. PaymentClientRepository::findByIdAndNotary → null ⇒ null (404)   [sin filtrar status]
  4. StatementRepository (4 consultas, todas con id_notary):
       findActiveTransactions(range)   ── trámites id_status=1 + LEFT JOIN procedimiento
       findPayments(range)             ── cobros
       sumActiveTransactionsBefore(from) ┐ solo si hay `from`
       sumPaymentsBefore(from)           ┘
  5. Totales en centavos enteros → ClientStatement
        ▼
StatementResponse::fromEntity(ClientStatement) → JSON 200
```

## Módulos Afectados
- [x] `Module/PaymentStatement/` — **nuevo** (Controller, Service, Repository, Entity, DTO)
- [x] `src/Config/routes.php` — 1 ruta
- [x] `src/bootstrap.php` — registrar `StatementRepository`, `PaymentStatementService`
- [x] `docs/openapi.yaml`, `README.md` — documentación
- [ ] `PaymentClient`, `PaymentTransaction`, `Payment` — **sin cambios** (solo se consume `PaymentClientRepository::findByIdAndNotary`)

## Estructura final

```
src/Module/PaymentStatement/
├── Controller/PaymentStatementController.php
├── Service/PaymentStatementService.php
├── Repository/StatementRepository.php
├── Entity/ClientStatement.php
├── Entity/StatementTransaction.php
├── Entity/StatementPayment.php
├── DTO/StatementQuery.php
└── DTO/StatementResponse.php

tests/Module/PaymentStatement/
├── DTO/StatementQueryTest.php
├── DTO/StatementResponseTest.php
└── Service/PaymentStatementServiceTest.php
```

## Tareas de Implementación

### Fase 1: Validación de parámetros
Estimación: 1.5 horas

#### 1.1 `StatementQuery`
- **Archivo(s):** `src/Module/PaymentStatement/DTO/StatementQuery.php`
- **Tipo:** Crear
- **Descripción:** DTO inmutable con constructor privado y `fromArray(array $params)` (mismo patrón que `CreatePaymentRequest`: `isValid()`, `getErrors()`).
  - `from` / `to`: ausente, `null` o `''` ⇒ `null` (no enviado). `is_string` obligatorio (`from[]=x` ⇒ error de formato, no `TypeError`).
  - Validar con `PaymentFieldRules::date()` (ya hace el round-trip `!Y-m-d`; rechaza `2026-02-31` y `2026-3-1`). Mensajes: `From must be a valid date in YYYY-MM-DD format` / `To must be ...` → `errors.from` / `errors.to`.
  - Si ambos válidos y `to < from` ⇒ `errors.to = 'To must not be before from'`. `from == to` es válido.
  - Si alguno es inválido **no** se compara el rango (un solo error por campo).
  - Parámetros desconocidos se ignoran.
  - Helpers de límites (datetime), calculados aquí para que el Repository no haga aritmética de fechas:
    `fromBoundary(): ?string` → `'2026-03-01 00:00:00'`; `toExclusiveBoundary(): ?string` → `to + 1 día` a las `00:00:00` (`DateTimeImmutable::modify('+1 day')`, zona `America/Mexico_City`).
- **Dependencias:** `PaymentFieldRules::date()` existente.

### Fase 2: Entities y Repository
Estimación: 2.5 horas

#### 2.1 Entities de lectura
- **Archivo(s):** `Entity/StatementTransaction.php`, `Entity/StatementPayment.php`, `Entity/ClientStatement.php`
- **Tipo:** Crear
- **Descripción:** `final`, propiedades `readonly` tipadas, `fromRow()` en las dos primeras.
  - `StatementTransaction`: `id`, `uuid`, `date` (`Y-m-d`), `procedureName` (`''` si el `LEFT JOIN` no encuentra), `reference`, `fee`, `taxes`, `total`.
  - `StatementPayment`: `id`, `uuid`, `date` (`?string`), `comment`, `amount`.
  - `ClientStatement`: `clientId`, `clientName`, `fromDate`, `toDate`, `transactions[]`, `payments[]`, `pendingAmount`, `totalCharges`, `totalPayments`, `balance` (ya redondeados a 2 decimales).

#### 2.2 `StatementRepository`
- **Archivo(s):** `Repository/StatementRepository.php` (`class`, no `final`, para poder mockearlo como los demás repositories)
- **Tipo:** Crear
- **Descripción:** solo acceso a datos; PDO preparado; **todas** las consultas con `id_notary = :notaryId AND id_client = :clientId`. Fechas siempre como parámetro (nunca concatenadas).

| Método | SQL (resumen) |
|--------|---------------|
| `findActiveTransactions(int $notaryId, int $clientId, ?string $from, ?string $toExclusive): array` | `SELECT t.id_transaction, t.uuid, t.reference, t.date, t.fee, t.taxes, t.total, p.procedure_name FROM payments_transactions t LEFT JOIN payment_procedures p ON p.id_procedure = t.id_procedure AND p.id_notary = t.id_notary WHERE t.id_notary=:n AND t.id_client=:c AND t.id_status = 1 [AND t.date >= :from] [AND t.date < :toExclusive] ORDER BY t.date ASC, t.id_transaction ASC` |
| `findPayments(...)` | `SELECT id_payment, uuid, payment_date, comments, amount FROM payments_payments WHERE id_notary=:n AND id_client=:c [AND payment_date >= :from] [AND payment_date < :toExclusive] ORDER BY (payment_date IS NULL), payment_date ASC, id_payment ASC` |
| `sumActiveTransactionsBefore(int $notaryId, int $clientId, string $from): float` | `SELECT COALESCE(SUM(total), 0) ... AND id_status = 1 AND `date` < :from` |
| `sumPaymentsBefore(...): float` | `SELECT COALESCE(SUM(amount), 0) ... AND payment_date < :from` |

  - Sin filtros: no hay `AND payment_date ...`, así un cobro con `payment_date` nulo **sí** aparece. Con `from` o `to` el predicado descarta el `NULL` solo (`NULL >= x` no es verdadero); no hace falta lógica extra.
  - `ORDER BY (payment_date IS NULL)` deja los cobros sin fecha **al final** (MariaDB los pondría primero). Detalle no definido por el PO; ver punto 3 de "Para devolver al PO".
  - El nombre del cliente **no** se une aquí: viene de `PaymentClient` (nombre actual, requisito del PO).
- **Dependencias:** 1.1 (los límites vienen de `StatementQuery`).

### Fase 3: Service y DTO de respuesta
Estimación: 2 horas

#### 3.1 `PaymentStatementService`
- **Archivo(s):** `Service/PaymentStatementService.php` (`final`)
- **Tipo:** Crear
- **Descripción:** constructor `(PaymentClientRepository, StatementRepository)`. `build(int $clientId, int $notaryId, StatementQuery $query): ?ClientStatement`.
  - Cliente no encontrado en la notaría ⇒ `null`. No se mira `statusId`.
  - Cálculo en **centavos enteros** (`(int) round($x * 100)`) para evitar deriva de `float`:
    - `totalCharges = Σ cents(total)` de la lista, `totalPayments = Σ cents(amount)` de la lista.
    - `pendingAmount = cents(sumTransactionsBefore) − cents(sumPaymentsBefore)` solo si hay `from`; si no, `0`.
    - `balance = pending + charges − payments` (aritmética entera, luego `/ 100`).
  - Salida: `round($cents / 100, 2)`. Por fila: `round($fee, 2)`, `round($taxes, 2)`, `round($total, 2)`, `round($amount, 2)`.
  - No recalcula `total` ni `taxes`.
  - Sin `from` no se ejecutan las 2 consultas de saldo anterior (ahorra 2 de las 5 consultas).

#### 3.2 `StatementResponse`
- **Archivo(s):** `DTO/StatementResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity(ClientStatement): array` con **exactamente** los nombres de campo de v1 (`clientId`, `clientName`, `fromDate`, `toDate`, `transactions[{transactionId, uuid, date, procedureName, reference, fee, taxes, total}]`, `payments[{paymentId, uuid, date, comment, amount}]`, `pendingAmount`, `totalCharges`, `totalPayments`, `balance`). Ojo: en la respuesta es `comment` (singular), aunque la columna es `comments`.

### Fase 4: HTTP
Estimación: 1 hora

#### 4.1 Controller y ruta
- **Archivo(s):** `Controller/PaymentStatementController.php`, `src/Config/routes.php`, `src/bootstrap.php`
- **Tipo:** Crear / Modificar
- **Descripción:**
  - `show(Request)`: orden exacto del PO: `parseId` → `StatementQuery` → service. `parseId` copia el de `PaymentClientController` (`FILTER_VALIDATE_INT`; `false` ⇒ 400 `Invalid ID format`; `<= 0` ⇒ 404 `Client not found`).
  - `query->isValid()` falso ⇒ `Response::badRequest('Validation failed', $query->getErrors())`.
  - Service devuelve `null` ⇒ `Response::notFound('Client not found')`. Éxito ⇒ `Response::json(StatementResponse::fromEntity(...))`.
  - Un `Throwable` inesperado lo captura el handler global de `public/index.php` (500 genérico); no se agrega try/catch propio.
  - Ruta: `$router->get('/api/v3/payment-clients/{id}/statement', [PaymentStatementController::class, 'show'])->withMiddleware('auth');` junto a las rutas de Payment Clients. No colisiona con `/payment-clients/{id}` (el Router usa `^…$` con `[^/]+`).
  - `bootstrap.php`: `StatementRepository` (`Connection::getInstance()`) y `PaymentStatementService` (`PaymentClientRepository`, `StatementRepository`). Registrar después de `PaymentClientRepository`.

### Fase 5: Pruebas
Estimación: 3 horas

#### 5.1 Unitarias (PHPUnit, sin BD)
- **`StatementQueryTest`**: sin params; solo `from`; solo `to`; `''` = no enviado; formato inválido (`01/03/2026`, `2026-3-1`); fecha inexistente (`2026-02-31`); array (`from[]=x`); `from > to` ⇒ error en `to`; `from == to` válido; límites (`fromBoundary`, `toExclusiveBoundary` incluyendo cruce de mes/año: `2026-12-31` ⇒ `2027-01-01 00:00:00`); ambos inválidos ⇒ ambos errores y sin error de rango.
- **`PaymentStatementServiceTest`** (mocks de `PaymentClientRepository` y `StatementRepository`): cliente inexistente ⇒ `null` y **no** consulta movimientos; cliente inactivo ⇒ estado completo; sin movimientos ⇒ todo en 0; sin `from` ⇒ no se llaman las sumas previas; con `from` ⇒ `pendingAmount` y `balance`; saldo a favor (negativo); redondeo (`0.1 + 0.2` ⇒ `0.3`); solo rango sin movimientos pero con saldo previo ⇒ `balance == pendingAmount`; se pasa `notaryId` y `clientId` a **cada** consulta del repository.
- **`StatementResponseTest`**: nombres de campo exactos (contrato v1), `date: null` en cobro sin fecha, `fromDate`/`toDate` `null`.

#### 5.2 Verificación manual / QA (no hay infraestructura de integración con BD)
El PO pide pruebas de integración para los 21 escenarios; el proyecto no tiene esa infraestructura (igual que en spec 18). Se cubren con pruebas unitarias + validación de QA contra el contenedor Docker con datos sembrados. Escenarios críticos para QA: 3 (último día con hora), 7/8 (cancelados), 11 (otra notaría), 14/15 (cobro sin fecha), 19 (fecha inexistente).

### Fase 6: Documentación y comparación con v1
Estimación: 2.5 horas

#### 6.1 OpenAPI y README
- **Archivo(s):** `docs/openapi.yaml`, `README.md`
- **Tipo:** Modificar
- **Descripción:** endpoint, parámetros `from`/`to` (`format: date`), respuestas 200/400/401/404, ejemplo de 400 con `errors`. README: fila en la sección *Payment Clients* + entrada en *Changelog*.

#### 6.2 Comparación contra v1 (QA, con datos reales)
- Muestreo de clientes: v1 vs v3. Toda diferencia debe explicarse por (a) cancelados excluidos, (b) último día del rango, (c) cliente inactivo ahora consultable. Si hay otra causa, es hallazgo, no se corrige aquí.
- Revisar `total ≠ fee + taxes` y reportar (no se corrige).

## Orden de Implementación
1. **1.1** `StatementQuery` + sus pruebas
2. **2.1** Entities
3. **2.2** `StatementRepository`
4. **3.1** Service + pruebas
5. **3.2** `StatementResponse` + pruebas
6. **4.1** Controller, ruta, `bootstrap.php`
7. Prueba manual con `curl` contra Docker (escenarios 1–21)
8. **6.1** OpenAPI y README
9. **6.2** Comparación contra v1 (QA)

## Estimación Total

| Fase | Horas |
|------|-------|
| 1. Validación de params | 1.5 |
| 2. Entities y Repository | 2.5 |
| 3. Service y Response | 2 |
| 4. HTTP | 1 |
| 5. Pruebas | 3 |
| 6. Docs | 1 |
| 6.2 Comparación v1 (QA) | 1.5 |
| **Total** | **~12.5 h** (PO: 11.5 h; +1 h por el repository propio y las entities de lectura) |

## Riesgos y Mitigaciones

| ID | Riesgo | Prob. | Impacto | Mitigación |
|----|--------|-------|---------|------------|
| R1 | La migración de `payments_payments` (spec 17) sigue en `.db/pending`: sin ella la tabla puede estar en MyISAM y sin índice `(id_notary, id_client)`; cada consulta haría *full scan* | Media | Alto (rendimiento) | Confirmar que esté aplicada antes del deploy; EXPLAIN de las 4 consultas en QA. Si falta, es prerrequisito de despliegue, no código de este feature |
| R2 | Diferencias de saldo vs v1 por cancelados excluidos | Alta (esperada) | Medio | Comparación 6.2; cada diferencia se explica por una decisión de negocio |
| R3 | `float` con montos de más de 2 decimales en datos legacy: `ROUND`/`SUM` en BD vs `round()` en PHP pueden diferir 1 centavo | Baja | Bajo | Centavos enteros en PHP; el saldo anterior se suma en BD y se redondea una sola vez. QA revisa en el muestreo |
| R4 | Cliente con miles de movimientos y sin paginación (decisión del PO) | Baja | Medio | Medir con EXPLAIN y con un cliente grande en QA; si p95 > 500 ms con 500 movimientos, volver al PO (paginación o límite) |
| R5 | Lecturas no atómicas: un cobro nuevo entre dos consultas puede dejar totales ligeramente inconsistentes | Muy baja | Bajo | Aceptado (ADR-006) |
| R6 | Datos legacy con hora en `date`/`payment_date` (v3 escribe `00:00:00`; v1 pudo guardar hora) | Media | Medio | Los límites de rango ya cubren hora; escenarios 3 y 4 en QA con filas con hora |

## Decisiones de Arquitectura

### ADR-001: Módulo `PaymentStatement` separado
- **Contexto:** El PO deja abierto si va dentro de `PaymentClient` o en módulo nuevo. El estado de cuenta combina datos de 3 tablas (clientes, trámites, cobros) y no es un CRUD del cliente.
- **Decisión:** Módulo nuevo `src/Module/PaymentStatement/`. Consume `PaymentClientRepository` (validación del cliente) y tiene su propio `StatementRepository`.
- **Consecuencias:** Ningún módulo existente cambia de comportamiento. El futuro PDF del estado de cuenta (spec posterior, vía módulo Receipt) consume `PaymentStatementService` sin tocar `PaymentClient`.

### ADR-002: Repository y entities de lectura propios (no reutilizar los de `PaymentTransaction`/`Payment`)
- **Contexto:** El PO asumía listados existentes; no existen. Reusar `PaymentTransaction` obligaría a cargar `details` (N+1: una consulta por trámite) y a construir una entity con campos que el estado de cuenta no usa. Reusar `PaymentRepository` obligaría a agregar métodos de rango en otro módulo.
- **Decisión:** `StatementRepository` con 4 consultas y entities `StatementTransaction`/`StatementPayment` con solo los campos del contrato.
- **Consecuencias:** Una consulta por lista, sin N+1. El SQL de `LEFT JOIN` a procedimientos se duplica con el de `PaymentTransactionRepository` (aceptado: es una línea y el acoplamiento sería peor). Si cambia la estructura de esas tablas, hay que tocar dos módulos.

### ADR-003: Rango con límites datetime semiabiertos, calculados en `StatementQuery`
- **Contexto:** `date`/`payment_date` son datetime; `BETWEEN 'Y-m-d' AND 'Y-m-d'` (v1) excluye el último día con hora.
- **Decisión:** `col >= 'from 00:00:00'` y `col < 'to + 1 día 00:00:00'`; el saldo anterior usa `col < 'from 00:00:00'`, el mismo límite que el inicio del rango, así no hay huecos ni solapes. Sin función sobre la columna (`DATE(col)` anularía el índice).
- **Consecuencias:** El último día entra completo (escenarios 3 y 4). El Repository recibe strings ya calculados y no hace aritmética de fechas.

### ADR-004: Filtrar en SQL, no en PHP
- **Contexto:** Pregunta abierta del PO.
- **Decisión:** SQL, con parámetros preparados. El saldo anterior es un `SUM` en BD, no se traen filas anteriores al rango.
- **Consecuencias:** Menos memoria y transferencia; el costo depende de `(id_notary, id_client)` (R1).

### ADR-005: Totales en centavos enteros
- **Contexto:** Las columnas son `double`; sumar `float` acumula error (`0.1 + 0.2`). El PO exige máximo 2 decimales.
- **Decisión:** Convertir cada monto a centavos (`(int) round($x * 100)`), sumar enteros y dividir al final. `balance` se deriva de enteros, así `balance == pending + charges − payments` exactamente.
- **Consecuencias:** Totales exactos y consistentes con la lista. Sin dependencia de `bcmath`.

### ADR-006: Sin transacción de lectura
- **Contexto:** Son 3–5 consultas separadas.
- **Decisión:** No se abre transacción; cada consulta lee el estado vigente. Es solo lectura y el PO pide "sin caché, cada consulta lee la BD".
- **Consecuencias:** Una escritura concurrente puede quedar a medias entre dos consultas (R5). Aceptado por baja probabilidad y bajo impacto.

### ADR-007: Contrato de errores igual al de `PaymentClientController`
- **Contexto:** El PO ya lo define: `abc` ⇒ 400 `Invalid ID format`; `0`/`-3`/inexistente/otra notaría ⇒ 404 `Client not found`; fechas ⇒ 400 `Validation failed` + `errors`.
- **Decisión:** Se implementa igual, con `parseId` copiado del controller existente (patrón del proyecto: cada controller tiene el suyo). Mensajes de `errors` en inglés (convención de la API).
- **Consecuencias:** Consistencia con el frontend. Un cliente de otra notaría es indistinguible de uno inexistente (no se filtra su existencia).

### ADR-008: `PaymentStatementService` depende de `PaymentClientRepository`, no de `PaymentClientService`
- **Contexto:** `PaymentClientService` es `final`: PHPUnit no puede mockearla. El patrón de `PaymentService` ya inyecta `PaymentClientRepository`.
- **Decisión:** Inyectar `PaymentClientRepository::findByIdAndNotary`.
- **Consecuencias:** Service testeable con mocks. Si `PaymentClientService` agrega reglas a la lectura (hoy es un pass-through), este feature no las hereda.

## Para devolver al PO / QA (no bloquean el desarrollo)

1. **Referencia a listados existentes** (ver Resumen): no existen en v3; el alcance no cambia.
2. **Pruebas de integración** (21 escenarios): no hay infraestructura; se cubren con unitarias + QA manual.
3. **Orden de cobros sin fecha**: el PO no lo define. Se colocan **al final** de `payments` (solo aparecen sin filtros). v1 probablemente los dejaba primero (comportamiento por defecto de MariaDB). ¿Confirmar?
4. **Cobros sin fecha y saldo con filtros**: con `from` o `to` se excluyen también de `pendingAmount`. Con filtros, el `balance` puede diferir del `balance` sin filtros por el monto de esos cobros. Es consecuencia directa del default 1 aprobado; QA debe poder explicarlo.
5. **Zona horaria**: las columnas ya se guardan en hora local (v3 escribe `Y-m-d 00:00:00`). `date` de salida es `substr(col, 0, 10)`, sin conversión, igual que `PaymentTransaction`/`Payment`. Los límites de `to + 1 día` se calculan con `America/Mexico_City`.
