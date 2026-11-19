# Contexto Técnico

## Entidades Involucradas

Solo lectura. Esta spec no requiere migraciones.

### Cliente: `payments_clients`
Campos usados: `id_client`, `id_notary`, `full_name`, `id_status`.
Ya existe en v3: `PaymentClientRepository::findByIdAndNotary(int $id, int $notaryId)`.
**No** se filtra por estatus: un cliente inactivo se puede consultar (decisión de negocio).

### Trámites (cargos): `payments_transactions`
Campos usados: `id_transaction`, `uuid`, `id_notary`, `id_client`, `id_procedure`, `reference`, `date` (datetime), `fee`, `taxes`, `total`, `id_status`.
- Solo cuentan trámites con `id_status = 1`. Los cancelados (2) se excluyen de todo.
- Ya existe en v3 un listado con `LEFT JOIN` a `payment_procedures` (`procedureName`) en `PaymentTransactionRepository`. Se reutiliza la consulta base.

### Cobros (abonos): `payments_payments`
Campos usados: `id_payment`, `uuid`, `id_notary`, `id_client`, `amount`, `payment_date` (datetime, **nullable**), `comments`.
Sin columna de estatus.
Ya existe en v3: `PaymentRepository` con `LEFT JOIN` a `payments_clients` (`clientName`).

### Procedimientos: `payment_procedures`
Solo para `procedureName`, vía el join ya existente.

## Endpoint

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v3/payment-clients/{id}/statement?from=YYYY-MM-DD&to=YYYY-MM-DD` | Estado de cuenta del cliente |

### Conflictos de ruta
`/api/v3/payment-clients/{id}` tiene un segmento después del recurso. `/payment-clients/{id}/statement` tiene dos, y no hay ninguna ruta existente con ese patrón. No hay colisión.

### Respuesta 200
```json
{
  "clientId": 5,
  "clientName": "María García López",
  "fromDate": "2026-03-01",
  "toDate": "2026-03-31",
  "transactions": [
    {
      "transactionId": 25,
      "uuid": "Us2Gvq",
      "date": "2026-03-10",
      "procedureName": "Trámite de Compraventa",
      "reference": "ESC-123",
      "fee": 1000.00,
      "taxes": 160.00,
      "total": 1160.00
    }
  ],
  "payments": [
    {
      "paymentId": 10,
      "uuid": "abc123xyz",
      "date": "2026-03-15",
      "comment": "Anticipo",
      "amount": 500.00
    }
  ],
  "pendingAmount": 0.00,
  "totalCharges": 1160.00,
  "totalPayments": 500.00,
  "balance": 660.00
}
```

Los nombres de campos son los mismos que v1 (`payments_client_statement.php` y `ClientStatementResponse`). Solo cambia la ruta.

### Errores
| Caso | Status | Body |
|------|--------|------|
| `{id}` no numérico | 400 | `{ "error": "Invalid ID format" }` |
| `{id}` ≤ 0, inexistente o de otra notaría | 404 | `{ "error": "Client not found" }` |
| `from` o `to` con formato o fecha inválidos | 400 | `{ "error": "Validation failed", "errors": { "from": "..." } }` |
| `from` > `to` | 400 | `{ "error": "Validation failed", "errors": { "to": "..." } } ` |
| Sin token o token inválido | 401 | Middleware `auth` |

Los errores siguen el formato de los helpers de `Response` (`badRequest`, `notFound`). No se agrega campo `code`.

## Reglas de Cálculo

Notación: **C** = cargos activos (`id_status = 1`), **P** = abonos.

- `pendingAmount` = Σ C(`date` < from) − Σ P(`payment_date` < from). Si no hay `from`, es 0.
- `totalCharges` = Σ `total` de C dentro del rango.
- `totalPayments` = Σ `amount` de P dentro del rango.
- `balance` = `pendingAmount` + `totalCharges` − `totalPayments`.

### Rango de fechas (cambio clave respecto a v1)
Las columnas son datetime. v1 usa `date BETWEEN 'Y-m-d' AND 'Y-m-d'`, que compara contra `00:00:00` y excluye el resto del último día.

En v3, con `from` y `to` como fechas `Y-m-d`:
- Inicio: `col >= :from 00:00:00`
- Fin: `col < :to + 1 día 00:00:00` (exclusivo)

Los movimientos previos al `from` usan `col < :from 00:00:00`, que es el mismo límite que el inicio del rango.

### Cobros sin fecha
`payment_date` nulo no cumple ningún rango, así que se excluye cuando hay `from` o `to`. Sin filtros, se incluye con `date: null` (ver punto 1 de la historia).

## Dependencias

### Módulos existentes (reutilizar)
- **Auth**: JWT con `notaryId` (`Request::getNotaryId()`)
- **PaymentClient**: validación de cliente por `id` y `notaryId`
- **PaymentTransaction**: consulta base de trámites con procedimiento
- **Payment**: consulta base de cobros con cliente

### Módulo nuevo (propuesta, Arch decide)
`src/Module/PaymentStatement/`
```
├── Controller/PaymentStatementController.php   (1 endpoint)
├── Service/PaymentStatementService.php         (cálculo de saldo y totales)
├── DTO/StatementQuery.php                      (valida from/to, from ≤ to)
├── DTO/StatementResponse.php                   (forma de la respuesta)
```
Las consultas por rango pueden vivir en los repositorios existentes de `PaymentTransaction` y `Payment`, con métodos nuevos (`findActiveByClientAndRange`, `findByClientAndRange`), o en el módulo nuevo. Arch elige.

## Impacto en Sistema Existente

### Archivos a modificar
- `src/Config/routes.php`: una ruta nueva
- `docs/openapi.yaml`: documentar el endpoint, parámetros y respuestas
- `README.md`: sección del endpoint, siguiendo el formato de las demás secciones de pagos

### Sin cambios en
- Migraciones de base de datos
- `payment-clients`, `payment-transactions` y `payments` (se reutilizan consultas, sin cambiar su comportamiento)
- API v1 (`payments_client_statement.php`) y su frontend. Se mantienen funcionando hasta la migración del frontend.

## Consideraciones de Datos

- **Montos**: `double` en BD. Redondear a 2 decimales al armar la respuesta.
- **Fechas**: `date` y `payment_date` son datetime. La salida usa `Y-m-d`.
- **Estatus**: `id_status = 1` para cargos activos. No se filtra estatus del cliente.
- **Multi-tenant**: todas las consultas filtran por `id_notary`, incluido el saldo anterior.
- **Índices**: Arch verifica con EXPLAIN si hace falta índice por `(id_client, date)` en `payments_transactions` y `(id_client, payment_date)` en `payments_payments`. Si no existen, va en una migración aparte.
- **Consistencia**: se usa el `total` guardado. No se recalcula `fee + taxes`. Si difiere, se reporta como hallazgo, no se corrige (igual que en spec 18).

## Notas para Implementación

1. **Orden de operaciones**: validar `{id}` (400/404) → validar `from`/`to` (400) → cargar cliente por `id` y `id_notary` (404) → consultar saldo anterior, cargos del rango y abonos del rango → armar respuesta → redondear.
2. **Validación de fechas**: usar `DateTimeImmutable::createFromFormat('!Y-m-d', $value)` y comparar que `format('Y-m-d') === $value`. Así se rechaza `2026-02-31`.
3. **Consultas**: parámetros con PDO preparado, sin concatenar fechas (la versión legacy concatena en el `WHERE`).
4. **Orden**: `ORDER BY date ASC` y `ORDER BY payment_date ASC`, con `id` como desempate.
5. **Cliente sin movimientos**: responder 200 con listas vacías y totales en 0. No es un 404.
6. **Sin caché**: cada consulta lee la base.
7. **Pruebas**:
   - Unitarias: validación de fechas, cálculo de saldo y redondeo.
   - Integración: los 21 escenarios de `acceptance_criteria.md`, con énfasis en el último día del rango (escenario 3), trámites cancelados (7 y 8) y multi-tenant (11).

## Estimación

- **Complejidad**: Media. La lógica es simple, pero hay que replicar cuatro consultas de rango con el límite correcto de datetime y el aislamiento por notaría.
- **Riesgo**: Bajo-medio. Principal riesgo: diferencias de saldo respecto a v1 por la exclusión de cancelados. Se debe comparar un muestreo de clientes entre v1 y v3, explicando cada diferencia con el cambio de negocio.

| Tarea | Estimación |
|-------|------------|
| Validación de query params (`from`, `to`) | 1 hora |
| Consultas por rango en repositorios | 2 horas |
| Servicio de cálculo y respuesta | 2 horas |
| Controller, ruta y errores | 1 hora |
| Pruebas unitarias e integración | 3 horas |
| OpenAPI y README | 1 hora |
| Comparación contra v1 con clientes reales | 1.5 horas |
| **Total** | **~11.5 horas** |
