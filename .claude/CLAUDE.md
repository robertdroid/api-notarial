# Notarial API v3

## Project Overview

Sistema de índice notarial para el manejo y tracking de escrituras. Esta es la versión 3 del sistema, separando el API del frontend. El sistema es usado por trabajadores de notarías para rastrear escrituras: ubicación, proceso actual, responsable, y estado de completado.

### Business Context

Una **notaría** es la organización/tenant principal. Cada notaría tiene:
- **Escrituras (Deeds)**: Documento legal principal que se rastrea
- **Actos (Acts)**: Tipos de actos jurídicos (compraventa, donación, etc.)
- **Tipos de Escritura (Deed Types)**: Clasificación de escrituras
- **Fraccionamientos (Subdivisions)**: Ubicaciones/desarrollos
- **Participantes**: Personas involucradas en una escritura
- **Inscripciones**: Registros de inscripción de la escritura
- **Usuarios**: Empleados de la notaría que usan el sistema

El módulo de **Payments** permite gestionar:
- Procedimientos de pago
- Impuestos/conceptos
- Clientes
- Transacciones

## Architecture

### Layered Architecture with Modules

```
src/
├── Core/                    # Shared infrastructure
│   ├── Database/           # Database connection, query builder
│   ├── Http/               # Request, Response, Router
│   ├── Middleware/         # Auth, CORS, etc.
│   └── Exception/          # Custom exceptions
│
├── Module/                  # Feature modules
│   ├── Auth/               # Authentication module
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   └── Entity/
│   │
│   ├── Notary/             # Notary management
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   └── Entity/
│   │
│   ├── Deed/               # Deed management (main module)
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   ├── Entity/
│   │   └── DTO/
│   │
│   ├── Act/                # Act types
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   └── Entity/
│   │
│   ├── DeedType/           # Deed types
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   └── Entity/
│   │
│   ├── Subdivision/        # Subdivisions/locations
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   └── Entity/
│   │
│   ├── User/               # User management
│   │   ├── Controller/
│   │   ├── Service/
│   │   ├── Repository/
│   │   └── Entity/
│   │
│   └── Payment/            # Payment module
│       ├── Controller/
│       ├── Service/
│       ├── Repository/
│       └── Entity/
│
├── Shared/                  # Shared domain concepts
│   ├── ValueObject/        # Email, Money, etc.
│   └── Contract/           # Interfaces
│
└── Config/                  # Configuration
    └── routes.php
```

### Request Flow

```
Request → Router → Middleware(s) → Controller → Service → Repository → Database
                                        ↓
Response ← Controller ← Service ← Repository ← Database
```

### Design Principles

1. **SOLID**
   - Single Responsibility: Each class has one reason to change
   - Open/Closed: Extend behavior without modifying existing code
   - Liskov Substitution: Subtypes must be substitutable
   - Interface Segregation: Small, specific interfaces
   - Dependency Inversion: Depend on abstractions

2. **Clean Architecture**
   - Entities: Core business objects
   - Use Cases: Application-specific business rules (in Services)
   - Interface Adapters: Controllers, Repositories
   - Frameworks: External tools (kept at the edges)

3. **Repository Pattern**
   - Repositories return Entities, not raw data
   - Repositories implement interfaces for testability

4. **DTO Pattern**
   - Request DTOs for input validation
   - Response DTOs for API responses
   - Never expose Entities directly in API

## Tech Stack

- **PHP 8.3/8.5** - Latest PHP features (typed properties, attributes, enums, etc.)
- **MariaDB 11.4** - Production database
- **Apache 2.4** - Web server
- **No Frameworks** - Pure PHP implementation
- **JWT** - Authentication tokens (firebase/php-jwt)
- **PSR Standards** - PSR-4 autoloading
- **Docker** - Local development environment

## Database Schema (Existing)

### Core Tables (Spanish names - legacy)
- `notaria` - Notary organizations
- `usuarios` - Users
- `escrituras` - Deeds
- `participantes_escritura` - Deed participants
- `actos_notaria` - Legal acts per notary
- `tipos_escritura` - Deed types per notary
- `fraccionamientos` - Subdivisions
- `inscripciones` - Inscriptions

### Payment Tables
- `payment_procedures` - Payment procedures
- `payment_taxes` - Tax types
- `payment_procedure_taxes` - Procedure-tax relationship
- `payments_clients` - Payment clients
- `payments_transactions` - Transactions
- `payments_transaction_details` - Transaction details
- `payments_payments` - Actual payments

### Views
- `view_deed_detail_main` - Deed list with details
- `view_payment_procedure_taxes` - Procedures with taxes

## Conventions

### Naming
- **Classes**: PascalCase (`DeedController`, `DeedService`)
- **Methods**: camelCase (`findById`, `createDeed`)
- **Variables**: camelCase (`$deedNumber`, $notaryId`)
- **Constants**: UPPER_SNAKE_CASE (`HTTP_OK`, `STATUS_ACTIVE`)
- **Database columns**: snake_case (legacy, keep as is)
- **API endpoints**: kebab-case (`/api/v3/deed-types`)

### Files
- One class per file
- File name matches class name
- PSR-4 autoloading

### API
- RESTful endpoints
- JSON request/response
- Standard HTTP status codes
- Version prefix: `/api/v3/`
- **All API output in English**: Field names, endpoint paths, and error messages must be in English
- **Request fields**: camelCase (`username`, `password`, `notaryId`)
- **Response fields**: camelCase (`accessToken`, `refreshToken`, `expiresIn`)
- **Error messages**: English (`"Invalid credentials"`, `"Resource not found"`)
- **OpenAPI documentation**: Update `docs/openapi.yaml` when adding/modifying endpoints

### HTTP Status Codes
- `200` - Success
- `201` - Created
- `204` - No Content (successful update/delete)
- `400` - Bad Request (validation errors)
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `500` - Internal Server Error

## Multi-tenancy

The system is multi-tenant at the notary level:
- All queries MUST filter by `notary_id` (from authenticated user's token)
- Never allow cross-notary data access
- The JWT token contains `notary_id` and `user_id`

## Development Setup

### Docker Environment

Configuración para emular el servidor de producción:
- **PHP 8.3** (Apache) - Actualizar a 8.5 cuando esté disponible
- **MariaDB 11.4** - Misma versión que producción
- **phpMyAdmin 5.2.3**

```bash
# Copiar configuración de entorno
cp .env.example .env

# Iniciar contenedores
docker-compose up -d

# Instalar dependencias (primera vez)
docker-compose exec api composer install

# Detener contenedores
docker-compose down

# Ver logs
docker-compose logs -f api
```

### Access Points
- **API**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081
- **Health Check**: http://localhost:8080/api/v3/health

### Database Connection (Docker)
- Host: `db` (dentro de Docker) / `localhost` (desde host)
- Port: `3306`
- Database: `notarial_db`
- User: `notarial_user`

### Production Server Info
- Apache 2.4.69
- MariaDB 11.4.13
- IP: 65.99.225.24

## API Endpoints (Planned)

### Authentication
- `POST /api/v3/auth/login` - Login
- `POST /api/v3/auth/refresh` - Refresh token

### Deeds
- `GET /api/v3/deeds` - List deeds (paginated, filterable)
- `GET /api/v3/deeds/{id}` - Get deed by ID
- `POST /api/v3/deeds` - Create deed
- `PUT /api/v3/deeds/{id}` - Update deed
- `DELETE /api/v3/deeds/{id}` - Delete deed (soft delete)

### Acts
- `GET /api/v3/acts` - List acts
- `GET /api/v3/acts/{id}` - Get act by ID
- `POST /api/v3/acts` - Create act
- `PUT /api/v3/acts/{id}` - Update act

### Deed Types
- `GET /api/v3/deed-types` - List deed types
- `GET /api/v3/deed-types/{id}` - Get deed type by ID
- `POST /api/v3/deed-types` - Create deed type
- `PUT /api/v3/deed-types/{id}` - Update deed type

### Subdivisions
- `GET /api/v3/subdivisions` - List subdivisions
- `GET /api/v3/subdivisions/{id}` - Get subdivision by ID
- `POST /api/v3/subdivisions` - Create subdivision
- `PUT /api/v3/subdivisions/{id}` - Update subdivision

### Users
- `GET /api/v3/users` - List users
- `GET /api/v3/users/{id}` - Get user by ID
- `POST /api/v3/users` - Create user
- `PUT /api/v3/users/{id}` - Update user

### Payments
- Procedures, Taxes, Clients, Transactions endpoints

## Progress Tracking

### Phase 1: Core Infrastructure
- [ ] Project structure setup
- [ ] PSR-4 autoloader
- [ ] Database connection class
- [ ] Router implementation
- [ ] Request/Response classes
- [ ] Exception handling
- [ ] Middleware system
- [ ] JWT Authentication middleware

### Phase 2: Base Modules
- [ ] Auth module (login, token refresh)
- [ ] Notary module
- [ ] User module

### Phase 3: Main Modules
- [ ] Deed module (main feature)
- [ ] Act module
- [ ] DeedType module
- [ ] Subdivision module

### Phase 4: Payment Module
- [ ] Procedures
- [ ] Taxes
- [ ] Clients
- [ ] Transactions

### Phase 5: Production Ready
- [ ] Input validation
- [ ] Error handling
- [ ] Logging
- [ ] Rate limiting
- [ ] API documentation

## Notes

- The existing database has Spanish column names - we map them to English in entities
- Status values: 1 = active, 2 = inactive (convention from legacy system)
- Dates should be handled in `America/Mexico_City` timezone
- All monetary values use `double` (from legacy, consider decimal for new)
