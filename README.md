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
