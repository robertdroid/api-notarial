# Criterios de Aceptación Técnicos: Estado de Cuenta de Cliente

## Controller Layer
- [ ] `GET /api/v3/payment-clients/{id}/statement` registrado en `routes.php` con middleware `auth`
- [ ] Ruta **no** colisiona con `GET /api/v3/payment-clients/{id}` (ambas responden lo suyo)
- [ ] Orden de validación: `{id}` → `from`/`to` → cliente (404)
- [ ] `{id}` no numérico ⇒ **400** `{ "error": "Invalid ID format" }`
- [ ] `{id}` `0` o negativo ⇒ **404** `{ "error": "Client not found" }` (igual que `PaymentClientController`)
- [ ] `from`/`to` inválidos ⇒ **400** `{ "error": "Validation failed", "errors": { "from": "..." } }`
- [ ] `from > to` ⇒ **400** con `errors.to`
- [ ] Cliente inexistente o de otra notaría ⇒ **404** `{ "error": "Client not found" }` (mismo body en ambos casos)
- [ ] Éxito ⇒ **200** con `Response::json(...)`; sin `Cache-Control` especial, sin escritura
- [ ] `from[]=x` o `to[]=x` (array) ⇒ **400**, nunca 500 ni `TypeError`
- [ ] Parámetro vacío (`from=`) tratado como no enviado; parámetros desconocidos ignorados
- [ ] El controller no contiene lógica de cálculo ni SQL
- [ ] Sin `try/catch` propio: errores inesperados los resuelve el handler global (500)

## Service Layer
- [ ] `PaymentStatementService::build()` devuelve `null` si el cliente no existe en la notaría y **no** consulta movimientos
- [ ] **No** filtra por `statusId` del cliente: un cliente inactivo devuelve el estado de cuenta completo
- [ ] `clientName` es el nombre **actual** del cliente (de `payments_clients`), no el de los movimientos
- [ ] Cálculo en centavos enteros; sin `float` acumulado
- [ ] `totalCharges` = Σ `total` de los trámites activos del rango; `totalPayments` = Σ `amount` de los cobros del rango
- [ ] `pendingAmount` = Σ cargos activos `< from` − Σ cobros `< from`; `0` si no hay `from` (y no se ejecutan esas 2 consultas)
- [ ] `balance` = `pendingAmount + totalCharges − totalPayments`; puede ser negativo (saldo a favor)
- [ ] Montos redondeados a máximo 2 decimales
- [ ] No recalcula `total` ni `taxes`
- [ ] Cliente sin movimientos ⇒ listas vacías y todos los totales en `0`
- [ ] `fromDate`/`toDate` de la respuesta son el valor recibido o `null`

## Repository Layer
- [ ] **Todas** las consultas con `id_notary = :notaryId` y `id_client = :clientId` (incluidas las 2 sumas de saldo anterior)
- [ ] Trámites: `id_status = 1` en la lista **y** en la suma anterior; cancelados excluidos de todo
- [ ] `LEFT JOIN payment_procedures` con `id_notary` en el `ON`; procedimiento eliminado ⇒ `procedureName = ''` sin error
- [ ] Rango con límites datetime semiabiertos: `col >= :from` (`'Y-m-d 00:00:00'`) y `col < :toExclusive` (día siguiente `00:00:00`); **sin** `BETWEEN` ni `DATE(col)`
- [ ] Saldo anterior usa `col < :from` (mismo límite que el inicio del rango)
- [ ] Un movimiento del `to` a las `23:59:59` entra; uno a las `00:00:00` del día siguiente no
- [ ] Un cobro con `payment_date` nulo aparece **solo** sin `from` ni `to`, con `date: null`, y suma a `totalPayments` solo en ese caso
- [ ] Orden: trámites `date ASC, id_transaction ASC`; cobros `payment_date ASC` (nulos al final), `id_payment ASC`
- [ ] Parámetros con PDO preparado; ninguna fecha ni id concatenados en el SQL
- [ ] Solo `SELECT`: ningún `INSERT`/`UPDATE`/`DELETE`, no abre transacciones
- [ ] Devuelve entities (`StatementTransaction`, `StatementPayment`), no arrays; sin N+1 (una consulta por lista)
- [ ] `SUM` con `COALESCE(..., 0)` (cliente sin movimientos previos no devuelve `NULL`)

## Entity/DTO
- [ ] Entities `final` con propiedades `readonly` tipadas; `StatementPayment::$date` es `?string`
- [ ] `StatementQuery`: constructor privado, `fromArray()`, `isValid()`, `getErrors()`
- [ ] Validación de fecha con `PaymentFieldRules::date()` (round-trip): rechaza `2026-02-31`, `2026-3-1`, `01/03/2026`
- [ ] `to < from` ⇒ error en `to`; `from == to` válido; si una fecha es inválida no se compara el rango
- [ ] `StatementResponse` con los nombres **exactos** de v1: `clientId`, `clientName`, `fromDate`, `toDate`, `transactions[transactionId, uuid, date, procedureName, reference, fee, taxes, total]`, `payments[paymentId, uuid, date, comment, amount]`, `pendingAmount`, `totalCharges`, `totalPayments`, `balance`
- [ ] En `payments` el campo es `comment` (singular), aunque la columna es `comments`
- [ ] Fechas de salida en `Y-m-d`; sin entities expuestas en la respuesta

## Tests
- [ ] `StatementQueryTest`: sin params, solo `from`, solo `to`, vacíos, formato inválido, fecha inexistente, array, `from > to`, `from == to`, cruce de mes/año en `toExclusiveBoundary`
- [ ] `PaymentStatementServiceTest`: cliente inexistente, cliente inactivo, sin movimientos, con/sin `from`, saldo a favor, redondeo (`0.1 + 0.2`), solo saldo previo, `notaryId`/`clientId` en cada consulta
- [ ] `StatementResponseTest`: nombres de campo exactos, `date: null`, `fromDate`/`toDate` `null`
- [ ] `composer test` pasa completo (`failOnWarning` y `failOnRisky` activos)
- [ ] Verificación manual (QA) de los 21 escenarios contra Docker; no hay infraestructura de integración con BD

## Código
- [ ] PSR-12, `declare(strict_types=1);` en todos los archivos
- [ ] Typed properties, `final` en Service/Entity/DTO/Controller
- [ ] SOLID: Controller (HTTP) → Service (cálculo) → Repository (SQL) → Entity
- [ ] Respuestas en inglés, campos `camelCase`

## Performance
- [ ] EXPLAIN de las 4 consultas usa `idx_payments_transactions_notary_client` / `idx_payments_payments_notary_client` (no *full scan*)
- [ ] Migración `.db/pending/20261118_100000_payments_payments_innodb_and_indexes.sql` (spec 17) **aplicada** en el entorno donde se mide
- [ ] p95 < 500 ms con un cliente de hasta 500 movimientos en el rango
- [ ] Máximo 5 consultas por petición (1 cliente + 2 listas + 2 sumas) y 3 sin `from`
- [ ] Sin migraciones nuevas

## Seguridad
- [ ] JWT requerido (401 sin token o token inválido, vía middleware `auth`)
- [ ] `notaryId` siempre sale del token (`Request::getNotaryId()`), nunca de query/body
- [ ] Cliente o movimiento de otra notaría no aparece ni altera totales; 404 indistinguible de inexistente
- [ ] Ningún valor de `from`/`to`/`id` llega al SQL sin parámetro preparado
- [ ] Sin caché: cada consulta lee la BD

## Documentación
- [ ] `docs/openapi.yaml`: endpoint, parámetros `from`/`to` (`format: date`), respuestas 200/400/401/404 y ejemplo de 400 con `errors`
- [ ] `README.md`: fila en *Payment Clients* y entrada en *Changelog*
- [ ] `specs/_index.md` actualizado con la fila de la feature 19
