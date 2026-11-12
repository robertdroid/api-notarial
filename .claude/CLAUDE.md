# Notarial API v3

Sistema de índice notarial para tracking de escrituras. Multi-tenant por notaría.

## Dominio

- **Notaría**: Tenant principal
- **Escritura (Deed)**: Documento legal rastreado
- **Acto**: Tipo de acto jurídico (compraventa, donación)
- **Participantes**: Personas en una escritura
- **Módulo Payments**: Procedimientos, impuestos, clientes, transacciones

## Tech Stack

- PHP 8.3 puro (sin frameworks), MariaDB 11.4, Apache, JWT, Docker

## Arquitectura

```
src/Module/{Modulo}/Controller → Service → Repository → Entity
```

- **SOLID** y **Clean Architecture**
- Repositories retornan Entities
- DTOs para Request/Response (nunca exponer Entities)

## Convenciones

- Clases: `PascalCase`, Métodos/Variables: `camelCase`
- Endpoints: `/api/v3/kebab-case`
- API output en inglés, campos `camelCase`
- Multi-tenant: SIEMPRE filtrar por `notary_id`

## HTTP Codes

`200` OK, `201` Created, `204` No Content, `400` Validation, `401` Unauth, `403` Forbidden, `404` Not Found, `409` Conflict, `422` Unprocessable, `500` Error

## Database (Legacy Spanish)

- `escrituras`, `notaria`, `usuarios`, `actos_notaria`, `tipos_escritura`, `fraccionamientos`, `participantes_escritura`, `inscripciones`
- Status: 1=active, 2=inactive
- Timezone: `America/Mexico_City`

## Dev Setup

```bash
docker-compose up -d        # Start
docker-compose exec api composer install  # First time
```
- API: http://localhost:8080
- phpMyAdmin: http://localhost:8081

## Specs & Templates

- **Index**: `specs/_index.md` - lista de features y su estado
- **Templates**: `specs/_templates/` - formatos de documentos
- **Feature docs**: `specs/XX_name/{po,arch,qa}/`
