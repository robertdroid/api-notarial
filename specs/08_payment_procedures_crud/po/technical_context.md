# Contexto Técnico

## Entidades Involucradas

### Tabla principal: `payment_procedures`
```sql
CREATE TABLE `payment_procedures` (
  `id_procedure` int(11) NOT NULL AUTO_INCREMENT,
  `id_notary` int(11) NOT NULL,
  `procedure_name` varchar(500) NOT NULL,
  `id_status` int(11) DEFAULT 1 COMMENT '1. active, 2. inactive',
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_procedure`)
)
```

### Tabla relacionada (fuera del scope): `payment_procedure_taxes`
- Relaciona procedimientos con impuestos
- No se modifica en este CRUD

## Endpoints

| Método | Ruta                            | Descripción                        |
|--------|---------------------------------|------------------------------------|
| GET    | /api/v3/payment-procedures      | Listar procedimientos (con filtros)|
| GET    | /api/v3/payment-procedures/{id} | Obtener procedimiento por ID       |
| POST   | /api/v3/payment-procedures      | Crear procedimiento                |
| PUT    | /api/v3/payment-procedures/{id} | Actualizar procedimiento           |

### Parámetros de Query (GET lista)
- `status` (int, opcional): Filtrar por estatus (1 o 2)
- `name` (string, opcional): Búsqueda parcial en nombre (LIKE %name%, case-insensitive)

### Request Body (POST)
```json
{
  "procedureName": "string (requerido, max 500)",
  "status": "int (opcional, default 1, valores: 1 o 2)"
}
```

### Request Body (PUT)
```json
{
  "procedureName": "string (opcional, max 500)",
  "status": "int (opcional, valores: 1 o 2)"
}
```

### Response (single item)
```json
{
  "procedureId": 1,
  "procedureName": "Escritura de compraventa",
  "status": 1
}
```

### Response (lista)
```json
[
  {
    "procedureId": 1,
    "procedureName": "Escritura de compraventa",
    "status": 1
  },
  {
    "procedureId": 2,
    "procedureName": "Certificación de documentos",
    "status": 1
  }
]
```

### Response (POST - 201 Created)
```json
{
  "procedureId": 3,
  "procedureName": "Nuevo trámite",
  "status": 1
}
```

## Mapeo de Campos (DB → API)

| Base de Datos   | API Response   | Notas                          |
|-----------------|----------------|--------------------------------|
| id_procedure    | procedureId    | Primary key                    |
| id_notary       | (no exponer)   | Se filtra por token            |
| procedure_name  | procedureName  | Nombre del procedimiento       |
| id_status       | status         | 1=activo, 2=inactivo           |
| created_at      | (no exponer)   | Solo uso interno               |
| modified_at     | (no exponer)   | Solo uso interno               |

## Dependencias

- **Auth Module:** Validación de JWT y extracción de `notary_id`
- **Core/Router:** Registro de rutas
- **Core/Database:** Query builder

## Impacto en Sistema Existente

- Agregar rutas en `src/Config/routes.php`
- Crear nuevo módulo `src/Module/PaymentProcedure/` siguiendo estructura existente:
  - Controller/PaymentProcedureController.php
  - Service/PaymentProcedureService.php
  - Repository/PaymentProcedureRepository.php
  - Entity/PaymentProcedure.php

## Consideraciones de Datos

- La tabla tiene ~20 registros existentes (AUTO_INCREMENT=21)
- La tabla NO tiene índice en `id_notary` - **se requiere migración**
- El campo `procedure_name` es NOT NULL (a diferencia de `tax_name` que era nullable)

## Migración Requerida

```sql
-- Agregar índice para optimizar consultas multi-tenant
CREATE INDEX idx_payment_procedures_notary_status 
ON payment_procedures (id_notary, id_status);
```

Archivo: `.db/pending/YYYYMMDD_HHMMSS_add_notary_status_index_to_payment_procedures.sql`

## Notas para Implementación

1. **Seguir patrón de PaymentTax module** - La estructura es prácticamente idéntica
2. **Validación de unicidad:** Query con `LOWER(procedure_name)` y `id_notary`, excluyendo `id_procedure` en UPDATE
3. **Trim del nombre:** Aplicar `trim()` antes de validar y guardar
4. **Multi-tenant:** TODAS las queries deben incluir `WHERE id_notary = ?`
5. **No exponer notaryId:** No incluir en response
6. **Diferencia con PaymentTax:** `procedure_name` es NOT NULL en BD, simplifica validación
