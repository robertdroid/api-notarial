# Notarial API v3

Sistema de índice notarial para el manejo y tracking de escrituras.

## Requirements

- PHP 8.2+
- MariaDB 11.4
- Docker & Docker Compose

## Installation

```bash
# Clone repository
git clone https://github.com/robertdroid/api-notarial.git
cd api-notarial

# Copy configuration
cp .env.example .env
# Edit .env with your credentials

# Start containers
docker-compose up -d

# Install dependencies
docker-compose exec api composer install
```

## Access

- **API:** http://localhost:8080
- **phpMyAdmin:** http://localhost:8081
- **Health Check:** http://localhost:8080/api/v3/health

## API Documentation

OpenAPI specification available at: `docs/openapi.yaml`

To view with Swagger UI, you can use:
- [Swagger Editor](https://editor.swagger.io/) - paste the YAML content
- [Redocly](https://redocly.github.io/redoc/) - for beautiful documentation

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v3/auth/login` | User login |
| POST | `/api/v3/auth/refresh` | Refresh tokens |

#### Login
```bash
curl -X POST http://localhost:8080/api/v3/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"user","password":"pass"}'
```

Response:
```json
{
  "accessToken": "eyJ...",
  "refreshToken": "eyJ...",
  "expiresIn": 7200,
  "tokenType": "Bearer"
}
```

#### Refresh Token
```bash
curl -X POST http://localhost:8080/api/v3/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{"refreshToken":"eyJ..."}'
```

### Health Check

```bash
curl http://localhost:8080/api/v3/health
```

### Acts (Legal Acts)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/acts` | List all acts (filterable) |
| GET | `/api/v3/acts/{id}` | Get act by ID |
| POST | `/api/v3/acts` | Create new act |
| PUT | `/api/v3/acts/{id}` | Update act |

#### List Acts
```bash
curl -X GET "http://localhost:8080/api/v3/acts?status=1&name=compra" \
  -H "Authorization: Bearer <token>"
```

Query parameters:
- `status` (optional): 1 = active, 2 = inactive
- `name` (optional): partial match, case-insensitive

#### Create Act
```bash
curl -X POST http://localhost:8080/api/v3/acts \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"actName":"Compraventa","actDescription":"Acto de compraventa"}'
```

Response: `201 Created` (no body)

#### Update Act
```bash
curl -X PUT http://localhost:8080/api/v3/acts/1 \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"actName":"Compraventa","actDescription":"Descripción","status":1}'
```

Response: `204 No Content`

### Subdivisions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/subdivisions` | List all subdivisions (filterable) |
| GET | `/api/v3/subdivisions/{id}` | Get subdivision by ID |
| POST | `/api/v3/subdivisions` | Create new subdivision |
| PUT | `/api/v3/subdivisions/{id}` | Update subdivision |

Query parameters for list:
- `status` (optional): 1 = active, 2 = inactive
- `name` (optional): partial match, case-insensitive

### Deed Types

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/deed-types` | List all deed types (filterable) |
| GET | `/api/v3/deed-types/{id}` | Get deed type by ID |
| POST | `/api/v3/deed-types` | Create new deed type |
| PUT | `/api/v3/deed-types/{id}` | Update deed type |

Query parameters for list:
- `status` (optional): 1 = active, 2 = inactive
- `name` (optional): partial match, case-insensitive

#### Create Deed Type
```bash
curl -X POST http://localhost:8080/api/v3/deed-types \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Infonavit",
    "description": "Escrituras de Infonavit",
    "maxInscriptions": 2,
    "enableSubdivisions": false
  }'
```

Response fields:
- `deedTypeId`: Unique identifier
- `name`: Deed type name (max 200 chars)
- `description`: Description (max 500 chars)
- `maxInscriptions`: Maximum inscriptions allowed (default: 1)
- `enableSubdivisions`: Whether subdivisions are enabled (boolean)
- `status`: 1 = active, 2 = inactive

### Deeds

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/deeds` | List deeds (paginated, searchable) |

Query parameters for list:
- `page` (optional): positive integer, default 1. Invalid values return 400
- `search` (optional): free text, split into words (max 10). Case- and accent-insensitive; every word must match in the deed number + act name, or all in a single active participant (seller/buyer). Word order is irrelevant

Results are ordered by deed number descending (numeric first), 100 per page.

```json
{
  "data": [
    { "id": 1, "deedNumber": "39734", "book": "750", "deedDate": "2026-10-30",
      "actName": "Compraventa", "deedTypeName": "Actuales", "isFinished": false }
  ],
  "pagination": { "page": 1, "perPage": 100, "total": 13372, "totalPages": 134 }
}
```

## API Conventions

- All field names are in **English** and **camelCase**
- Error messages are in **English**
- Endpoints use **kebab-case** (`/deed-types`)
- All responses are **JSON**

## Tests

```bash
docker-compose exec api ./vendor/bin/phpunit
```

## Changelog

### 2026-11-10 - Feature: Deeds List
- New `Deed` module with `GET /api/v3/deeds`: paginated list (100 per page) with word search over deed number, act and participants
- Multi-tenant filtering by notary; participants with deleted status are not searched
- Requires DB migration `add_search_indexes_to_escrituras` (indexes on `escrituras` and `participantes_escritura`)
- Reusable `Shared\Pagination\PaginatedResult`

### 2026-11-09 - Feature: Deed Types CRUD
- CRUD module for deed types (tipos de escritura)
- Endpoints: list, get, create, update
- Multi-tenant filtering by notary
- Name uniqueness validation per notary (case-insensitive)
- Fields: name, description, maxInscriptions, enableSubdivisions
- Soft delete via status change

### 2026-11-09 - Feature: Subdivisions CRUD
- CRUD module for subdivisions (fraccionamientos)
- Endpoints: list, get, create, update
- Multi-tenant filtering by notary

### 2026-11-09 - Feature: Acts CRUD
- CRUD module for legal acts (actos jurídicos)
- Endpoints: list, get, create, update
- Multi-tenant filtering by notary
- Name uniqueness validation per notary
- Soft delete via status change

### 2026-11-09 - Refactor: API English & OpenAPI
- All API fields and messages now in English
- Added OpenAPI 3.1 documentation

### 2026-11-09 - Feature: Auth Login
- JWT authentication implementation
- Endpoints: `/api/v3/auth/login`, `/api/v3/auth/refresh`
- Automatic MD5 to bcrypt password migration
- Base infrastructure: Config, Database Connection, ServiceLocator

## License

Proprietary - DevRob
