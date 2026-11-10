# Contexto Técnico

## Entidades Involucradas

### Tabla principal: `payment_procedure_taxes`
```sql
CREATE TABLE `payment_procedure_taxes` (
  `id_procedure` int(11) NOT NULL,
  `id_tax` int(11) NOT NULL,
  `default_value` double NOT NULL DEFAULT 0,
  `id_status` int(11) DEFAULT 1 COMMENT '1. active, 2. inactive',
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM
```

**Notas importantes:**
- No tiene PRIMARY KEY definida (la identidad natural es `id_procedure + id_tax`)
- No tiene `id_notary` (se infiere del procedimiento)
- Es MyISAM (sin transacciones ni FKs)

### Vista existente: `view_payment_procedure_taxes`
```sql
SELECT 
  pp.id_notary,
  pp.id_procedure,
  pp.procedure_name,
  pp.id_status AS procedure_status,
  pt.id_tax,
  pt.tax_name,
  pt.id_status AS tax_status,
  ppt.id_status AS status,
  ppt.default_value
FROM payment_procedures pp
LEFT JOIN payment_procedure_taxes ppt ON pp.id_procedure = ppt.id_procedure
LEFT JOIN payment_taxes pt ON ppt.id_tax = pt.id_tax
```

### Tablas relacionadas (solo lectura)
- `payment_procedures` - Para validar que el procedimiento existe, está activo y pertenece a la notaría
- `payment_taxes` - Para validar que el impuesto existe, está activo y pertenece a la notaría

## Endpoints

| Método | Ruta                                        | Descripción                              |
|--------|---------------------------------------------|------------------------------------------|
| GET    | /api/v3/payment-procedures/{id}/taxes       | Listar impuestos del procedimiento       |
| POST   | /api/v3/payment-procedures/{id}/taxes       | Agregar impuesto(s) al procedimiento     |
| PUT    | /api/v3/payment-procedures/{id}/taxes/{taxId} | Modificar valor default               |
| DELETE | /api/v3/payment-procedures/{id}/taxes/{taxId} | Deshabilitar impuesto (soft delete)   |

### Request/Response Examples

#### GET /api/v3/payment-procedures/5/taxes
```json
{
  "data": [
    {
      "procedureId": 5,
      "taxId": 12,
      "taxName": "ISR",
      "defaultValue": 500.00
    },
    {
      "procedureId": 5,
      "taxId": 15,
      "taxName": "ISAI",
      "defaultValue": 1200.00
    }
  ]
}
```

#### POST /api/v3/payment-procedures/5/taxes (single)
Request:
```json
{
  "taxId": 18,
  "defaultValue": 800.00
}
```
Response 201:
```json
{
  "procedureId": 5,
  "taxId": 18,
  "taxName": "Derechos de Registro",
  "defaultValue": 800.00
}
```

#### POST /api/v3/payment-procedures/5/taxes (bulk)
Request:
```json
{
  "taxes": [
    { "taxId": 12, "defaultValue": 500 },
    { "taxId": 15, "defaultValue": 1200 },
    { "taxId": 18 }
  ]
}
```
Response 201:
```json
{
  "data": [
    { "procedureId": 5, "taxId": 12, "taxName": "ISR", "defaultValue": 500.00 },
    { "procedureId": 5, "taxId": 15, "taxName": "ISAI", "defaultValue": 1200.00 },
    { "procedureId": 5, "taxId": 18, "taxName": "Derechos de Registro", "defaultValue": 0.00 }
  ]
}
```

#### PUT /api/v3/payment-procedures/5/taxes/12
Request:
```json
{
  "defaultValue": 750.00
}
```
Response 200:
```json
{
  "procedureId": 5,
  "taxId": 12,
  "taxName": "ISR",
  "defaultValue": 750.00
}
```

#### DELETE /api/v3/payment-procedures/5/taxes/12
Response 204 No Content

## Dependencias

- **Módulo Payment Procedures** (spec 08): Valida existencia y status del procedimiento
- **Módulo Payment Taxes** (spec 07): Valida existencia y status del impuesto
- **Módulo Auth** (spec 01): Token JWT con `notaryId`

## Impacto en Sistema Existente

### Integración con PaymentProcedureController
Las rutas son sub-recursos de `/payment-procedures/{id}`, por lo que pueden:
1. Agregarse como métodos en `PaymentProcedureController`, o
2. Crear un nuevo `PaymentProcedureTaxController` dedicado

**Recomendación**: Crear controlador separado para mantener SRP.

### No se modifica
- `PaymentTaxController` ni `PaymentTaxService`
- `PaymentProcedureController` ni `PaymentProcedureService`

## Consideraciones de Datos

### Índice recomendado
Crear índice compuesto para búsquedas eficientes:
```sql
ALTER TABLE payment_procedure_taxes 
ADD INDEX idx_procedure_tax_status (id_procedure, id_tax, id_status);
```

### Integridad referencial
Sin FKs por ser MyISAM, validar en código:
1. `id_procedure` existe en `payment_procedures` con la `id_notary` del token
2. `id_tax` existe en `payment_taxes` con la `id_notary` del token
3. Ambos deben tener `id_status = 1` para poder crear/modificar

### Rehabilitación de registros
Cuando se hace POST de un taxId que existe con status=2:
- Hacer UPDATE en lugar de INSERT
- Actualizar `default_value` y `id_status=1`
- El `modified_at` se actualiza automáticamente

## Notas para Implementación

1. **Validación de duplicados en bulk**: Antes de insertar, verificar que no haya taxIds repetidos en el array
2. **Todo o nada en bulk**: Validar todos los impuestos primero, luego insertar todos
3. **defaultValue sanitization**: `max(0, $defaultValue ?? 0)`
4. **Multi-tenant en todas las queries**: Siempre JOIN con las tablas padre para filtrar por notaría
5. **Response incluye taxName**: Hacer JOIN con `payment_taxes` al devolver datos
