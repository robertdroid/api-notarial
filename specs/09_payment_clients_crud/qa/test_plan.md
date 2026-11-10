# Plan de Pruebas: Payment Clients CRUD

## Información General
- **Feature:** 09_payment_clients_crud
- **Rama/PR:** feature/09-payment-clients-crud / PR #8
- **QA:** Claude (QA)
- **Fecha:** 2026-11-10

## Alcance de Pruebas

### En Scope
- `GET /api/v3/payment-clients` (filtros `status`, `name`)
- `GET /api/v3/payment-clients/{id}`
- `POST /api/v3/payment-clients`
- `PUT /api/v3/payment-clients/{id}` (incluye soft delete con `status: 2` y PUT parcial)
- Autenticación JWT, multi-tenancy, validaciones y casos edge
- Migración del índice `(id_notary, id_status)` y documentación OpenAPI

### Fuera de Scope
- Endpoint DELETE (no existe; baja vía PUT) — solo se verifica 405
- Paginación y búsqueda por email/teléfono (excluidos por el PO)
- Relación de clientes con transacciones

## Tipos de Pruebas
- [x] Unitarias
- [x] Funcionales (API, curl / PHP contra Docker)
- [x] Integración (verificación directa en BD tras las escrituras)
- [x] Regresión (suite completa)
- [x] Multi-tenancy (notaría 1 vs notaría 3)

## Ambiente de Pruebas
- PHP: 8.3.36 (contenedor `notarial-api`)
- MariaDB: 11.4 (contenedor `notarial-db`)
- Docker: corriendo (api, db, phpmyadmin)
- Autenticación: tokens JWT HS256 generados localmente con el `JWT_SECRET` de desarrollo (notaría 1, notaría 3, uno expirado y uno de tipo refresh)
- Datos QA creados durante las pruebas fueron eliminados al terminar (la tabla vuelve a 21 filas notaría 1 + 1 fila notaría 3)

## Nota sobre codificación
Las pruebas con caracteres no ASCII (acentos, emoji) se ejecutaron con un script PHP dentro del contenedor, porque la consola de Windows (Git Bash) altera los bytes UTF-8 enviados por `curl` y produce falsos negativos.
