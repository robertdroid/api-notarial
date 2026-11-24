# Estado de Cuenta de Cliente (Payments)

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** consultar el estado de cuenta de un cliente, con sus cargos (trámites), abonos (cobros) y saldo, opcionalmente acotado por rango de fechas
**Para** saber cuánto debe el cliente, revisar sus movimientos y decidir qué cobrar

## Contexto

Hoy esta consulta existe solo en la API legacy v1:

`GET /api/v1/payment/client-statement?clientId={id}[&from=YYYY-MM-DD][&to=YYYY-MM-DD]`

Lo usa la vista de movimientos del cliente (`notarial/js/payment_movements_list.js`). Funciona bien, pero vive fuera de v3 y tiene problemas que esta migración debe corregir:

- **Cancelados suman al saldo.** v1 suma trámites con estatus 2 (cancelados) a cargos y balance.
- **Fechas acotadas por `BETWEEN` de fecha.** Como `date` es datetime, un trámite del último día de `to` a cualquier hora posterior a las 00:00 queda fuera del rango.
- **Fechas inválidas se corrigen en silencio.** `2026-02-31` pasa la validación por expresión regular y PHP lo convierte a marzo. Además, si el `DateTime` falla, v1 ignora el filtro en vez de responder error.
- **Errores sin detalle por campo.** v1 responde un mensaje genérico. v3 responde `400 Validation failed` con `errors` por campo, que el frontend puede mostrar junto a cada input.
- **Lógica en el controlador legacy.** La orquestación vive en `payments_client_statement.php` y en un caso de uso que mezcla acceso a datos y cálculo. En v3 debe quedar en capas: Controller → Service → Repository → Entity.

Esta spec replica el contrato de v1 (mismos campos de respuesta) y lo refactoriza para v3 según las decisiones de negocio que se listan abajo.

## Decisiones de negocio

| Tema | Decisión | Cambio vs v1 |
|------|----------|--------------|
| Trámites cancelados (estatus 2) | Se excluyen de la lista, de cargos, de saldo anterior y del balance. Solo cuentan trámites activos (estatus 1). | **Sí**: en v1 sí suman |
| Cliente inactivo | Se permite la consulta. El 404 aplica solo si el cliente no existe o es de otra notaría. | **Sí**: en v1 responde 404 |
| Alcance | Solo JSON. El PDF (`report/statement`) queda en una spec posterior, reutilizando el módulo Receipt (spec 18). | No aplica |
| Ruta | `GET /api/v3/payment-clients/{id}/statement?from=&to=` | Cambia la ruta, no el contrato de campos |

## Alcance

### Incluido
- `GET /api/v3/payment-clients/{id}/statement` con filtros opcionales `from` y `to`
- Respuesta JSON con los mismos campos que v1: `clientId`, `clientName`, `fromDate`, `toDate`, `transactions[]`, `payments[]`, `pendingAmount`, `totalCharges`, `totalPayments`, `balance`
- Filtro de rango por día completo (ver criterios)
- Exclusión de trámites cancelados en todos los cálculos
- Validación estricta de `clientId`, `from` y `to` con respuesta 400
- Aislamiento multi-tenant: todas las consultas filtran por `id_notary`
- Documentación en `docs/openapi.yaml` y README

### Excluido
- Generación del PDF del estado de cuenta (`report/statement`). Spec posterior.
- Migrar el frontend de movimientos a la nueva URL. Es un cambio aparte que depende de esta spec.
- Eliminar o deprecar `GET /api/v1/payment/client-statement`. Se decide cuando se retire v1.
- Paginación de `transactions` y `payments`. Se evalúa en los puntos por confirmar.
- Cambios de esquema o migraciones de base de datos.
- Escritura de datos. Es una operación de solo lectura.

## Usuarios Afectados

- **Empleados de notaría**: consultan el saldo y los movimientos de sus clientes desde la vista de movimientos.
- **Frontend de movimientos**: cambiará la URL cuando se migre (fuera de esta spec).

## Valor de Negocio

- **Saldo correcto**: los trámites cancelados ya no inflan lo que el cliente debe.
- **Rangos confiables**: un rango con `to` incluye todo el último día, sin perder movimientos de la tarde.
- **Errores claros**: fechas inválidas o rangos invertidos responden 400 en vez de devolver datos calculados con un filtro distinto al pedido.
- **Consistencia con v3**: mismo formato de error, mismas convenciones de ruta y aislamiento por notaría.

## Puntos por confirmar

Estos puntos no están definidos y afectan el resultado. Propongo un default para cada uno; el equipo debe aprobarlo o cambiarlo antes de Arch.

1. **Cobros sin fecha.** `payments_payments.payment_date` es nullable. *Propuesta*: un cobro sin fecha aparece solo cuando la consulta no tiene filtros de fecha. Si hay `from` o `to`, se excluye, igual que hace v1 con `BETWEEN`. Suma al total de abonos solo en el caso sin filtros. ¿Correcto?
2. **Volumen sin paginación.** v1 devuelve todos los movimientos del rango sin paginar. *Propuesta*: mantener sin paginación en esta versión y que Arch revise el costo con un cliente de muchos movimientos. ¿Hay un límite de movimientos por cliente que debamos respetar?
3. **Permisos.** *Propuesta*: cualquier usuario autenticado de la notaría puede consultar, igual que los GET de pagos. ¿Solo administradores?
4. **Orden de movimientos.** *Propuesta*: `transactions` y `payments` en orden cronológico ascendente, igual que v1.

## Preguntas abiertas para Arch

- Índices necesarios sobre `payments_transactions(id_client, date)` y `payments_payments(id_client, payment_date)`, verificados con EXPLAIN.
- Si el rango se filtra en SQL (recomendado) o en PHP.
- Ubicación del módulo: `src/Module/PaymentStatement/` o dentro de `PaymentClient`.
