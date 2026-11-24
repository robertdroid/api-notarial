# Contexto Técnico

## Entidades Involucradas

### Tabla principal: `payment_taxes`
```sql
CREATE TABLE `payment_taxes` (
  `id_tax` int(11) NOT NULL AUTO_INCREMENT,
  `id_notary` int(11) NOT NULL,
  `tax_name` varchar(500) DEFAULT NULL,
  `id_status` int(11) DEFAULT 1 COMMENT '1. active, 2. inactive',
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_tax`)
)
```

### Tabla relacionada (solo lectura en este feature): `payment_procedure_taxes`
- Relaciona impuestos con procedimientos
- No se modifica en este CRUD, pero existe la relación

## Endpoints

| Método | Ruta                       | Descripción                    |
|--------|----------------------------|--------------------------------|
| GET    | /api/v3/payment-taxes      | Listar impuestos (con filtros) |
| GET    | /api/v3/payment-taxes/{id} | Obtener impuesto por ID        |
| POST   | /api/v3/payment-taxes      | Crear impuesto                 |
| PUT    | /api/v3/payment-taxes/{id} | Actualizar impuesto            |

### Parámetros de Query (GET lista)
- `status` (int, opcional): Filtrar por estatus (1 o 2)
- `name` (string, opcional): Búsqueda parcial en nombre (LIKE %name%, case-insensitive)

### Request Body (POST)
```json
{
  "taxName": "string (requerido, max 500)",
  "status": "int (opcional, default 1, valores: 1 o 2)"
}
```

### Request Body (PUT)
```json
{
  "taxName": "string (opcional, max 500)",
  "status": "int (opcional, valores: 1 o 2)"
}
```

### Response (GET)
```json
{
  "taxId": 1,
  "taxName": "ISR",
  "status": 1
}
```

## Mapeo de Campos (DB → API)

| Base de Datos | API Response | Notas                          |
|---------------|--------------|--------------------------------|
| id_tax        | taxId        | Primary key                    |
| id_notary     | (no exponer) | Se filtra por token            |
| tax_name      | taxName      | Nombre del impuesto            |
| id_status     | status       | 1=activo, 2=inactivo           |
| created_at    | (no exponer) | Solo uso interno               |
| modified_at   | (no exponer) | Solo uso interno               |

## Dependencias

- **Auth Module:** Validación de JWT y extracción de `notary_id`
- **Core/Router:** Registro de rutas
- **Core/Database:** Query builder

## Impacto en Sistema Existente

- Agregar rutas en `src/Config/routes.php`
- Crear nuevo módulo `src/Module/PaymentTax/` siguiendo estructura existente:
  - Controller/
  - Service/
  - Repository/
  - Entity/

## Consideraciones de Datos

- Existen 9 registros actuales (todos de notaría 1)
- La tabla no tiene índice en `id_notary` - considerar agregar para rendimiento
- El campo `tax_name` permite NULL en BD pero la API lo requiere

## Notas para Implementación

1. **Seguir patrón de DeedType module** - La estructura es prácticamente idéntica
2. **Validación de unicidad:** Query con `LOWER(tax_name)` y `id_notary`, excluyendo `id_tax` en UPDATE
3. **Trim del nombre:** Aplicar `trim()` antes de validar y guardar
4. **Multi-tenant:** TODAS las queries deben incluir `WHERE id_notary = ?`
5. **No exponer notaryId:** A diferencia de la API legacy, no incluir en response
