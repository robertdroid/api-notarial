# Payment Transactions CRUD

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** registrar y modificar transacciones de pago (movimientos)
**Para** llevar el control de los cargos asociados a cada cliente y procedimiento

## Contexto

Una **transacción** (o "movimiento") representa un cargo al cliente por un procedimiento específico. Cada transacción tiene:
- Un **cliente** al que se le cobra
- Un **procedimiento** que determina qué tipo de trámite es
- **Honorarios** (fee) del notario
- **Impuestos/conceptos** (taxes) como ISR, ISAI, derechos, etc.
- Una **fecha** del movimiento
- Un **responsable** (quién lo registró)
- Una **referencia** (número de expediente, escritura, etc.)
- Un **UUID** generado por el cliente para soporte offline-first

Los **detalles** de la transacción desglosan los impuestos/conceptos individuales:
- Qué impuesto (`taxId`)
- Cantidad (`quantity`, típicamente 1)
- Monto (`amount`)
- Fecha opcional (para fechas de pago diferentes)
- Comentarios

### Relación con otros módulos
- **Clientes** (spec 09): La transacción pertenece a un cliente
- **Procedimientos** (spec 08): El tipo de trámite
- **Impuestos** (spec 07): Los conceptos que se cobran
- **Procedure-Taxes** (spec 10): Configuración de impuestos por procedimiento (para defaults)

### Flujo de uso
1. Usuario selecciona un cliente desde la lista de clientes
2. Crea nueva transacción eligiendo procedimiento
3. El sistema carga los impuestos configurados para ese procedimiento (con valores default)
4. Usuario ajusta montos según el caso específico
5. Guarda la transacción
6. El sistema genera un UUID (frontend, offline-first) o lo recibe del cliente

## Alcance

### Incluido
- Migración de tablas `payments_transactions` y `payments_transaction_details` a InnoDB
- Limpieza de duplicados existentes antes de crear índices únicos
- Endpoint POST para crear transacción con sus detalles (atómico)
- Endpoint PUT para actualizar transacción y sus detalles (atómico, reemplazo completo)
- Endpoint GET por `id` para obtener transacción con detalles
- Endpoint GET por `uuid` para obtener transacción con detalles
- Validación de pertenencia a notaría (multi-tenant)
- Validación de cliente y procedimiento activos
- Cálculo automático de totales en servidor
- Documentación OpenAPI

### Excluido
- Listado/búsqueda de transacciones (será otro spec, probablemente junto con reportes)
- Eliminación/anulación de transacciones
- Reporte de movimiento (legacy: `report_movement.php`)
- Estado de cuenta del cliente (legacy: `payments_client_statement.php`)
- Endpoint de pagos (`payments_payments`) - es otra entidad

## Usuarios Afectados

- **Empleados de notaría**: Registran y modifican transacciones diariamente
- **Administradores**: Pueden ver y corregir transacciones

## Valor de Negocio

- **Integridad de datos**: Las transacciones PHP garantizan que no queden datos parciales
- **Consistencia**: El servidor calcula los totales, evitando discrepancias
- **Seguridad**: Validaciones de multi-tenant y estados activos
- **Offline-first**: El UUID generado por el cliente permite crear transacciones sin conexión y sincronizar después. POST es idempotente: reintentos por reconexión o doble clic devuelven la transacción existente sin duplicar ni modificar
