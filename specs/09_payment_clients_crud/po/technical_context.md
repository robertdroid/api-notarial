# Contexto Técnico

## Entidades Involucradas

### Tabla principal: `payments_clients`
```sql
CREATE TABLE `payments_clients` (
  `id_client` int(11) NOT NULL AUTO_INCREMENT,
  `id_notary` int(11) NOT NULL,
  `full_name` varchar(250) NOT NULL,
  `phone` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(250) NOT NULL DEFAULT '',
  `id_status` int(11) DEFAULT 1 COMMENT '1. active, 2. inactive',
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_client`)
)
```

**Nota:** El collation es `utf8mb3_bin` (case-sensitive). Las búsquedas por nombre deben usar `LOWER()` para ser case-insensitive.

## Endpoints

| Método | Ruta                          | Descripción                    |
|--------|-------------------------------|--------------------------------|
| GET    | /api/v3/payment-clients       | Listar clientes (con filtros)  |
| GET    | /api/v3/payment-clients/{id}  | Obtener cliente por ID         |
| POST   | /api/v3/payment-clients       | Crear cliente                  |
| PUT    | /api/v3/payment-clients/{id}  | Actualizar cliente             |

### Parámetros de Query (GET lista)
- `status` (int, opcional): Filtrar por estatus (1 o 2)
- `name` (string, opcional): Búsqueda parcial en nombre (LIKE %name%, case-insensitive)

### Request Body (POST)
```json
{
  "fullName": "string (requerido, max 250)",
  "phone": "string (opcional, max 20)",
  "email": "string (opcional, max 250, formato válido si no vacío)",
  "status": "int (opcional, default 1, valores: 1 o 2)"
}
```

### Request Body (PUT)
```json
{
  "fullName": "string (opcional, max 250)",
  "phone": "string (opcional, max 20)",
  "email": "string (opcional, max 250, formato válido si no vacío)",
  "status": "int (opcional, valores: 1 o 2)"
}
```

### Response (single item)
```json
{
  "clientId": 1,
  "fullName": "Juan Pérez",
  "phone": "555-1234",
  "email": "juan@example.com",
  "status": 1
}
```

### Response (lista)
```json
[
  {
    "clientId": 1,
    "fullName": "Juan Pérez",
    "phone": "555-1234",
    "email": "juan@example.com",
    "status": 1
  },
  {
    "clientId": 2,
    "fullName": "María García",
    "phone": "",
    "email": "",
    "status": 1
  }
]
```

### Response (POST - 201 Created)
```json
{
  "clientId": 3,
  "fullName": "Nuevo Cliente",
  "phone": "555-0000",
  "email": "nuevo@example.com",
  "status": 1
}
```

## Mapeo de Campos (DB → API)

| Base de Datos | API Response | Notas                        |
|---------------|--------------|------------------------------|
| id_client     | clientId     | Primary key                  |
| id_notary     | (no exponer) | Se filtra por token          |
| full_name     | fullName     | Nombre completo del cliente  |
| phone         | phone        | Teléfono de contacto         |
| email         | email        | Correo electrónico           |
| id_status     | status       | 1=activo, 2=inactivo         |
| created_at    | (no exponer) | Solo uso interno             |
| modified_at   | (no exponer) | Solo uso interno             |

## Dependencias

- **Auth Module:** Validación de JWT y extracción de `notary_id`
- **Core/Router:** Registro de rutas
- **Core/Database:** Query builder

## Impacto en Sistema Existente

- Agregar rutas en `src/Config/routes.php`
- Crear nuevo módulo `src/Module/PaymentClient/` siguiendo estructura existente:
  - Controller/PaymentClientController.php
  - Service/PaymentClientService.php
  - Repository/PaymentClientRepository.php
  - Entity/PaymentClient.php

## Consideraciones de Datos

- La tabla tiene ~22 registros existentes (AUTO_INCREMENT=23)
- La tabla NO tiene índice en `id_notary` - **se requiere migración**
- Los campos `phone` y `email` permiten vacíos (DEFAULT '')
- La mayoría de registros existentes tienen phone y email vacíos
- **No hay validación de unicidad de nombre** (a diferencia de otros catálogos)

## Migración Requerida

```sql
-- Agregar índice para optimizar consultas multi-tenant
CREATE INDEX idx_payments_clients_notary_status 
ON payments_clients (id_notary, id_status);
```

Archivo: `.db/pending/YYYYMMDD_HHMMSS_add_notary_status_index_to_payments_clients.sql`

## Notas para Implementación

1. **Seguir patrón de PaymentProcedure module** - La estructura es similar, con campos adicionales
2. **Sin validación de unicidad:** A diferencia de otros catálogos, dos clientes pueden tener el mismo nombre
3. **Validación de email:** Solo validar formato cuando `email` no está vacío después de trim
4. **Trim a todos los campos:** Aplicar `trim()` a `fullName`, `phone` y `email`
5. **Multi-tenant:** TODAS las queries deben incluir `WHERE id_notary = ?`
6. **No exponer notaryId:** No incluir en response
7. **Búsqueda case-insensitive:** Usar `LOWER(full_name) LIKE LOWER(?)` por el collation bin
