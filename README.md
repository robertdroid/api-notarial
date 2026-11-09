# Notarial API v3

Sistema de índice notarial para el manejo y tracking de escrituras.

## Requisitos

- PHP 8.2+
- MariaDB 11.4
- Docker & Docker Compose

## Instalación

```bash
# Clonar repositorio
git clone https://github.com/robertdroid/api-notarial.git
cd api-notarial

# Copiar configuración
cp .env.example .env
# Editar .env con tus credenciales

# Iniciar contenedores
docker-compose up -d

# Instalar dependencias
docker-compose exec api composer install
```

## Acceso

- **API:** http://localhost:8080
- **phpMyAdmin:** http://localhost:8081
- **Health Check:** http://localhost:8080/api/v3/health

## API Endpoints

### Autenticación

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/api/v3/auth/login` | Iniciar sesión |
| POST | `/api/v3/auth/refresh` | Renovar tokens |

#### Login
```bash
curl -X POST http://localhost:8080/api/v3/auth/login \
  -H "Content-Type: application/json" \
  -d '{"usuario":"user","contrasenia":"pass"}'
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

## Tests

```bash
docker-compose exec api ./vendor/bin/phpunit
```

## Changelog

### 2026-11-09 - Feature: Auth Login
- Implementación de autenticación JWT
- Endpoints: `/api/v3/auth/login`, `/api/v3/auth/refresh`
- Migración automática de contraseñas MD5 a bcrypt
- Infraestructura base: Config, Database Connection, ServiceLocator

## Licencia

Proprietary - DevRob
