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
| GET | `/api/v3/deeds/{id}` | Deed detail with act, type, subdivision, active participants, registrations and last editor |
| POST | `/api/v3/deeds` | Create a deed (optionally with participants and registrations, atomically) |
| PUT | `/api/v3/deeds/{id}` | Replace the editable fields of a deed (never touches `isFinished`, participants or registrations) |
| POST | `/api/v3/deeds/{id}/finish` | Mark a deed as finished (irreversible; 409 if already finished) |
| GET | `/api/v3/deeds/{id}/participants` | List active participants |
| POST | `/api/v3/deeds/{id}/participants` | Add a participant |
| PUT | `/api/v3/deeds/{id}/participants/{participantId}` | Update a participant |
| DELETE | `/api/v3/deeds/{id}/participants/{participantId}` | Deactivate a participant (soft delete) |
| GET | `/api/v3/deeds/{id}/registrations` | List registrations |
| POST | `/api/v3/deeds/{id}/registrations` | Add a registration |
| PUT | `/api/v3/deeds/{id}/registrations/{registrationId}` | Update a registration |
| DELETE | `/api/v3/deeds/{id}/registrations/{registrationId}` | Delete a registration (physical delete) |

Notes for the deed write endpoints:
- `deedNumber` is **not** unique (a deed with several properties is stored as several rows with the same number)
- Deed type rules apply: `422 registration_limit_exceeded` (`max_inscripciones`) and `422 subdivision_not_allowed` (`activar_fraccionamientos`). On `PUT` they are only evaluated when the type or subdivision changes
- Every write (including participants and registrations) updates the deed's `modifiedBy` / `modifiedAt`, inside the same transaction
- Domain errors return `{ "error", "code" }` (plus `details` when applicable); decide by `code`, not by the message. Another notary's deed always answers `404 deed_not_found`
- Requires DB migrations `deeds_innodb_and_indexes` (InnoDB for deed tables + `idx_inscripciones_escritura`) and `normalize_participant_status`

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

### Payment Taxes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/payment-taxes` | List payment taxes (filterable) |
| GET | `/api/v3/payment-taxes/{id}` | Get payment tax by ID |
| POST | `/api/v3/payment-taxes` | Create payment tax (201, no body) |
| PUT | `/api/v3/payment-taxes/{id}` | Update payment tax; deactivate with `status: 2` (204) |

Query parameters for list:
- `status` (optional): 1 = active, 2 = inactive
- `name` (optional): partial match, case- and accent-insensitive

There is no DELETE endpoint; deactivate with `status: 2`.

### Payment Procedures

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/payment-procedures` | List payment procedures (filterable) |
| GET | `/api/v3/payment-procedures/{id}` | Get payment procedure by ID |
| POST | `/api/v3/payment-procedures` | Create payment procedure (201, no body) |
| PUT | `/api/v3/payment-procedures/{id}` | Update payment procedure; deactivate with `status: 2` (204) |

Query parameters for list:
- `status` (optional): 1 = active, 2 = inactive
- `name` (optional): partial match, case- and accent-insensitive

There is no DELETE endpoint; deactivate with `status: 2`.

### Payment Clients

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/payment-clients` | List payment clients (filterable) |
| GET | `/api/v3/payment-clients/{id}` | Get payment client by ID |
| POST | `/api/v3/payment-clients` | Create payment client (201, no body) |
| PUT | `/api/v3/payment-clients/{id}` | Partial update; deactivate with `status: 2` (204) |

Query parameters for list:
- `status` (optional): 1 = active, 2 = inactive (any other value returns 400)
- `name` (optional): partial match, case- and accent-insensitive

Client names are not unique. There is no DELETE endpoint; deactivate with `status: 2`.

### Payment Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v3/payment-transactions` | Create a transaction with details (201); an existing `uuid` returns the existing one unchanged (200) |
| GET | `/api/v3/payment-transactions/{id}` | Get transaction with details by ID |
| GET | `/api/v3/payment-transactions?uuid=X` | Get transaction with details by client-generated UUID |
| PUT | `/api/v3/payment-transactions/{id}` | Full replace of header and details (200); `clientId` and `uuid` cannot change |

Totals are computed by the server. If the procedure has configured taxes, `taxes` is the sum of `quantity × amount` of the details (inactive, foreign and duplicate taxes are ignored); otherwise `taxes` comes from the request and details are ignored.

### Payment Procedure Taxes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/payment-procedures/{id}/taxes` | List active taxes configured for a procedure (flat array) |
| POST | `/api/v3/payment-procedures/{id}/taxes` | Add one tax `{taxId, defaultValue?}` or several `{taxes: [...]}` (201, no body) |
| PUT | `/api/v3/payment-procedures/{id}/taxes/{taxId}` | Change the default value (204) |
| DELETE | `/api/v3/payment-procedures/{id}/taxes/{taxId}` | Disable the tax in the procedure, soft delete (204) |

- `defaultValue` is optional; omitted, `null`, zero or negative values are stored as `0`.
- POSTing a previously disabled tax re-enables it (201). An already active tax returns 409; an inactive tax or procedure returns 422.
- Bulk is all-or-nothing (max 50 items); item errors return 400 with keys like `taxes.N.taxId`.

### Users

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v3/users` | List the notary's users, ordered by last name (`?status=1\|2`; no pagination) |
| GET | `/api/v3/users/{id}` | Get user by ID |
| POST | `/api/v3/users` | Create user (201, returns the user) |
| PUT | `/api/v3/users/{id}` | Partial update; deactivate with `status: 2` (200, returns the user) |

- `access` is an array of module codes: `ESC` (deeds) and `PAG` (payments); at least one is required.
- `username` is **globally unique** (all notaries) and checked case-insensitively; 3–20 characters (letters, digits, `.`, `_`). On `PUT` the format is only validated when the username changes, so legacy usernames stay editable.
- `password` (min 8 characters, max 72 bytes) is stored with bcrypt and never returned. It cannot be changed through `PUT` (400).
- A user cannot deactivate their own account (403). Legacy status `0` is exposed as `status: 2`.
- There is no DELETE endpoint; deactivate with `status: 2`. A deactivated user cannot log in or refresh, but an already issued access token stays valid until it expires.

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

### 2026-11-12 - Feature: Deeds CRUD
- Completes the `Deed` module: detail, create (with participants and registrations in one transaction), update, finish, and participants / registrations as sub-resources
- Fixes the legacy bug where editing a finished deed reset its finished flag: `PUT` never touches `terminada`; only `POST /finish` sets it (irreversible)
- Deed tables moved to InnoDB (`SELECT ... FOR UPDATE` serializes concurrent registrations and double `finish`); every write audits `modifiedBy` / `modifiedAt`
- Compatible with the v1 sentinels (`id_fraccionamiento` `-1`/`0`, participant `id_estatus` `0`, `0000-00-00` dates); `GET /deeds` no longer shows zero dates as `-0001-11-30`
- Requires DB migrations `deeds_innodb_and_indexes` and `normalize_participant_status`. Before production: confirm the v1 does not rely on MyISAM-specific features, back up the 3 tables, apply in a low-usage window
- Endpoints added:
  - `GET /api/v3/deeds/{id}`, `POST /api/v3/deeds`, `PUT /api/v3/deeds/{id}`, `POST /api/v3/deeds/{id}/finish`
  - `GET|POST /api/v3/deeds/{id}/participants`, `PUT|DELETE /api/v3/deeds/{id}/participants/{participantId}`
  - `GET|POST /api/v3/deeds/{id}/registrations`, `PUT|DELETE /api/v3/deeds/{id}/registrations/{registrationId}`

### 2026-11-11 - Feature: Users CRUD
- New `User` module to manage the notary's users (`usuarios_notaria`); multi-tenant, passwords hashed with bcrypt (cost 10) and never exposed
- Globally unique, case-insensitive username; self-deactivation blocked (403); legacy usernames and legacy status `0` handled
- `POST` returns 201 with the user and `PUT` returns 200 with the user
- Requires DB migration `add_unique_username_to_usuarios_notaria` (unique `usuario` + `(id_notaria, id_estatus)` index). Run PRE-CHECK A (duplicate usernames) before applying in production
- Endpoints added:
  - `GET /api/v3/users`, `GET /api/v3/users/{id}`
  - `POST /api/v3/users`, `PUT /api/v3/users/{id}`

### 2026-11-11 - Feature: Payment Transactions CRUD
- New `PaymentTransaction` module and `Core\Database\TransactionManager` for atomic multi-table writes
- Idempotent POST by client-generated `uuid` (offline-first); server-side totals; multi-tenant
- Requires DB migrations `dedupe_payments_transactions_uuid` and `payments_transactions_innodb_and_indexes`
- Endpoints added:
  - `POST /api/v3/payment-transactions`, `GET /api/v3/payment-transactions/{id}`
  - `GET /api/v3/payment-transactions?uuid=X`, `PUT /api/v3/payment-transactions/{id}`

### 2026-11-10 - Feature: Payment Procedure Taxes CRUD
- New `PaymentProcedureTax` module to configure which taxes (and default values) apply to each payment procedure
- Single and bulk (all-or-nothing, max 50) creation; re-enabling of disabled taxes; soft delete only
- Multi-tenant: procedure and tax must belong to the token's notary (otherwise 404)
- `Response::conflict` (409) and `Response::unprocessable` (422) helpers added
- Requires DB migration `add_unique_procedure_tax_to_payment_procedure_taxes` (unique `(id_procedure, id_tax)`)
- Endpoints added:
  - `GET /api/v3/payment-procedures/{id}/taxes`, `POST /api/v3/payment-procedures/{id}/taxes`
  - `PUT /api/v3/payment-procedures/{id}/taxes/{taxId}`, `DELETE /api/v3/payment-procedures/{id}/taxes/{taxId}`

### 2026-11-10 - Feature: Payment Clients CRUD
- New `PaymentClient` module (`payments_clients` catalog) with list, get, create and update
- Multi-tenant filtering by notary; client names are not unique; name filter is case- and accent-insensitive
- Partial PUT (omitted fields keep their value; `""`/`null` clear phone and email); soft delete via `status: 2`; no DELETE endpoint
- Requires DB migration `add_notary_status_index_to_payments_clients`
- Endpoints added:
  - `GET /api/v3/payment-clients`, `GET /api/v3/payment-clients/{id}`
  - `POST /api/v3/payment-clients`, `PUT /api/v3/payment-clients/{id}`

### 2026-11-10 - Feature: Payment Procedures CRUD
- New `PaymentProcedure` module (`payment_procedures` catalog) with list, get, create and update
- Multi-tenant filtering by notary; name uniqueness per notary (case- and accent-insensitive)
- Soft delete via `status: 2`; no DELETE endpoint
- Requires DB migration `add_notary_status_index_to_payment_procedures`
- Endpoints added:
  - `GET /api/v3/payment-procedures`, `GET /api/v3/payment-procedures/{id}`
  - `POST /api/v3/payment-procedures`, `PUT /api/v3/payment-procedures/{id}`

### 2026-11-10 - Feature: Payment Taxes CRUD
- New `PaymentTax` module (`payment_taxes` catalog) with list, get, create and update
- Multi-tenant filtering by notary; name uniqueness per notary (case- and accent-insensitive)
- Soft delete via `status: 2`; no DELETE endpoint
- Requires DB migration `add_notary_status_index_to_payment_taxes`
- Endpoints added:
  - `GET /api/v3/payment-taxes`, `GET /api/v3/payment-taxes/{id}`
  - `POST /api/v3/payment-taxes`, `PUT /api/v3/payment-taxes/{id}`

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
